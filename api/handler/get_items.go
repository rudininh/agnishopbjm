package handler

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
)

// ===== Structs =====
type TokenData struct {
	ShopID      int64  `json:"shop_id"`
	AccessToken string `json:"access_token"`
}

// ===== Database Connection =====
func getDBConn(ctx context.Context) (*pgx.Conn, error) {
	conn, err := pgx.Connect(ctx, os.Getenv("DATABASE_URL"))
	if err != nil {
		return nil, fmt.Errorf("gagal konek DB: %v", err)
	}
	return conn, nil
}

// ===== Generate Shopee Signature =====
func generateShopeeSign(partnerID int64, path, accessToken string, shopID int64, timestamp int64, partnerKey string) string {
	baseString := fmt.Sprintf("%d%s%d%s%d", partnerID, path, timestamp, accessToken, shopID)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	return hex.EncodeToString(h.Sum(nil))
}

// ===== Helper functions =====
func parseNumber(v interface{}) (int64, bool) {
	if v == nil {
		return 0, false
	}
	switch t := v.(type) {
	case float64:
		return int64(t), true
	case float32:
		return int64(t), true
	case int:
		return int64(t), true
	case int64:
		return t, true
	case json.Number:
		i, err := t.Int64()
		if err == nil {
			return i, true
		}
		f, err := t.Float64()
		if err == nil {
			return int64(f), true
		}
		return 0, false
	case string:
		s := strings.ReplaceAll(t, ",", "")
		s = strings.TrimSpace(s)
		if s == "" {
			return 0, false
		}
		if i, err := strconv.ParseInt(s, 10, 64); err == nil {
			return i, true
		}
		if f, err := strconv.ParseFloat(s, 64); err == nil {
			return int64(f), true
		}
		return 0, false
	default:
		return 0, false
	}
}

func rupiahFromMicros(m int64) int64 {
	if m == 0 {
		return 0
	}
	abs := m
	if abs < 0 {
		abs = -abs
	}
	if abs > 1000000 {
		return m / 100000
	}
	return m
}

func formatRupiah(v int64) string {
	neg := v < 0
	if neg {
		v = -v
	}
	s := strconv.FormatInt(v, 10)
	var parts []string
	for len(s) > 3 {
		parts = append([]string{s[len(s)-3:]}, parts...)
		s = s[:len(s)-3]
	}
	if s != "" {
		parts = append([]string{s}, parts...)
	}
	out := strings.Join(parts, ".")
	if out == "" {
		out = "0"
	}
	if neg {
		return fmt.Sprintf("-Rp %s", out)
	}
	return fmt.Sprintf("Rp %s", out)
}

func tryGetMap(m map[string]interface{}, keys ...string) (interface{}, bool) {
	var cur interface{} = m
	for _, k := range keys {
		if curMap, ok := cur.(map[string]interface{}); ok {
			cur, ok = curMap[k]
			if !ok {
				return nil, false
			}
		} else {
			return nil, false
		}
	}
	return cur, true
}

// ===== Handler utama =====
func Handler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	ctx := context.Background()

	conn, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
		return
	}
	defer conn.Close(ctx)

	var token TokenData
	err = conn.QueryRow(ctx, "SELECT shop_id, access_token FROM shopee_tokens ORDER BY created_at DESC LIMIT 1").
		Scan(&token.ShopID, &token.AccessToken)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil token dari DB: %v"}`, err), http.StatusInternalServerError)
		return
	}

	partnerIDStr := "2013107"
	partnerKey := "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"

	var partnerID int64
	fmt.Sscanf(partnerIDStr, "%d", &partnerID)

	// === STEP 1: GET ITEM LIST ===
	timestamp := time.Now().Unix()
	path := "/api/v2/product/get_item_list"
	sign := generateShopeeSign(partnerID, path, token.AccessToken, token.ShopID, timestamp, partnerKey)

	url := fmt.Sprintf("https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&offset=0&page_size=100&item_status=NORMAL",
		path, partnerID, token.ShopID, timestamp, token.AccessToken, sign)

	resp, err := http.Get(url)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item list: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	var listRes map[string]interface{}
	if err := json.Unmarshal(body, &listRes); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parsing item list: %v","raw":%q}`, err, string(body)), http.StatusInternalServerError)
		return
	}

	itemListRaw, ok := tryGetMap(listRes, "response", "item")
	if !ok {
		itemListRaw, _ = listRes["response"]
	}
	itemsArray, ok := itemListRaw.([]interface{})
	if !ok || len(itemsArray) == 0 {
		json.NewEncoder(w).Encode(map[string]interface{}{
			"items": []interface{}{},
			"note":  "Tidak ada item ditemukan di toko",
		})
		return
	}

	var itemIDs []int64
	for _, it := range itemsArray {
		if imap, ok := it.(map[string]interface{}); ok {
			if idv, ok := imap["item_id"]; ok {
				if id, ok := parseNumber(idv); ok {
					itemIDs = append(itemIDs, id)
				}
			}
		}
	}

	var idStrings []string
	for _, id := range itemIDs {
		idStrings = append(idStrings, fmt.Sprintf("%d", id))
	}
	itemIDJoined := strings.Join(idStrings, ",")

	// === STEP 2: GET ITEM BASE INFO (menampilkan URL lengkap) ===
	path2 := "/api/v2/product/get_item_base_info"
	timestamp2 := time.Now().Unix()
	sign2 := generateShopeeSign(partnerID, path2, token.AccessToken, token.ShopID, timestamp2, partnerKey)

	url2 := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_id_list=%s&need_tax_info=true&need_complaint_policy=true",
		path2, partnerID, token.ShopID, timestamp2, token.AccessToken, sign2, itemIDJoined,
	)

	resp2, err := http.Get(url2)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item base info: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp2.Body.Close()

	body2, _ := io.ReadAll(resp2.Body)
	var baseInfo map[string]interface{}
	if err := json.Unmarshal(body2, &baseInfo); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parse base info: %v","raw":%q}`, err, string(body2)), http.StatusInternalServerError)
		return
	}

	// Kirim hasil URL ke response JSON agar bisa dilihat
	result := map[string]interface{}{
		"success":  true,
		"url_used": url2,
		"response": baseInfo,
	}

	json.NewEncoder(w).Encode(result)
}

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

// ===== Helper Functions =====
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
	}
	return 0, false
}

func rupiahFromMicros(m int64) int64 {
	if m == 0 {
		return 0
	}
	if m > 1000000 {
		return m / 100000
	}
	return m
}

func formatRupiah(v int64) string {
	s := strconv.FormatInt(v, 10)
	var parts []string
	for len(s) > 3 {
		parts = append([]string{s[len(s)-3:]}, parts...)
		s = s[:len(s)-3]
	}
	if s != "" {
		parts = append([]string{s}, parts...)
	}
	return "Rp " + strings.Join(parts, ".")
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

// ===== Handler Utama =====
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
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil token: %v"}`, err), http.StatusInternalServerError)
		return
	}

	partnerID := int64(2013107)
	partnerKey := "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"

	// === Step 1: Get Item List ===
	path := "/api/v2/product/get_item_list"
	timestamp := time.Now().Unix()
	sign := generateShopeeSign(partnerID, path, token.AccessToken, token.ShopID, timestamp, partnerKey)
	url := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_status=NORMAL&page_size=100",
		path, partnerID, token.ShopID, timestamp, token.AccessToken, sign,
	)

	resp, err := http.Get(url)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item list: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)

	var listRes map[string]interface{}
	json.Unmarshal(body, &listRes)

	itemList, _ := tryGetMap(listRes, "response", "item")
	arr, ok := itemList.([]interface{})
	if !ok {
		http.Error(w, `{"error":"Tidak ada item ditemukan"}`, http.StatusInternalServerError)
		return
	}

	var itemIDs []int64
	for _, v := range arr {
		if im, ok := v.(map[string]interface{}); ok {
			if id, ok := parseNumber(im["item_id"]); ok {
				itemIDs = append(itemIDs, id)
			}
		}
	}

	// === Step 2: Get Base Info ===
	path2 := "/api/v2/product/get_item_base_info"
	timestamp2 := time.Now().Unix()
	sign2 := generateShopeeSign(partnerID, path2, token.AccessToken, token.ShopID, timestamp2, partnerKey)
	itemJoined := strings.Trim(strings.Join(strings.Fields(fmt.Sprint(itemIDs)), ","), "[]")

	url2 := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_id_list=%s",
		path2, partnerID, token.ShopID, timestamp2, token.AccessToken, sign2, itemJoined,
	)
	resp2, err := http.Get(url2)
	if err != nil {
		http.Error(w, `{"error":"Gagal ambil base info"}`, http.StatusInternalServerError)
		return
	}
	body2, _ := io.ReadAll(resp2.Body)
	defer resp2.Body.Close()

	var baseInfo map[string]interface{}
	json.Unmarshal(body2, &baseInfo)

	// === Step 3: Get Model List per Item ===
	modelLookup := map[int64][]map[string]interface{}{}
	for _, id := range itemIDs {
		path3 := "/api/v2/product/get_model_list"
		timestamp3 := time.Now().Unix()
		sign3 := generateShopeeSign(partnerID, path3, token.AccessToken, token.ShopID, timestamp3, partnerKey)
		url3 := fmt.Sprintf("https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_id=%d",
			path3, partnerID, token.ShopID, timestamp3, token.AccessToken, sign3, id)

		fmt.Println("🔍 URL Get Item Model:", url3)

		resp3, err := http.Get(url3)
		if err != nil {
			fmt.Println("❌ Gagal ambil model:", err)
			continue
		}
		body3, _ := io.ReadAll(resp3.Body)
		resp3.Body.Close()

		var modelRes map[string]interface{}
		if err := json.Unmarshal(body3, &modelRes); err != nil {
			fmt.Println("⚠️ Parse error:", err)
			continue
		}

		respField, _ := tryGetMap(modelRes, "response")
		respMap, _ := respField.(map[string]interface{})
		modelsRaw, ok := respMap["model"].([]interface{})
		if !ok {
			continue
		}

		var models []map[string]interface{}
		for _, mv := range modelsRaw {
			if m, ok := mv.(map[string]interface{}); ok {
				name := fmt.Sprint(m["name"])
				price := int64(0)
				if pm, ok := m["price_info"].(map[string]interface{}); ok {
					if cp, ok := pm["current_price"]; ok {
						if n, ok := parseNumber(cp); ok {
							price = rupiahFromMicros(n)
						}
					}
				}
				stock := int64(0)
				if sm, ok := m["stock_info_v2"].(map[string]interface{}); ok {
					if sn, ok := sm["stock_number"]; ok {
						if n, ok := parseNumber(sn); ok {
							stock = n
						}
					}
				}

				fmt.Printf("   ➜ Varian: %v | Harga: %s | Stok: %d\n", name, formatRupiah(price), stock)
				models = append(models, map[string]interface{}{
					"name":  name,
					"price": price,
					"stock": stock,
				})
			}
		}
		modelLookup[id] = models
		time.Sleep(400 * time.Millisecond)
	}

	json.NewEncoder(w).Encode(map[string]interface{}{
		"success": true,
		"models":  modelLookup,
	})
}

package handler

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
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

// ===========================
// Struct
// ===========================
type TikTokTokenData struct {
	ShopCipher  string `json:"shop_cipher"`
	AccessToken string `json:"access_token"`
}

// ===========================
// Database
// ===========================
func getDB(ctx context.Context) (*pgx.Conn, error) {
	return pgx.Connect(ctx, os.Getenv("DATABASE_URL"))
}

// ===========================
// Sign Generator
// ===========================
func generateTikTokSign(appKey, appSecret string, timestamp int64) string {
	base := fmt.Sprintf("%s%d", appKey, timestamp)
	h := hmac.New(sha256.New, []byte(appSecret))
	h.Write([]byte(base))
	return base64.StdEncoding.EncodeToString(h.Sum(nil))
}

// ===========================
// Helper
// ===========================
func parseNum(v interface{}) (int64, bool) {
	switch t := v.(type) {
	case float64:
		return int64(t), true
	case json.Number:
		i, err := t.Int64()
		if err == nil {
			return i, true
		}
	}
	return 0, false
}

func formatRp(v int64) string {
	s := strconv.FormatInt(v, 10)
	var out []string
	for len(s) > 3 {
		out = append([]string{s[len(s)-3:]}, out...)
		s = s[:len(s)-3]
	}
	out = append([]string{s}, out...)
	return "Rp " + strings.Join(out, ".")
}

// ===========================
// HANDLER
// ===========================
func TikTokGetItemsHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	ctx := context.Background()

	// DB
	conn, err := getDB(ctx)
	if err != nil {
		http.Error(w, "DB Error: "+err.Error(), 500)
		return
	}
	defer conn.Close(ctx)

	// Ambil token & shop_cipher
	var token TikTokTokenData
	err = conn.QueryRow(ctx,
		`SELECT shop_cipher, access_token FROM tiktok_tokens ORDER BY created_at DESC LIMIT 1`,
	).Scan(&token.ShopCipher, &token.AccessToken)

	if err != nil {
		http.Error(w, "Token tidak ditemukan: "+err.Error(), 500)
		return
	}

	// TikTok Credential
	appKey := os.Getenv("TTS_APP_KEY")
	appSecret := os.Getenv("TTS_APP_SECRET")

	timestamp := time.Now().Unix()
	sign := generateTikTokSign(appKey, appSecret, timestamp)

	// ===========================
	// GET PRODUCT LIST
	// ===========================
	url := fmt.Sprintf(
		"https://open-api.tiktokglobalshop.com/product/202309/products/search?app_key=%s&timestamp=%d&sign=%s",
		appKey, timestamp, sign,
	)

	payload := map[string]interface{}{
		"page_number": 1,
		"page_size":   100,
		"shop_cipher": token.ShopCipher,
	}

	bodySend, _ := json.Marshal(payload)
	req, _ := http.NewRequest("POST", url, strings.NewReader(string(bodySend)))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-TTS-Access-Token", token.AccessToken)

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		http.Error(w, "Request gagal: "+err.Error(), 500)
		return
	}
	respBody, _ := io.ReadAll(resp.Body)
	resp.Body.Close()

	var listRes map[string]interface{}
	if err := json.Unmarshal(respBody, &listRes); err != nil {
		http.Error(w, "JSON TikTok rusak: "+string(respBody), 500)
		return
	}

	if code, ok := parseNum(listRes["code"]); ok && code != 0 {
		w.WriteHeader(500)
		json.NewEncoder(w).Encode(map[string]interface{}{
			"error": listRes["message"],
			"raw":   listRes,
		})
		return
	}

	data := listRes["data"].(map[string]interface{})
	products := data["products"].([]interface{})

	var finalItems []map[string]interface{}

	// ===========================
	// DETAIL PER PRODUK
	// ===========================
	for _, p := range products {
		pmap := p.(map[string]interface{})
		productID := pmap["id"].(string)

		t2 := time.Now().Unix()
		sign2 := generateTikTokSign(appKey, appSecret, t2)

		urlDet := fmt.Sprintf(
			"https://open-api.tiktokglobalshop.com/product/202309/products/product_id?app_key=%s&timestamp=%d&sign=%s&product_id=%s&shop_cipher=%s",
			appKey, t2, sign2, productID, token.ShopCipher,
		)

		req2, _ := http.NewRequest("GET", urlDet, nil)
		req2.Header.Set("X-TTS-Access-Token", token.AccessToken)

		resp2, err := http.DefaultClient.Do(req2)
		if err != nil {
			continue
		}
		body2, _ := io.ReadAll(resp2.Body)
		resp2.Body.Close()

		var det map[string]interface{}
		json.Unmarshal(body2, &det)

		d := det["data"].(map[string]interface{})

		name := d["title"].(string)

		// harga
		var price int64
		if pi, ok := d["price_info"].(map[string]interface{}); ok {
			price, _ = parseNum(pi["base_price"])
		}

		finalItems = append(finalItems, map[string]interface{}{
			"product_id": productID,
			"name":       name,
			"price":      price,
			"price_str":  formatRp(price),
		})
	}

	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": finalItems,
	})
}

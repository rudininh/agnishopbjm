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

// =================================
// Struct
// =================================

type TikTokTokenData struct {
	ShopID      string `json:"shop_id"`
	AccessToken string `json:"access_token"`
}

// =================================
// DB Connection
// =================================

func getDB(ctx context.Context) (*pgx.Conn, error) {
	return pgx.Connect(ctx, os.Getenv("DATABASE_URL"))
}

// =================================
// TikTok Signature
// =================================

func generateTikTokSign(appKey, appSecret string, timestamp int64) string {
	message := fmt.Sprintf("%s%d", appKey, timestamp)
	h := hmac.New(sha256.New, []byte(appSecret))
	h.Write([]byte(message))
	return base64.StdEncoding.EncodeToString(h.Sum(nil))
}

// =================================
// Helper: Parse Number
// =================================

func parseNum(v interface{}) (int64, bool) {
	switch t := v.(type) {
	case float64:
		return int64(t), true
	case json.Number:
		v2, err := t.Int64()
		if err == nil {
			return v2, true
		}
	case string:
		i, err := strconv.ParseInt(strings.TrimSpace(t), 10, 64)
		return i, err == nil
	}
	return 0, false
}

// =================================
// Format Rupiah
// =================================

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

// =================================
// MAIN HANDLER: GET ITEMS
// =================================

func TikTokGetItemsHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	ctx := context.Background()

	// DB Connect
	conn, err := getDB(ctx)
	if err != nil {
		http.Error(w, "DB Error: "+err.Error(), 500)
		return
	}
	defer conn.Close(ctx)

	// Get latest token
	var token TikTokTokenData
	err = conn.QueryRow(ctx,
		`SELECT shop_id, access_token 
		 FROM tiktok_tokens 
		 ORDER BY created_at DESC 
		 LIMIT 1`,
	).Scan(&token.ShopID, &token.AccessToken)

	if err != nil || token.AccessToken == "" || token.ShopID == "" {
		http.Error(w, "Token TikTok tidak ditemukan: "+err.Error(), 500)
		return
	}

	appKey := os.Getenv("TTS_APP_KEY")
	appSecret := os.Getenv("TTS_APP_SECRET")

	if appKey == "" || appSecret == "" {
		http.Error(w, "APP_KEY atau APP_SECRET kosong", 500)
		return
	}

	// ======================================================
	// STEP 1: GET PRODUCT LIST
	// ======================================================

	timestamp := time.Now().Unix()
	sign := generateTikTokSign(appKey, appSecret, timestamp)

	url := fmt.Sprintf(
		"https://open-api.tiktokglobalshop.com/product/202309/products/search?shop_id=%s&app_key=%s&timestamp=%d&sign=%s",
		token.ShopID, appKey, timestamp, sign,
	)

	payload := map[string]interface{}{
		"page_number": 1,
		"page_size":   100,
	}

	jsonBody, _ := json.Marshal(payload)
	req, _ := http.NewRequest("POST", url, strings.NewReader(string(jsonBody)))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Access-Token", token.AccessToken)

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		http.Error(w, "Gagal request produk TikTok: "+err.Error(), 500)
		return
	}
	defer resp.Body.Close()

	rawBody, _ := io.ReadAll(resp.Body)

	// ========== DEBUGGING WAJIB ==========
	fmt.Println("===== RAW RESPONSE LIST =====")
	fmt.Println(string(rawBody))
	fmt.Println("=============================")

	if resp.StatusCode != 200 {
		http.Error(w, "Status TikTok bukan 200: "+string(rawBody), 500)
		return
	}

	// PARSE JSON
	var listRes map[string]interface{}
	if err := json.Unmarshal(rawBody, &listRes); err != nil {
		http.Error(w, "Gagal parse JSON LIST: "+err.Error()+" | RAW: "+string(rawBody), 500)
		return
	}

	dataBlock, ok := listRes["data"].(map[string]interface{})
	if !ok {
		http.Error(w, "Response TikTok tidak punya data block", 500)
		return
	}

	listArr, ok := dataBlock["products"].([]interface{})
	if !ok {
		http.Error(w, "products tidak ditemukan di TikTok", 500)
		return
	}

	// Jika kosong
	if len(listArr) == 0 {
		json.NewEncoder(w).Encode(map[string]interface{}{
			"items": []interface{}{},
		})
		return
	}

	finalItems := []map[string]interface{}{}

	// ======================================================
	// STEP 2: GET DETAIL PER PRODUK
	// ======================================================

	for _, p := range listArr {
		pMap, ok := p.(map[string]interface{})
		if !ok {
			continue
		}

		productID, ok := pMap["id"].(string)
		if !ok {
			continue
		}

		// DETAIL REQUEST
		timestamp2 := time.Now().Unix()
		sign2 := generateTikTokSign(appKey, appSecret, timestamp2)

		url2 := fmt.Sprintf(
			"https://open-api.tiktokglobalshop.com/product/202309/products/detail?shop_id=%s&app_key=%s&timestamp=%d&sign=%s&product_id=%s",
			token.ShopID, appKey, timestamp2, sign2, productID,
		)

		req2, _ := http.NewRequest("GET", url2, nil)
		req2.Header.Set("Access-Token", token.AccessToken)

		resp2, err := http.DefaultClient.Do(req2)
		if err != nil {
			continue
		}
		body2, _ := io.ReadAll(resp2.Body)
		resp2.Body.Close()

		// Debug detail
		fmt.Println("=== DETAIL RAW ===")
		fmt.Println(string(body2))
		fmt.Println("==================")

		var detailRes map[string]interface{}
		json.Unmarshal(body2, &detailRes)

		dData, ok := detailRes["data"].(map[string]interface{})
		if !ok {
			continue
		}

		// NAME
		name, _ := dData["title"].(string)

		// PRICE
		var basePrice int64
		if pinfo, ok := dData["price_info"].(map[string]interface{}); ok {
			if val, ok := parseNum(pinfo["base_price"]); ok {
				basePrice = val
			}
		}

		// SKUS
		var variants []map[string]interface{}
		if skus, ok := dData["skus"].([]interface{}); ok {
			for _, s := range skus {
				sMap, ok := s.(map[string]interface{})
				if !ok {
					continue
				}

				var vprice int64
				if pi, ok := sMap["price_info"].(map[string]interface{}); ok {
					if bp, ok := parseNum(pi["base_price"]); ok {
						vprice = bp
					}
				}

				variants = append(variants, map[string]interface{}{
					"sku_id":    sMap["id"],
					"name":      sMap["sku_name"],
					"price":     vprice,
					"price_str": formatRp(vprice),
				})
			}
		}

		finalItems = append(finalItems, map[string]interface{}{
			"product_id": productID,
			"name":       name,
			"price":      basePrice,
			"price_str":  formatRp(basePrice),
			"variants":   variants,
		})
	}

	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": finalItems,
	})
}

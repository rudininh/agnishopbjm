package handler

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"

	"github.com/jackc/pgx/v5"
)

// ===== Struktur Data =====

type TokenData struct {
	ShopID      int64  `json:"shop_id"`
	AccessToken string `json:"access_token"`
}

type ShopeeItemListResponse struct {
	Response struct {
		Item []struct {
			ItemID int64 `json:"item_id"`
		} `json:"item"`
	} `json:"response"`
	Error   string `json:"error"`
	Message string `json:"message"`
}

type ShopeeItemInfoResponse struct {
	Response struct {
		ItemList []struct {
			ItemID   int64  `json:"item_id"`
			ItemName string `json:"item_name"`
			Stock    int64  `json:"stock"`
			Price    string `json:"price"`
			SKU      string `json:"item_sku"`
		} `json:"item_list"`
	} `json:"response"`
	Error   string `json:"error"`
	Message string `json:"message"`
}

// ===== Utility Fungsi =====

func getDBConn(ctx context.Context) (*pgx.Conn, error) {
	conn, err := pgx.Connect(ctx, os.Getenv("DATABASE_URL"))
	if err != nil {
		return nil, fmt.Errorf("gagal konek DB: %v", err)
	}
	return conn, nil
}

func createShopeeSign(basePath, partnerID, partnerKey, accessToken string, shopID int64, timestamp int64) string {
	// format base string sesuai dokumentasi resmi Shopee Partner v2
	baseString := fmt.Sprintf("%s%s/api/v2/%s%s%d",
		partnerID,
		basePath,
		"", // basePath sudah termasuk endpoint (contoh: "/product/get_item_list")
		accessToken,
		timestamp,
	)

	// Namun Shopee sebenarnya minta format:
	// baseString := fmt.Sprintf("%s/api/v2%s%s%d", partnerID, basePath, accessToken, timestamp)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(fmt.Sprintf("%s/api/v2%s%s%d", partnerID, basePath, accessToken, timestamp)))
	return hex.EncodeToString(h.Sum(nil))
}

// ====== Handler Utama ======

func Handler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")

	ctx := context.Background()
	conn, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
		return
	}
	defer conn.Close(ctx)

	// === Ambil token dari database ===
	var token TokenData
	err = conn.QueryRow(ctx, "SELECT shop_id, access_token FROM shopee_tokens ORDER BY created_at DESC LIMIT 1").
		Scan(&token.ShopID, &token.AccessToken)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil token dari DB: %v"}`, err), http.StatusInternalServerError)
		return
	}

	partnerID := os.Getenv("SHOPEE_PARTNER_ID")
	partnerKey := os.Getenv("SHOPEE_PARTNER_KEY")

	// === STEP 1: Ambil daftar item ID ===
	timestamp := time.Now().Unix()
	sign := createShopeeSign("/product/get_item_list", partnerID, partnerKey, token.AccessToken, token.ShopID, timestamp)

	url := fmt.Sprintf("https://partner.shopeemobile.com/api/v2/product/get_item_list?partner_id=%s&timestamp=%d&access_token=%s&shop_id=%d&sign=%s&page_size=20",
		partnerID, timestamp, token.AccessToken, token.ShopID, sign)

	resp, err := http.Get(url)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item list: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)

	// Cek apakah respons bukan JSON
	if len(body) == 0 || body[0] != '{' {
		http.Error(w, fmt.Sprintf(`{"error":"Respons Shopee tidak valid","raw":%q}`, string(body)), http.StatusBadGateway)
		return
	}

	var listRes ShopeeItemListResponse
	if err := json.Unmarshal(body, &listRes); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parsing item list: %v","raw":%q}`, err, string(body)), http.StatusInternalServerError)
		return
	}

	if listRes.Error != "" {
		http.Error(w, fmt.Sprintf(`{"error":"Shopee API error: %s","message":"%s"}`, listRes.Error, listRes.Message), http.StatusBadGateway)
		return
	}

	if len(listRes.Response.Item) == 0 {
		json.NewEncoder(w).Encode(map[string]interface{}{
			"items": []interface{}{},
			"note":  "Tidak ada item ditemukan dari Shopee",
		})
		return
	}

	// === STEP 2: Ambil detail item ===
	var itemIDs []int64
	for _, item := range listRes.Response.Item {
		itemIDs = append(itemIDs, item.ItemID)
	}

	itemIDsJSON, _ := json.Marshal(map[string][]int64{"item_id_list": itemIDs})

	timestamp2 := time.Now().Unix()
	sign2 := createShopeeSign("/product/get_item_base_info", partnerID, partnerKey, token.AccessToken, token.ShopID, timestamp2)

	url2 := fmt.Sprintf("https://partner.shopeemobile.com/api/v2/product/get_item_base_info?partner_id=%s&timestamp=%d&access_token=%s&shop_id=%d&sign=%s",
		partnerID, timestamp2, token.AccessToken, token.ShopID, sign2)

	req, _ := http.NewRequest("POST", url2, bytes.NewBuffer(itemIDsJSON))
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{}
	resp2, err := client.Do(req)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item info: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp2.Body.Close()

	body2, _ := io.ReadAll(resp2.Body)
	if len(body2) == 0 || body2[0] != '{' {
		http.Error(w, fmt.Sprintf(`{"error":"Respons Shopee item info tidak valid","raw":%q}`, string(body2)), http.StatusBadGateway)
		return
	}

	var infoRes ShopeeItemInfoResponse
	if err := json.Unmarshal(body2, &infoRes); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parsing item info: %v","raw":%q}`, err, string(body2)), http.StatusInternalServerError)
		return
	}

	// === STEP 3: Kirim hasil ke frontend ===
	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": infoRes.Response.ItemList,
		"count": len(infoRes.Response.ItemList),
	})
}

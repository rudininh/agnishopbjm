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

// ===== Structs =====
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
}

// ===== Utility: koneksi DB =====
func getDBConn(ctx context.Context) (*pgx.Conn, error) {
	conn, err := pgx.Connect(ctx, os.Getenv("DATABASE_URL"))
	if err != nil {
		return nil, fmt.Errorf("gagal konek DB: %v", err)
	}
	return conn, nil
}

// ===== Utility: generate HMAC Shopee =====
func generateSign(path, partnerID, partnerKey string, timestamp int64, accessToken string, shopID int64) string {
	// Format: partner_id + path + timestamp + access_token + shop_id
	base := fmt.Sprintf("%s%s%d%s%d", partnerID, path, timestamp, accessToken, shopID)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(base))
	return hex.EncodeToString(h.Sum(nil))
}

// ===== Handler utama =====
func Handler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")

	ctx := context.Background()
	conn, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, err.Error(), 500)
		return
	}
	defer conn.Close(ctx)

	// Ambil token terakhir dari DB
	var token TokenData
	err = conn.QueryRow(ctx, `SELECT shop_id, access_token FROM shopee_tokens ORDER BY created_at DESC LIMIT 1`).
		Scan(&token.ShopID, &token.AccessToken)
	if err != nil {
		http.Error(w, fmt.Sprintf("Gagal ambil token dari DB: %v", err), 500)
		return
	}

	partnerID := os.Getenv("SHOPEE_PARTNER_ID")
	partnerKey := os.Getenv("SHOPEE_PARTNER_KEY")
	timestamp := time.Now().Unix()

	// === 1️⃣ GET ITEM LIST ===
	path1 := "/api/v2/product/get_item_list"
	sign1 := generateSign(path1, partnerID, partnerKey, timestamp, token.AccessToken, token.ShopID)
	url1 := fmt.Sprintf("https://partner.shopeemobile.com%s?partner_id=%s&timestamp=%d&access_token=%s&shop_id=%d&sign=%s&page_size=20",
		path1, partnerID, timestamp, token.AccessToken, token.ShopID, sign1)

	resp, err := http.Get(url1)
	if err != nil {
		http.Error(w, fmt.Sprintf("Gagal ambil item list: %v", err), 500)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	var listRes ShopeeItemListResponse
	if err := json.Unmarshal(body, &listRes); err != nil {
		http.Error(w, "Gagal parse response item list", 500)
		return
	}

	if len(listRes.Response.Item) == 0 {
		w.Write([]byte(`{"items": []}`))
		return
	}

	// === 2️⃣ GET ITEM BASE INFO ===
	var itemIDs []int64
	for _, item := range listRes.Response.Item {
		itemIDs = append(itemIDs, item.ItemID)
	}
	itemIDsJson, _ := json.Marshal(map[string][]int64{"item_id_list": itemIDs})

	timestamp2 := time.Now().Unix()
	path2 := "/api/v2/product/get_item_base_info"
	sign2 := generateSign(path2, partnerID, partnerKey, timestamp2, token.AccessToken, token.ShopID)
	url2 := fmt.Sprintf("https://partner.shopeemobile.com%s?partner_id=%s&timestamp=%d&access_token=%s&shop_id=%d&sign=%s",
		path2, partnerID, timestamp2, token.AccessToken, token.ShopID, sign2)

	req, _ := http.NewRequest("POST", url2, bytes.NewBuffer(itemIDsJson))
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 15 * time.Second}
	resp2, err := client.Do(req)
	if err != nil {
		http.Error(w, fmt.Sprintf("Gagal ambil item info: %v", err), 500)
		return
	}
	defer resp2.Body.Close()

	body2, _ := io.ReadAll(resp2.Body)
	var infoRes ShopeeItemInfoResponse
	if err := json.Unmarshal(body2, &infoRes); err != nil {
		http.Error(w, "Gagal parse item info", 500)
		return
	}

	json.NewEncoder(w).Encode(map[string]interface{}{
		"count": len(infoRes.Response.ItemList),
		"items": infoRes.Response.ItemList,
	})
}

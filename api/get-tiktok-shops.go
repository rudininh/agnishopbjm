package handler

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

type TikTokShopResponse struct {
	Code int    `json:"code"`
	Msg  string `json:"message"`
	Data struct {
		Shops []struct {
			ShopID     string `json:"shop_id"`
			ShopCipher string `json:"shop_cipher"`
			Region     string `json:"region"`
		} `json:"shops"`
	} `json:"data"`
}

var db *pgxpool.Pool

func init() {
	conn, err := pgxpool.New(context.Background(), os.Getenv("NEON_DB_URL"))
	if err != nil {
		panic("Gagal konek ke database: " + err.Error())
	}
	db = conn
}

// =====================================================
// GET /api/get-tiktok-shops
// =====================================================
func GetTiktokShops(w http.ResponseWriter, r *http.Request) {

	ctx := context.Background()

	// 1️⃣ Ambil access token dari database
	var accessToken string
	err := db.QueryRow(ctx, `
		SELECT access_token 
		FROM tiktok_tokens 
		ORDER BY id DESC LIMIT 1
	`).Scan(&accessToken)

	if err != nil || accessToken == "" {
		http.Error(w, "ERROR: access_token TikTok tidak ditemukan di database", 500)
		return
	}

	// 2️⃣ Payload request TikTok API
	payload := map[string]any{
		"page_size": 20,
		"page_no":   1,
	}

	bodyData, _ := json.Marshal(payload)

	apiURL := "https://open-api.tiktokglobalshop.com/api/shop/get_authorized_shops"

	// 3️⃣ Build HTTP request
	req, err := http.NewRequest("POST", apiURL, bytes.NewBuffer(bodyData))
	if err != nil {
		http.Error(w, "Gagal membuat request: "+err.Error(), 500)
		return
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("x-tts-access-token", accessToken)

	client := &http.Client{Timeout: 20 * time.Second}

	// 4️⃣ Kirim request ke TikTok API
	resp, err := client.Do(req)
	if err != nil {
		http.Error(w, "Gagal call TikTok API: "+err.Error(), 500)
		return
	}
	defer resp.Body.Close()

	// 5️⃣ Baca body response
	respBody, _ := io.ReadAll(resp.Body)

	// Debug jika API error
	if resp.StatusCode != 200 {
		http.Error(w, fmt.Sprintf("TikTok API Error (%d): %s", resp.StatusCode, string(respBody)), 500)
		return
	}

	// 6️⃣ Parse response
	var shopResp TikTokShopResponse
	if err := json.Unmarshal(respBody, &shopResp); err != nil {
		http.Error(w, "Gagal decode response TikTok: "+err.Error(), 500)
		return
	}

	if shopResp.Code != 0 {
		http.Error(w, "TikTok balas error: "+shopResp.Msg, 500)
		return
	}

	// 7️⃣ Kembalikan hasil ke dashboard
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(shopResp)
}

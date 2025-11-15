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

// =====================================================
// INIT DATABASE (fix koneksi pooling untuk Vercel)
// =====================================================
func init() {
	connStr := os.Getenv("DATABASE_URL") // gunakan ini sama seperti get-tiktok-token.go

	if connStr == "" {
		fmt.Println("❌ DATABASE_URL tidak ditemukan")
		return
	}

	conn, err := pgxpool.New(context.Background(), connStr)
	if err != nil {
		fmt.Println("❌ Gagal konek DB:", err)
		return
	}
	db = conn
}

// =====================================================
// GET /api/get-tiktok-shops
// =====================================================
func GetTiktokShops(w http.ResponseWriter, r *http.Request) {
	ctx := context.Background()

	// ================================
	// 1️⃣ AMBIL ACCESS TOKEN DARI DB
	// ================================
	var accessToken string

	err := db.QueryRow(ctx, `
		SELECT access_token 
		FROM tiktok_tokens 
		ORDER BY id DESC 
		LIMIT 1
	`).Scan(&accessToken)

	if err != nil {
		http.Error(w, "⛔ Query DB gagal: "+err.Error(), 500)
		return
	}

	if accessToken == "" {
		http.Error(w, "⛔ access_token TikTok kosong", 500)
		return
	}

	// Debug aman → tampil di logs Vercel
	fmt.Println("DEBUG: Token dari DB =", accessToken[:15], "...")

	// =====================================
	// 2️⃣ Siapkan Payload request TikTok API
	// =====================================
	payload := map[string]any{
		"page_size": 20,
		"page_no":   1,
	}

	bodyData, _ := json.Marshal(payload)

	apiURL := "https://open-api.tiktokglobalshop.com/api/shop/get_authorized_shops"

	// =====================================
	// 3️⃣ Build HTTP request
	// =====================================
	req, err := http.NewRequest("POST", apiURL, bytes.NewBuffer(bodyData))
	if err != nil {
		http.Error(w, "⛔ Gagal membuat request: "+err.Error(), 500)
		return
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("x-tts-access-token", accessToken)

	client := &http.Client{Timeout: 20 * time.Second}

	// =====================================
	// 4️⃣ Kirim request ke TikTok API
	// =====================================
	resp, err := client.Do(req)
	if err != nil {
		http.Error(w, "⛔ Gagal menghubungi TikTok API: "+err.Error(), 500)
		return
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	// Debug response mentah
	fmt.Println("API RAW:", string(respBody))

	// =====================================
	// 5️⃣ Jika status bukan 200, kembalikan error
	// =====================================
	if resp.StatusCode != 200 {
		http.Error(w, fmt.Sprintf("⛔ TikTok API Error (%d): %s", resp.StatusCode, respBody), 500)
		return
	}

	// =====================================
	// 6️⃣ Decode data TikTok
	// =====================================
	var shopResp TikTokShopResponse

	if err := json.Unmarshal(respBody, &shopResp); err != nil {
		http.Error(w, "⛔ Gagal decode JSON TikTok: "+err.Error(), 500)
		return
	}

	if shopResp.Code != 0 {
		http.Error(w, "⛔ TikTok balas error: "+shopResp.Msg, 500)
		return
	}

	// =====================================
	// 7️⃣ Kembalikan ke Frontend (selalu valid JSON)
	// =====================================
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(shopResp)
}

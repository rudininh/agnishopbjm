package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"

	_ "github.com/lib/pq"
)

const (
	TikTokTokenURL = "https://auth.tiktok-shops.com/api/v2/token/get"
	AppKey         = "6i1cagd9f0p83"
	AppSecret      = "3710881f177a1e6b03664cc91d8a3516001a0bc7"
)

type TikTokResponse struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
	Data    struct {
		AccessToken          string   `json:"access_token"`
		AccessTokenExpireIn  int64    `json:"access_token_expire_in"`
		RefreshToken         string   `json:"refresh_token"`
		RefreshTokenExpireIn int64    `json:"refresh_token_expire_in"`
		OpenID               string   `json:"open_id"`
		SellerName           string   `json:"seller_name"`
		SellerBaseRegion     string   `json:"seller_base_region"`
		GrantedScopes        []string `json:"granted_scopes"`
	} `json:"data"`
	RequestID string `json:"request_id"`
}

func TikTokGetTokenHandler(w http.ResponseWriter, r *http.Request) {

	// --- Load DB ---
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		http.Error(w, "DATABASE_URL not set", http.StatusInternalServerError)
		return
	}

	db, err := sql.Open("postgres", dbURL)
	if err != nil {
		http.Error(w, "Database connection failed: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer db.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	// --- Ambil Auth Code ---
	var code string
	err = db.QueryRowContext(ctx, `
		SELECT code FROM tiktok_callbacks ORDER BY id DESC LIMIT 1
	`).Scan(&code)
	if err != nil {
		http.Error(w, "No auth_code found in tiktok_callbacks: "+err.Error(), http.StatusInternalServerError)
		return
	}

	fmt.Println("🔍 Using auth_code:", code)

	// --- Build URL ---
	url := fmt.Sprintf(
		"%s?app_key=%s&app_secret=%s&auth_code=%s&grant_type=authorized_code",
		TikTokTokenURL, AppKey, AppSecret, code,
	)

	// --- HTTP Client dengan Timeout ---
	client := &http.Client{Timeout: 10 * time.Second}

	resp, err := client.Get(url)
	if err != nil {
		http.Error(w, "Failed to request TikTok API: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	// Cek status TikTok
	if resp.StatusCode != 200 {
		bodyErr, _ := io.ReadAll(resp.Body)
		http.Error(w, fmt.Sprintf("TikTok API Error %d: %s", resp.StatusCode, string(bodyErr)), resp.StatusCode)
		return
	}

	body, _ := io.ReadAll(resp.Body)

	// --- Parse JSON TikTok ---
	var data TikTokResponse
	if err := json.Unmarshal(body, &data); err != nil {
		http.Error(w, fmt.Sprintf("JSON parse failed: %s | raw: %s", err.Error(), string(body)), 500)
		return
	}

	// --- Jika Gagal dari TikTok ---
	if data.Code != 0 {
		fmt.Println("❌ TikTok Error:", data.Message)
		w.Header().Set("Content-Type", "application/json")
		w.Write(body)
		return
	}

	// --- Jika Berhasil ---
	fmt.Println("✅ TikTok Token Received")

	// --- Create Table if not exists ---
	_, err = db.ExecContext(ctx, `
		CREATE TABLE IF NOT EXISTS tiktok_tokens (
			id SERIAL PRIMARY KEY,
			open_id TEXT,
			seller_name TEXT,
			seller_region TEXT,
			access_token TEXT,
			refresh_token TEXT,
			expire_at TIMESTAMP,
			created_at TIMESTAMP DEFAULT NOW()
		)
	`)
	if err != nil {
		fmt.Println("⚠️ DB Create Table Error:", err)
	}

	// --- Save Token ---
	_, err = db.ExecContext(ctx, `
		INSERT INTO tiktok_tokens (open_id, seller_name, seller_region, access_token, refresh_token, expire_at)
		VALUES ($1, $2, $3, $4, $5, to_timestamp($6))
	`,
		data.Data.OpenID,
		data.Data.SellerName,
		data.Data.SellerBaseRegion,
		data.Data.AccessToken,
		data.Data.RefreshToken,
		data.Data.AccessTokenExpireIn,
	)

	if err != nil {
		fmt.Println("⚠️ DB Insert Error:", err)
	}

	fmt.Printf("💾 Token saved for seller %s (open_id: %s)\n", data.Data.SellerName, data.Data.OpenID)

	// Balikkan response TikTok aslinya
	w.Header().Set("Content-Type", "application/json")
	w.Write(body)
}

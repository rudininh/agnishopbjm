package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"

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

// Handler untuk ambil token TikTok
func TikTokGetTokenHandler(w http.ResponseWriter, r *http.Request) {
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		http.Error(w, "DATABASE_URL not set", http.StatusInternalServerError)
		return
	}

	db, err := sql.Open("postgres", dbURL)
	if err != nil {
		http.Error(w, "Failed connect DB: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer db.Close()

	// Ambil kode terbaru dari tabel callback
	var code string
	err = db.QueryRowContext(context.Background(), `
		SELECT code FROM tiktok_callbacks ORDER BY id DESC LIMIT 1
	`).Scan(&code)
	if err != nil {
		http.Error(w, "No auth code found: "+err.Error(), http.StatusInternalServerError)
		return
	}

	// Buat URL
	url := fmt.Sprintf("%s?app_key=%s&app_secret=%s&auth_code=%s&grant_type=authorized_code",
		TikTokTokenURL, AppKey, AppSecret, code)

	// Kirim permintaan ke TikToks
	resp, err := http.Get(url)
	if err != nil {
		http.Error(w, "Failed request: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)

	// Parse JSON TikTok response
	var data TikTokResponse
	if err := json.Unmarshal(body, &data); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Failed to parse TikTok response","raw":%q}`, string(body)), http.StatusInternalServerError)
		return
	}

	// Jika sukses (code = 0)
	if data.Code == 0 && data.Data.AccessToken != "" {
		_, err = db.ExecContext(context.Background(), `
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
			fmt.Println("⚠️ DB create error:", err)
		}

		// Simpan token
		_, err = db.ExecContext(context.Background(), `
			INSERT INTO tiktok_tokens (open_id, seller_name, seller_region, access_token, refresh_token, expire_at)
			VALUES ($1, $2, $3, $4, $5, to_timestamp($6))
		`, data.Data.OpenID, data.Data.SellerName, data.Data.SellerBaseRegion,
			data.Data.AccessToken, data.Data.RefreshToken, data.Data.AccessTokenExpireIn)
		if err != nil {
			fmt.Println("⚠️ DB insert error:", err)
		}

		fmt.Printf("✅ Token TikTok saved for %s (%s)\n", data.Data.SellerName, data.Data.OpenID)
	}

	w.Header().Set("Content-Type", "application/json")
	w.Write(body)
}

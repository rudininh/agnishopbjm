package main

import (
	"bytes"
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

// TikTok credentials
const (
	ClientKey    = "your_client_key"
	ClientSecret = "your_client_secret"
	TokenURL     = "https://open-api.tiktok.com/oauth/access_token/"
)

type TikTokTokenResponse struct {
	Data struct {
		AccessToken  string `json:"access_token"`
		ExpiresIn    int    `json:"expires_in"`
		OpenID       string `json:"open_id"`
		RefreshToken string `json:"refresh_token"`
		Scope        string `json:"scope"`
	} `json:"data"`
	Message string `json:"message"`
}

func TikTokGetTokenHandler(w http.ResponseWriter, r *http.Request) {
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

	// Ambil code terakhir dari callback TikTok
	var code string
	err = db.QueryRowContext(context.Background(),
		`SELECT code FROM tiktok_callbacks ORDER BY id DESC LIMIT 1`,
	).Scan(&code)
	if err != nil {
		http.Error(w, "Failed to get last code: "+err.Error(), http.StatusInternalServerError)
		return
	}

	redirectURI := "https://yourdomain.com/callback" // harus sama dengan yang didaftarkan di TikTok Developer

	body := map[string]interface{}{
		"client_key":    ClientKey,
		"client_secret": ClientSecret,
		"code":          code,
		"grant_type":    "authorization_code",
		"redirect_uri":  redirectURI,
	}
	jsonBody, _ := json.Marshal(body)

	req, _ := http.NewRequest("POST", TokenURL, bytes.NewBuffer(jsonBody))
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		http.Error(w, "Request failed: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)

	var tokenResp TikTokTokenResponse
	if err := json.Unmarshal(responseBody, &tokenResp); err != nil {
		http.Error(w, "Failed to parse TikTok response: "+err.Error(), http.StatusInternalServerError)
		return
	}

	if tokenResp.Data.AccessToken != "" {
		_, err = db.ExecContext(context.Background(),
			`INSERT INTO tiktok_tokens (open_id, access_token, refresh_token, expires_in, scope)
			 VALUES ($1, $2, $3, $4, $5)`,
			tokenResp.Data.OpenID,
			tokenResp.Data.AccessToken,
			tokenResp.Data.RefreshToken,
			tokenResp.Data.ExpiresIn,
			tokenResp.Data.Scope,
		)
		if err != nil {
			fmt.Println("❌ Failed to insert token:", err)
		} else {
			fmt.Println("✅ Token inserted successfully for open_id:", tokenResp.Data.OpenID)
		}
	}

	fmt.Printf("✅ Code: %s\n📦 Body: %s\n🧾 Response: %s\n",
		code, jsonBody, responseBody)

	w.Header().Set("Content-Type", "application/json")
	w.Write(responseBody)
}

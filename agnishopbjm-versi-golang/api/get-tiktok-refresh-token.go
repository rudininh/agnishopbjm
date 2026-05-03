package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"time"

	_ "github.com/lib/pq"
)

const refreshURL = "https://auth.tiktok-shops.com/api/v2/token/refresh"

/* ======================== STRUCT ======================== */

type RefreshResponse struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
	Data    struct {
		AccessToken          string   `json:"access_token"`
		RefreshToken         string   `json:"refresh_token"`
		AccessTokenExpireIn  int64    `json:"access_token_expire_in"`
		RefreshTokenExpireIn int64    `json:"refresh_token_expire_in"`
		OpenID               string   `json:"open_id"`
		SellerName           string   `json:"seller_name"`
		SellerRegion         string   `json:"seller_base_region"`
		UserType             int      `json:"user_type"`
		GrantedScopes        []string `json:"granted_scopes"`
	} `json:"data"`
}

/* ======================== HANDLER ======================== */

func Handler(w http.ResponseWriter, r *http.Request) {
	log.Println("▶️ TikTok Refresh Token START")

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	db, err := openDB()
	if err != nil {
		log.Println("❌ openDB error:", err)
		respondError(w, err)
		return
	}
	defer db.Close()
	log.Println("✅ Database connected")

	appKey, appSecret, err := loadAppCredential(ctx, db)
	if err != nil {
		log.Println("❌ loadAppCredential error:", err)
		respondError(w, err)
		return
	}
	log.Println("✅ AppKey loaded:", appKey)

	refreshToken, err := loadRefreshToken(ctx, db)
	if err != nil {
		log.Println("❌ loadRefreshToken error:", err)
		respondError(w, err)
		return
	}
	log.Println("✅ RefreshToken loaded (len):", len(refreshToken))

	resp, err := refreshTokenRequest(ctx, appKey, appSecret, refreshToken)
	if err != nil {
		log.Println("❌ refreshTokenRequest error:", err)
		respondError(w, err)
		return
	}

	log.Printf("📦 TikTok API response code=%d message=%s\n", resp.Code, resp.Message)

	if resp.Code != 0 {
		log.Println("❌ TikTok API ERROR:", resp.Message)
		respondJSON(w, http.StatusBadRequest, resp)
		return
	}

	if err := saveToken(ctx, db, resp); err != nil {
		log.Println("❌ saveToken error:", err)
		respondError(w, err)
		return
	}

	log.Println("✅ Refresh token SUCCESS")

	respondJSON(w, http.StatusOK, map[string]any{
		"status":    "success",
		"open_id":   resp.Data.OpenID,
		"shop_name": resp.Data.SellerName,
		"region":    resp.Data.SellerRegion,
		"expire_at": resp.Data.AccessTokenExpireIn,
	})
}

/* ======================== RESPONSE ======================== */

func respondError(w http.ResponseWriter, err error) {
	respondJSON(w, http.StatusInternalServerError, map[string]any{
		"status": "error",
		"error":  err.Error(),
	})
}

func respondJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}

/* ======================== DB ======================== */

func loadAppCredential(ctx context.Context, db *sql.DB) (string, string, error) {
	var appKey, appSecret string
	err := db.QueryRowContext(ctx, `
		SELECT app_key, app_secret
		FROM tiktok_config
		ORDER BY id DESC
		LIMIT 1
	`).Scan(&appKey, &appSecret)
	return appKey, appSecret, err
}

func loadRefreshToken(ctx context.Context, db *sql.DB) (string, error) {
	var refreshToken string
	err := db.QueryRowContext(ctx, `
		SELECT refresh_token
		FROM tiktok_tokens
		ORDER BY created_at DESC
		LIMIT 1
	`).Scan(&refreshToken)

	if err != nil {
		return "", err
	}
	return refreshToken, nil
}

/* ======================== TIKTOK ======================== */

func refreshTokenRequest(ctx context.Context, appKey, appSecret, refreshToken string) (*RefreshResponse, error) {
	log.Println("🔄 Requesting refresh token to TikTok")

	params := url.Values{}
	params.Set("app_key", appKey)
	params.Set("app_secret", appSecret)
	params.Set("refresh_token", refreshToken)
	params.Set("grant_type", "refresh_token")

	fullURL := refreshURL + "?" + params.Encode()
	log.Println("🌐 TikTok URL:", fullURL)

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, fullURL, nil)
	if err != nil {
		return nil, err
	}

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	log.Println("📨 HTTP Status:", resp.Status)
	log.Println("📨 Raw TikTok response:", string(body))

	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("tiktok http error: %s", string(body))
	}

	var result RefreshResponse
	if err := json.Unmarshal(body, &result); err != nil {
		return nil, err
	}

	return &result, nil
}

/* ======================== SAVE ======================== */

func saveToken(ctx context.Context, db *sql.DB, res *RefreshResponse) error {
	log.Println("💾 Saving token to database")

	expireAt := time.Unix(res.Data.AccessTokenExpireIn, 0)
	expireIn := int(time.Until(expireAt).Seconds())

	_, err := db.ExecContext(ctx, `
		INSERT INTO tiktok_tokens (
			open_id,
			seller_name,
			seller_region,
			access_token,
			refresh_token,
			expire_at,
			expire_in,
			shop_id,
			created_at
		)
		VALUES ($1,$2,$3,$4,$5,$6,$7,NULL,NOW())
		ON CONFLICT (open_id)
		DO UPDATE SET
			access_token = EXCLUDED.access_token,
			refresh_token = EXCLUDED.refresh_token,
			expire_at = EXCLUDED.expire_at,
			expire_in = EXCLUDED.expire_in;
	`,
		res.Data.OpenID,
		res.Data.SellerName,
		res.Data.SellerRegion,
		res.Data.AccessToken,
		res.Data.RefreshToken,
		expireAt,
		expireIn,
	)

	return err
}

/* ======================== DB OPEN ======================== */

func openDB() (*sql.DB, error) {
	dsn := os.Getenv("DATABASE_URL")
	if dsn == "" {
		return nil, fmt.Errorf("DATABASE_URL not set")
	}
	return sql.Open("postgres", dsn)
}

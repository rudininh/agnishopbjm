package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"

	_ "github.com/lib/pq"
)

const refreshURL = "https://auth.tiktok-shops.com/api/v2/token/refresh"

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

func main() {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	db, err := openDB()
	if err != nil {
		panic(err)
	}
	defer db.Close()

	appKey, appSecret, err := loadAppCredential(ctx, db)
	if err != nil {
		panic(err)
	}

	refreshToken, err := loadRefreshToken(ctx, db)
	if err != nil {
		panic(err)
	}

	fmt.Println("🔄 Refreshing TikTok access token...")

	resp, err := refreshTokenRequest(ctx, appKey, appSecret, refreshToken)
	if err != nil {
		panic(err)
	}

	if resp.Code != 0 {
		panic("TikTok error: " + resp.Message)
	}

	if err := saveToken(ctx, db, resp); err != nil {
		panic(err)
	}

	fmt.Println("✅ Refresh token berhasil")
	fmt.Println("Access token expire at:", time.Unix(resp.Data.AccessTokenExpireIn, 0))
}

func loadAppCredential(ctx context.Context, db *sql.DB) (string, string, error) {
	var appKey, appSecret string

	err := db.QueryRowContext(ctx, `
		SELECT app_key, app_secret
		FROM tiktok_config
		ORDER BY id DESC
		LIMIT 1
	`).Scan(&appKey, &appSecret)

	if err != nil {
		return "", "", err
	}

	return appKey, appSecret, nil
}

func loadRefreshToken(ctx context.Context, db *sql.DB) (string, error) {
	var refreshToken string

	err := db.QueryRowContext(ctx, `
		SELECT refresh_token
		FROM tiktok_tokens
		ORDER BY updated_at DESC
		LIMIT 1
	`).Scan(&refreshToken)

	if err != nil {
		return "", err
	}

	return refreshToken, nil
}

func refreshTokenRequest(
	ctx context.Context,
	appKey, appSecret, refreshToken string,
) (*RefreshResponse, error) {

	form := url.Values{}
	form.Set("app_key", appKey)
	form.Set("app_secret", appSecret)
	form.Set("refresh_token", refreshToken)
	form.Set("grant_type", "refresh_token")

	req, err := http.NewRequestWithContext(
		ctx,
		http.MethodPost,
		refreshURL,
		strings.NewReader(form.Encode()),
	)
	if err != nil {
		return nil, err
	}

	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)

	var result RefreshResponse
	if err := json.Unmarshal(body, &result); err != nil {
		return nil, err
	}

	return &result, nil
}

func saveToken(ctx context.Context, db *sql.DB, res *RefreshResponse) error {

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
			updated_at
		)
		VALUES ($1,$2,$3,$4,$5,$6,$7,NOW())
		ON CONFLICT (open_id)
		DO UPDATE SET
			access_token = EXCLUDED.access_token,
			refresh_token = EXCLUDED.refresh_token,
			expire_at = EXCLUDED.expire_at,
			expire_in = EXCLUDED.expire_in,
			updated_at = NOW();
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

func openDB() (*sql.DB, error) {
	dsn := os.Getenv("DATABASE_URL")
	if dsn == "" {
		return nil, fmt.Errorf("DATABASE_URL not set")
	}
	return sql.Open("postgres", dsn)
}

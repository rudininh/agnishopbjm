package tiktok

import (
	"context"
	"database/sql"
	"errors"
	"os"
	"time"
)

type TikTokConfig struct {
	AppKey    string
	AppSecret string

	ShopID string
	Cipher string

	AccessToken  string
	RefreshToken string
	ExpireIn     int

	Code string
}

func LoadTikTokConfig() (*TikTokConfig, error) {

	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		return nil, errors.New("DATABASE_URL not set")
	}

	db, err := sql.Open("postgres", dbURL)
	if err != nil {
		return nil, err
	}
	defer db.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	cfg := &TikTokConfig{}

	// ===============================
	// 1️⃣ Ambil appKey & appSecret
	// ===============================
	err = db.QueryRowContext(ctx, `
		SELECT app_key, app_secret
		FROM tiktok_config
		ORDER BY id DESC
		LIMIT 1
	`).Scan(&cfg.AppKey, &cfg.AppSecret)

	if err != nil {
		return nil, errors.New("tiktok_config not found: " + err.Error())
	}

	// ===============================
	// 2️⃣ Ambil cipher & shop_id
	// ===============================
	err = db.QueryRowContext(ctx, `
		SELECT id, cipher
		FROM tiktok_shops
		ORDER BY updated_at DESC
		LIMIT 1
	`).Scan(&cfg.ShopID, &cfg.Cipher)

	if err != nil {
		return nil, errors.New("tiktok_shops not found: " + err.Error())
	}

	// ===============================
	// 3️⃣ Ambil access_token & refresh_token
	// ===============================
	err = db.QueryRowContext(ctx, `
		SELECT access_token, refresh_token, expire_in
		FROM tiktok_tokens
		WHERE shop_id = $1
		ORDER BY updated_at DESC
		LIMIT 1
	`, cfg.ShopID).Scan(&cfg.AccessToken, &cfg.RefreshToken, &cfg.ExpireIn)

	if err != nil {
		return nil, errors.New("tiktok_tokens not found: " + err.Error())
	}

	// ===============================
	// 4️⃣ Ambil code dari callback OAuth
	// ===============================
	err = db.QueryRowContext(ctx, `
		SELECT code
		FROM tiktok_callbacks
		ORDER BY id DESC
		LIMIT 1
	`).Scan(&cfg.Code)

	if err != nil {
		return nil, errors.New("tiktok_callbacks not found: " + err.Error())
	}

	return cfg, nil
}

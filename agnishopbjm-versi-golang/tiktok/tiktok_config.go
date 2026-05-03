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

	// ✅ WAJIB UNTUK INVENTORY UPDATE
	WarehouseID string
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

	// 1️⃣ app_key & app_secret
	err = db.QueryRowContext(ctx, `
		SELECT app_key, app_secret
		FROM tiktok_config
		ORDER BY id DESC
		LIMIT 1
	`).Scan(&cfg.AppKey, &cfg.AppSecret)
	if err != nil {
		return nil, errors.New("tiktok_config not found: " + err.Error())
	}

	// 2️⃣ shop_id & cipher
	err = db.QueryRowContext(ctx, `
		SELECT id, cipher
		FROM tiktok_shops
		ORDER BY created_at DESC
		LIMIT 1
	`).Scan(&cfg.ShopID, &cfg.Cipher)
	if err != nil {
		return nil, errors.New("tiktok_shops not found: " + err.Error())
	}

	// 3️⃣ token
	err = db.QueryRowContext(ctx, `
		SELECT access_token, refresh_token, expire_in
		FROM tiktok_tokens
		ORDER BY created_at DESC
		LIMIT 1
	`).Scan(&cfg.AccessToken, &cfg.RefreshToken, &cfg.ExpireIn)
	if err != nil {
		return nil, errors.New("tiktok_tokens not found: " + err.Error())
	}

	// 4️⃣ oauth code
	_ = db.QueryRowContext(ctx, `
		SELECT code
		FROM tiktok_callbacks
		ORDER BY id DESC
		LIMIT 1
	`).Scan(&cfg.Code)

	// ✅ 5️⃣ warehouse_id (FIXED SESUAI DATA ANDA)
	cfg.WarehouseID = "7395901885692495617"

	return cfg, nil
}

/* =====================================================
   OPTIONAL GETTERS (AMAN UNTUK SDK / INTERFACE)
   ===================================================== */

func (c *TikTokConfig) GetAccessToken() string {
	return c.AccessToken
}

func (c *TikTokConfig) GetAppKey() string {
	return c.AppKey
}

func (c *TikTokConfig) GetAppSecret() string {
	return c.AppSecret
}

func (c *TikTokConfig) GetCipher() string {
	return c.Cipher
}

func (c *TikTokConfig) GetShopID() string {
	return c.ShopID
}

func (c *TikTokConfig) GetWarehouseID() string {
	return c.WarehouseID
}

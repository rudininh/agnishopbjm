package shopee

import (
	"context"
	"database/sql"
	"errors"
	"time"
)

/*
TABEL: shopee_config

partner_id  BIGINT NOT NULL
partner_key TEXT NOT NULL
is_active   BOOLEAN NOT NULL DEFAULT TRUE
created_at  TIMESTAMP NOT NULL DEFAULT NOW()
*/

type ShopeeConfig struct {
	PartnerID  int64
	PartnerKey string
	IsActive   bool
	CreatedAt  time.Time
}

// ================================
// GET CONFIG SHOPEE (AKTIF)
// ================================
func GetShopeeConfig(
	ctx context.Context,
	db *sql.DB,
) (*ShopeeConfig, error) {

	const query = `
		SELECT
			partner_id,
			partner_key,
			is_active,
			created_at
		FROM shopee_config
		WHERE is_active = TRUE
		LIMIT 1
	`

	var cfg ShopeeConfig

	err := db.QueryRowContext(ctx, query).Scan(
		&cfg.PartnerID,
		&cfg.PartnerKey,
		&cfg.IsActive,
		&cfg.CreatedAt,
	)

	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, errors.New("shopee config aktif tidak ditemukan")
		}
		return nil, err
	}

	return &cfg, nil
}

/*
TABEL: shopee_tokens

shop_id        BIGINT NOT NULL
access_token   TEXT NOT NULL
refresh_token  TEXT NOT NULL
expire_at      TIMESTAMP NOT NULL
is_active      BOOLEAN NOT NULL DEFAULT TRUE
updated_at     TIMESTAMP NOT NULL DEFAULT NOW()
*/

type ShopeeToken struct {
	ShopID       int64
	AccessToken  string
	RefreshToken string
	ExpireAt     time.Time
	IsActive     bool
	UpdatedAt    time.Time
}

// ================================
// GET TOKEN SHOPEE PER SHOP_ID
// ================================
func GetShopeeToken(
	ctx context.Context,
	db *sql.DB,
	shopID int64,
) (*ShopeeToken, error) {

	const query = `
		SELECT
			shop_id,
			access_token,
			refresh_token,
			expire_at,
			is_active,
			updated_at
		FROM shopee_tokens
		WHERE shop_id = $1
		  AND is_active = TRUE
		LIMIT 1
	`

	var token ShopeeToken

	err := db.QueryRowContext(ctx, query, shopID).Scan(
		&token.ShopID,
		&token.AccessToken,
		&token.RefreshToken,
		&token.ExpireAt,
		&token.IsActive,
		&token.UpdatedAt,
	)

	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, errors.New("shopee token tidak ditemukan")
		}
		return nil, err
	}

	return &token, nil
}

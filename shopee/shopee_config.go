package shopee

import (
	"context"
	"database/sql"
	"errors"
	"time"
)

/*
Struktur tabel yang dipakai:

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

// GetShopeeConfig mengambil 1 config Shopee yang aktif
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

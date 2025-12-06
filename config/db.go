package config

import (
	"context"
	"os"

	"github.com/jackc/pgx/v5/pgxpool"
)

var DB *pgxpool.Pool

func InitDB() error {
	url := os.Getenv("DATABASE_URL")

	cfg, err := pgxpool.ParseConfig(url)
	if err != nil {
		return err
	}

	pool, err := pgxpool.NewWithConfig(context.Background(), cfg)
	if err != nil {
		return err
	}

	DB = pool
	return nil
}

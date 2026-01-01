package handler

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"time"

	"agnishopbjm/shopee"
)

/*
=====================================================
ENDPOINT
GET /api/get-shopee-refresh-token?shop_id=XXXXX
=====================================================
*/

type ShopeeRefreshTokenResponse struct {
	PartnerID    int64  `json:"partner_id"`
	ShopID       int64  `json:"shop_id"`
	AccessToken  string `json:"access_token"`
	RefreshToken string `json:"refresh_token"`
	ExpireIn     int64  `json:"expire_in"`
	RequestID    string `json:"request_id"`
	Error        string `json:"error"`
	Message      string `json:"message"`
}

/*
=====================================================
VERCEL HTTP HANDLER (EXPORT WAJIB)
=====================================================
*/
func ShopeeRefreshTokenHandler(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	shopIDStr := r.URL.Query().Get("shop_id")
	if shopIDStr == "" {
		writeJSON(w, 400, map[string]string{
			"error": "shop_id wajib",
		})
		return
	}

	shopID, err := strconv.ParseInt(shopIDStr, 10, 64)
	if err != nil {
		writeJSON(w, 400, map[string]string{
			"error": "shop_id tidak valid",
		})
		return
	}

	db, err := sql.Open("pgx", os.Getenv("DATABASE_URL"))
	if err != nil {
		writeError(w, err)
		return
	}
	defer db.Close()

	result, err := refreshShopeeAccessToken(ctx, db, shopID)
	if err != nil {
		writeError(w, err)
		return
	}

	writeJSON(w, 200, result)
}

/*
=====================================================
BUSINESS LOGIC
=====================================================
*/
func refreshShopeeAccessToken(
	ctx context.Context,
	db *sql.DB,
	shopID int64,
) (*ShopeeRefreshTokenResponse, error) {

	cfg, err := shopee.GetShopeeConfig(ctx, db)
	if err != nil {
		return nil, err
	}

	var refreshToken string
	err = db.QueryRowContext(ctx, `
		SELECT refresh_token
		FROM shopee_tokens
		WHERE shop_id = $1
		ORDER BY created_at DESC
		LIMIT 1
	`, shopID).Scan(&refreshToken)
	if err != nil {
		return nil, err
	}

	const (
		baseURL = "https://partner.shopeemobile.com"
		path    = "/api/v2/auth/access_token/get"
	)

	timestamp := time.Now().Unix()
	sign := shopee.GenerateShopeeSign(
		cfg.PartnerID,
		cfg.PartnerKey,
		path,
		timestamp,
	)

	url := fmt.Sprintf(
		"%s%s?partner_id=%d&timestamp=%d&sign=%s",
		baseURL, path, cfg.PartnerID, timestamp, sign,
	)

	payload := map[string]interface{}{
		"shop_id":       shopID,
		"refresh_token": refreshToken,
		"partner_id":    cfg.PartnerID,
	}

	body, _ := json.Marshal(payload)

	reqHTTP, err := http.NewRequestWithContext(
		ctx,
		http.MethodPost,
		url,
		bytes.NewBuffer(body),
	)
	if err != nil {
		return nil, err
	}
	reqHTTP.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(reqHTTP)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	var result ShopeeRefreshTokenResponse
	if err := json.Unmarshal(respBody, &result); err != nil {
		return nil, err
	}

	if result.Error != "" {
		return nil, fmt.Errorf("shopee error: %s", result.Message)
	}

	_, err = db.ExecContext(ctx, `
		INSERT INTO shopee_tokens
		(shop_id, access_token, refresh_token, expire_in, request_id, created_at)
		VALUES ($1,$2,$3,$4,$5,NOW())
	`,
		shopID,
		result.AccessToken,
		result.RefreshToken,
		result.ExpireIn,
		result.RequestID,
	)
	if err != nil {
		return nil, err
	}

	return &result, nil
}

/*
=====================================================
HELPERS
=====================================================
*/
func writeJSON(w http.ResponseWriter, code int, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(data)
}

func writeError(w http.ResponseWriter, err error) {
	writeJSON(w, 500, map[string]string{
		"error": err.Error(),
	})
}

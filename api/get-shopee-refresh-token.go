package shopee

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"time"
)

/*
=====================================================
TABEL shopee_tokens

id
shop_id
access_token
refresh_token
expire_in
request_id
created_at
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
Generate Shopee Signature
sign = HMAC_SHA256(partner_id + path + timestamp)
=====================================================
*/
func generateShopeeSign(
	partnerID int64,
	partnerKey string,
	path string,
	timestamp int64,
) string {

	baseString :=
		strconv.FormatInt(partnerID, 10) +
			path +
			strconv.FormatInt(timestamp, 10)

	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))

	return hex.EncodeToString(h.Sum(nil))
}

/*
=====================================================
Refresh Access Token (STEP 3 & 4 DOKUMENTASI)

- Ambil refresh_token TERAKHIR dari DB
- Call RefreshAccessToken API
- Simpan token BARU ke shopee_tokens
=====================================================
*/
func RefreshShopeeAccessToken(
	ctx context.Context,
	db *sql.DB,
	shopID int64,
) (*ShopeeRefreshTokenResponse, error) {

	// 1. Ambil partner config
	cfg, err := GetShopeeConfig(ctx, db)
	if err != nil {
		return nil, err
	}

	// 2. Ambil refresh_token TERAKHIR
	var refreshToken string
	err = db.QueryRowContext(ctx, `
		SELECT refresh_token
		FROM shopee_tokens
		WHERE shop_id = $1
		ORDER BY created_at DESC
		LIMIT 1
	`, shopID).Scan(&refreshToken)

	if err != nil {
		return nil, fmt.Errorf("refresh_token tidak ditemukan untuk shop_id %d", shopID)
	}

	const (
		baseURL = "https://partner.shopeemobile.com"
		path    = "/api/v2/auth/access_token/get"
	)

	timestamp := time.Now().Unix()
	sign := generateShopeeSign(
		cfg.PartnerID,
		cfg.PartnerKey,
		path,
		timestamp,
	)

	// 3. Build URL
	url := fmt.Sprintf(
		"%s%s?partner_id=%d&timestamp=%d&sign=%s",
		baseURL,
		path,
		cfg.PartnerID,
		timestamp,
		sign,
	)

	// 4. BODY SESUAI DOKUMENTASI
	payload := map[string]interface{}{
		"shop_id":       shopID,
		"refresh_token": refreshToken,
		"partner_id":    cfg.PartnerID,
	}

	body, _ := json.Marshal(payload)

	req, err := http.NewRequestWithContext(
		ctx,
		http.MethodPost,
		url,
		bytes.NewBuffer(body),
	)
	if err != nil {
		return nil, err
	}

	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	// 5. Parse response
	var result ShopeeRefreshTokenResponse
	if err := json.Unmarshal(respBody, &result); err != nil {
		return nil, err
	}

	if result.Error != "" {
		return nil, fmt.Errorf(
			"shopee refresh token error: %s - %s",
			result.Error,
			result.Message,
		)
	}

	// 6. SIMPAN TOKEN BARU
	_, err = db.ExecContext(ctx, `
		INSERT INTO shopee_tokens (
			shop_id,
			access_token,
			refresh_token,
			expire_in,
			request_id,
			created_at
		) VALUES (
			$1, $2, $3, $4, $5, NOW()
		)
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

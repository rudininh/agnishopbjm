package handler

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"
	"time"

	"agnishopbjm/shopee"

	_ "github.com/jackc/pgx/v5/stdlib"
)

/*
=====================================================
ENDPOINT
POST /api/get-shopee-refresh-token
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
HANDLER
=====================================================
*/
func ShopeeRefreshTokenHandler(w http.ResponseWriter, r *http.Request) {
	log.Println("=== SHOPEE REFRESH TOKEN START ===")

	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{
			"error": "method harus POST",
		})
		return
	}

	log.Println("METHOD :", r.Method)
	log.Println("URL    :", r.URL.String())

	// ===== READ RAW BODY =====
	var rawBody []byte
	if r.Body != nil {
		rawBody, _ = io.ReadAll(r.Body)
		r.Body = io.NopCloser(bytes.NewBuffer(rawBody))
	}

	if len(rawBody) == 0 {
		log.Println("RAW BODY: <KOSONG>")
	} else {
		log.Println("RAW BODY:", string(rawBody))
	}

	ctx := r.Context()
	var shopID int64

	// ===== DB CONNECT =====
	db, err := sql.Open("pgx", os.Getenv("DATABASE_URL"))
	if err != nil {
		writeError(w, err)
		return
	}
	defer db.Close()

	// ===== 1️⃣ QUERY STRING =====
	if v := r.URL.Query().Get("shop_id"); v != "" {
		if id, err := strconv.ParseInt(v, 10, 64); err == nil {
			shopID = id
			log.Println("SHOP ID dari QUERY:", shopID)
		}
	}

	// ===== 2️⃣ BODY JSON =====
	if shopID == 0 && len(rawBody) > 0 {
		var payload map[string]interface{}
		if err := json.Unmarshal(rawBody, &payload); err == nil {
			if v, ok := payload["shop_id"]; ok {
				switch t := v.(type) {
				case float64:
					shopID = int64(t)
				case string:
					shopID, _ = strconv.ParseInt(t, 10, 64)
				}
				if shopID != 0 {
					log.Println("SHOP ID dari BODY:", shopID)
				}
			}
		}
	}

	// ===== 3️⃣ FALLBACK DATABASE =====
	if shopID == 0 {
		log.Println("ℹ️ shop_id tidak dikirim, ambil dari database...")
		id, err := getLastShopeeShopID(ctx, db)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]string{
				"error": "shop_id tidak dikirim dan database kosong",
			})
			return
		}
		shopID = id
		log.Println("SHOP ID dari DB:", shopID)
	}

	// ===== BUSINESS =====
	result, err := refreshShopeeAccessToken(ctx, db, shopID)
	if err != nil {
		writeError(w, err)
		return
	}

	log.Println("=== SHOPEE REFRESH TOKEN SUCCESS ===")
	writeJSON(w, http.StatusOK, result)
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

	token, err := shopee.GetShopeeToken(ctx, db, shopID)
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
		baseURL,
		path,
		cfg.PartnerID,
		timestamp,
		sign,
	)

	payload := map[string]interface{}{
		"partner_id":    cfg.PartnerID,
		"shop_id":       shopID,
		"refresh_token": token.RefreshToken,
	}

	body, _ := json.MarshalIndent(payload, "", "  ")

	// ===== FULL CURL LOG =====
	log.Println("=== SHOPEE CURL (COPY PASTEABLE) ===")
	log.Printf(
		`curl -X POST "%s" -H "Content-Type: application/json" -d '%s'`,
		url,
		string(body),
	)
	log.Println("===================================")

	req, _ := http.NewRequestWithContext(
		ctx,
		http.MethodPost,
		url,
		bytes.NewBuffer(body),
	)
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)
	log.Println("SHOPEE HTTP STATUS:", resp.StatusCode)
	log.Println("SHOPEE RAW RESPONSE:", string(respBody))

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("shopee http %d: %s", resp.StatusCode, string(respBody))
	}

	var result ShopeeRefreshTokenResponse
	if err := json.Unmarshal(respBody, &result); err != nil {
		return nil, err
	}

	if result.Error != "" {
		return nil, fmt.Errorf("shopee error: %s", result.Message)
	}

	// ===== INSERT TOKEN =====
	_, err = db.ExecContext(ctx, `
		INSERT INTO shopee_tokens
		(shop_id, access_token, refresh_token, expire_in, request_id, created_at)
		VALUES ($1, $2, $3, $4, $5, NOW())
	`,
		result.ShopID,
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
FALLBACK SHOP ID
=====================================================
*/
func getLastShopeeShopID(ctx context.Context, db *sql.DB) (int64, error) {
	var shopID int64
	err := db.QueryRowContext(ctx, `
		SELECT shop_id
		FROM shopee_tokens
		ORDER BY created_at DESC
		LIMIT 1
	`).Scan(&shopID)
	return shopID, err
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
	writeJSON(w, http.StatusInternalServerError, map[string]string{
		"error": err.Error(),
	})
}

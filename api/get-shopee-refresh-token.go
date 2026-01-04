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
========================================
ENDPOINT
POST /api/get-shopee-refresh-token
========================================
*/

// ================= RESPONSE SHOPEE (FIX SESUAI ASLI) =================
type ShopeeRefreshTokenResponse struct {
	PartnerID    int    `json:"partner_id"`
	ShopID       int64  `json:"shop_id"`
	AccessToken  string `json:"access_token"`
	RefreshToken string `json:"refresh_token"`
	ExpireIn     int64  `json:"expire_in"`
	RequestID    string `json:"request_id"`
	Error        string `json:"error"`
	Message      string `json:"message"`
}

// ===================================================================

func ShopeeRefreshHandler(w http.ResponseWriter, r *http.Request) {
	log.Println("=== SHOPEE REFRESH TOKEN START ===")

	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]string{
			"error": "method harus POST",
		})
		return
	}

	log.Println("METHOD :", r.Method)
	log.Println("URL    :", r.URL.Path)

	// ===== RAW BODY =====
	var rawBody []byte
	if r.Body != nil {
		rawBody, _ = io.ReadAll(r.Body)
	}
	if len(rawBody) == 0 {
		log.Println("RAW BODY: <KOSONG>")
	} else {
		log.Println("RAW BODY:", string(rawBody))
	}

	ctx := context.Background()

	// ===== DB CONNECT =====
	db, err := sql.Open("pgx", os.Getenv("DATABASE_URL"))
	if err != nil {
		writeError(w, err)
		return
	}
	defer db.Close()

	var shopID int64

	// ===== 1. QUERY STRING =====
	if v := r.URL.Query().Get("shop_id"); v != "" {
		if id, err := strconv.ParseInt(v, 10, 64); err == nil {
			shopID = id
			log.Println("SHOP ID dari QUERY:", shopID)
		}
	}

	// ===== 2. BODY =====
	if shopID == 0 && len(rawBody) > 0 {
		var body map[string]interface{}
		if json.Unmarshal(rawBody, &body) == nil {
			if v, ok := body["shop_id"]; ok {
				switch t := v.(type) {
				case float64:
					shopID = int64(t)
				case string:
					shopID, _ = strconv.ParseInt(t, 10, 64)
				}
				log.Println("SHOP ID dari BODY:", shopID)
			}
		}
	}

	// ===== 3. FALLBACK DB =====
	if shopID == 0 {
		log.Println("ℹ️ shop_id tidak dikirim, ambil dari database...")
		err = db.QueryRowContext(
			ctx,
			`SELECT shop_id FROM shopee_tokens ORDER BY created_at DESC LIMIT 1`,
		).Scan(&shopID)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]string{
				"error": "shop_id tidak ditemukan",
			})
			return
		}
		log.Println("SHOP ID dari DB:", shopID)
	}

	// ===== AMBIL REFRESH TOKEN TERAKHIR =====
	var refreshToken string
	err = db.QueryRowContext(
		ctx,
		`SELECT refresh_token
		 FROM shopee_tokens
		 WHERE shop_id=$1
		 ORDER BY created_at DESC
		 LIMIT 1`,
		shopID,
	).Scan(&refreshToken)
	if err != nil {
		writeError(w, err)
		return
	}

	// ===== CONFIG =====
	cfg, err := shopee.GetShopeeConfig(ctx, db)
	if err != nil {
		writeError(w, err)
		return
	}

	// ===== REQUEST KE SHOPEE =====
	const path = "/api/v2/auth/access_token/get"
	ts := time.Now().Unix()

	sign := shopee.GenerateShopeeSign(
		cfg.PartnerID,
		cfg.PartnerKey,
		path,
		ts,
	)

	url := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&timestamp=%d&sign=%s",
		path,
		cfg.PartnerID,
		ts,
		sign,
	)

	payload := map[string]interface{}{
		"partner_id":    cfg.PartnerID,
		"shop_id":       shopID,
		"refresh_token": refreshToken,
	}

	bodyJSON, _ := json.Marshal(payload)

	log.Println("=== CURL ===")
	log.Printf(
		`curl -X POST "%s" -H "Content-Type: application/json" -d '%s'`,
		url,
		string(bodyJSON),
	)

	req, _ := http.NewRequest(http.MethodPost, url, bytes.NewBuffer(bodyJSON))
	req.Header.Set("Content-Type", "application/json")

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		writeError(w, err)
		return
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)
	log.Println("SHOPEE RESPONSE:", string(respBody))

	var shResp ShopeeRefreshTokenResponse
	if err := json.Unmarshal(respBody, &shResp); err != nil {
		writeError(w, err)
		return
	}

	// ===== VALIDASI TOKEN (WAJIB) =====
	if shResp.AccessToken == "" || shResp.RefreshToken == "" {
		log.Println("❌ TOKEN DARI SHOPEE KOSONG")
		writeJSON(w, http.StatusBadGateway, shResp)
		return
	}

	// ===== INSERT DB (SATU KALI) =====
	res, err := db.ExecContext(ctx, `
		INSERT INTO shopee_tokens
		(shop_id, access_token, refresh_token, expire_in, request_id, created_at)
		VALUES ($1, $2, $3, $4, $5, NOW())
	`,
		shopID,
		shResp.AccessToken,
		shResp.RefreshToken,
		shResp.ExpireIn,
		shResp.RequestID,
	)
	if err != nil {
		log.Println("❌ DB INSERT ERROR:", err)
		writeError(w, err)
		return
	}

	rows, _ := res.RowsAffected()
	log.Printf("✅ DB INSERT SUCCESS: %d row(s) affected", rows)

	writeJSON(w, http.StatusOK, shResp)
}

// =====================================================

func writeJSON(w http.ResponseWriter, code int, v interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, err error) {
	writeJSON(w, http.StatusInternalServerError, map[string]string{
		"error": err.Error(),
	})
}

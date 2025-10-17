package handler

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
	"os"
	"time"

	_ "github.com/lib/pq"
)

const (
	PartnerID  = 2013107
	PartnerKey = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	Host       = "https://partner.shopeemobile.com"
)

func generateSign(baseString, key string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

func Handler(w http.ResponseWriter, r *http.Request) {
	// Ambil koneksi database dari environment (Neon)
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		http.Error(w, "DATABASE_URL not set", http.StatusInternalServerError)
		return
	}

	db, err := sql.Open("postgres", dbURL)
	if err != nil {
		http.Error(w, "Database connection failed: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer db.Close()

	// Ambil baris terakhir dari tabel shopee_callbacks
	var code string
	var shopID int64
	err = db.QueryRowContext(context.Background(),
		`SELECT code, shop_id FROM shopee_callbacks ORDER BY id DESC LIMIT 1`,
	).Scan(&code, &shopID)
	if err != nil {
		http.Error(w, "Failed to get last row: "+err.Error(), http.StatusInternalServerError)
		return
	}

	timestamp := time.Now().Unix()
	path := "/api/v2/auth/token/get"

	baseString := fmt.Sprintf("%d%s%d", PartnerID, path, timestamp)
	sign := generateSign(baseString, PartnerKey)

	url := fmt.Sprintf("%s%s?partner_id=%d&timestamp=%d&sign=%s",
		Host, path, PartnerID, timestamp, sign)

	body := map[string]interface{}{
		"code":       code,
		"shop_id":    shopID,
		"partner_id": PartnerID,
	}
	jsonBody, _ := json.Marshal(body)

	req, _ := http.NewRequest("POST", url, bytes.NewBuffer(jsonBody))
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		http.Error(w, "Request failed: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)

	// Log ke console di Vercel (optional)
	fmt.Printf("✅ Code terakhir: %s\n✅ Shop ID: %d\n📦 Request Body: %s\n📜 Status Code: %d\n🧾 Response: %s\n",
		code, shopID, jsonBody, resp.StatusCode, responseBody)

	// Kirim response ke browser / client
	w.Header().Set("Content-Type", "application/json")
	w.Write(responseBody)
}

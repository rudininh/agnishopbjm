package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"time"

	_ "github.com/lib/pq" // PostgreSQL driver
)

const (
	PartnerID  = 2013107
	PartnerKey = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	Host       = "https://partner.shopeemobile.com"
	Redirect   = "https://funny-haupia-0efca3.netlify.app/callback"
)

func generateSign(baseString, key string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

var db *sql.DB

func main() {
	// Ambil URL database dari environment
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		log.Fatal("DATABASE_URL belum diset")
	}



	var err error
	db, err = sql.Open("postgres", dbURL)
	if err != nil {
		log.Fatalf("Gagal konek ke database: %v", err)
	}
	defer db.Close()

	// Buat tabel jika belum ada
	createTable()

	http.HandleFunc("/", handleIndex)
	http.HandleFunc("/callback", handleCallback)

	port := ":8080"
	fmt.Println("=== Shopee Authorization URL ===")
	timestamp := time.Now().Unix()
	path := "/api/v2/shop/auth_partner"
	baseString := fmt.Sprintf("%d%s%d", PartnerID, path, timestamp)
	sign := generateSign(baseString, PartnerKey)

	authURL := fmt.Sprintf(
		"%s%s?partner_id=%d&timestamp=%d&sign=%s&redirect=%s",
		Host, path, PartnerID, timestamp, sign, Redirect,
	)
	fmt.Println(authURL)
	fmt.Println("===============================")
	fmt.Println("Server running on port", port)

	log.Fatal(http.ListenAndServe(port, nil))
}

func handleIndex(w http.ResponseWriter, r *http.Request) {
	fmt.Fprintln(w, "Server aktif. Buka link di terminal untuk otorisasi Shopee.")
}

func handleCallback(w http.ResponseWriter, r *http.Request) {
	code := r.URL.Query().Get("code")
	shopID := r.URL.Query().Get("shop_id")

	if code == "" || shopID == "" {
		http.Error(w, "Code atau ShopID tidak ditemukan di URL callback.", http.StatusBadRequest)
		return
	}

	fmt.Fprintf(w, "✅ Authorization berhasil! Code: %s, ShopID: %s\nMenukar dengan access_token...", code, shopID)

	tokenResp, err := getAccessToken(code, shopID)
	if err != nil {
		http.Error(w, fmt.Sprintf("Gagal ambil access token: %v", err), http.StatusInternalServerError)
		return
	}

	// Simpan ke database
	saveToDatabase(shopID, tokenResp)

	fmt.Fprintln(w, "\n✅ Access token berhasil disimpan ke database")
	fmt.Println("\n=== TOKEN DISIMPAN KE DATABASE ===")
	fmt.Printf("%+v\n", tokenResp)
}

func getAccessToken(code, shopID string) (map[string]interface{}, error) {
	timestamp := time.Now().Unix()
	path := "/api/v2/auth/token/get"
	baseString := fmt.Sprintf("%d%s%d", PartnerID, path, timestamp)
	sign := generateSign(baseString, PartnerKey)

	url := fmt.Sprintf("%s%s?partner_id=%d&timestamp=%d&sign=%s", Host, path, PartnerID, timestamp, sign)

	body := map[string]interface{}{
		"code":       code,
		"shop_id":    shopID,
		"partner_id": PartnerID,
	}
	jsonBody, _ := json.Marshal(body)

	req, _ := http.NewRequest("POST", url, bytes.NewBuffer(jsonBody))
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)

	var result map[string]interface{}
	if err := json.Unmarshal(responseBody, &result); err != nil {
		return nil, err
	}

	return result, nil
}

// Buat tabel jika belum ada
func createTable() {
	query := `
	CREATE TABLE IF NOT EXISTS shopee_tokens (
		id SERIAL PRIMARY KEY,
		shop_id VARCHAR(50),
		access_token TEXT,
		refresh_token TEXT,
		expire_in BIGINT,
		created_at TIMESTAMP DEFAULT NOW()
	)`
	_, err := db.Exec(query)
	if err != nil {
		log.Fatalf("Gagal membuat tabel: %v", err)
	}
}

// Simpan token ke database
func saveToDatabase(shopID string, data map[string]interface{}) {
	resp := data["response"].(map[string]interface{})

	accessToken := resp["access_token"].(string)
	refreshToken := resp["refresh_token"].(string)
	expireIn := int64(resp["expire_in"].(float64))

	_, err := db.Exec(`
		INSERT INTO shopee_tokens (shop_id, access_token, refresh_token, expire_in)
		VALUES ($1, $2, $3, $4)`,
		shopID, accessToken, refreshToken, expireIn)

	if err != nil {
		log.Printf("Gagal simpan ke database: %v", err)
	} else {
		log.Println("✅ Data berhasil disimpan ke database.")
	}
}

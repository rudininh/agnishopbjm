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

func main() {
	// Ambil DATABASE_URL dari environment
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		fmt.Println("❌ DATABASE_URL not found in environment")
		return
	}

	// Koneksi ke database
	db, err := sql.Open("postgres", dbURL)
	if err != nil {
		fmt.Println("❌ Failed to connect database:", err)
		return
	}
	defer db.Close()

	// Ambil baris terakhir dari tabel shopee_callbacks
	var code string
	var shopID int64
	err = db.QueryRow(`SELECT code, shop_id FROM shopee_callbacks ORDER BY id DESC LIMIT 1`).Scan(&code, &shopID)
	if err != nil {
		fmt.Println("❌ Query error:", err)
		return
	}

	fmt.Println("✅ Code terakhir:", code)
	fmt.Println("✅ Shop ID:", shopID)

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
	jsonBody, _ := json.MarshalIndent(body, "", "  ")
	fmt.Println("\n📦 Request Body:")
	fmt.Println(string(jsonBody))

	req, _ := http.NewRequest("POST", url, bytes.NewBuffer(jsonBody))
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		fmt.Println("❌ HTTP Request failed:", err)
		return
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)

	fmt.Println("\n🌐 URL Request:", url)
	fmt.Println("📜 Status Code:", resp.StatusCode)
	fmt.Println("\n🧾 Response Shopee:")
	fmt.Println(string(responseBody))
}

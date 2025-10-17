// package main

// import (
// 	"bytes"
// 	"crypto/hmac"
// 	"crypto/sha256"
// 	"encoding/hex"
// 	"encoding/json"
// 	"fmt"
// 	"io"
// 	"net/http"
// 	"time"
// )

// const (
// 	PartnerID  = 2013107                                                            // pakai Partner ID Production
// 	PartnerKey = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465" // pakai Partner Key Production
// 	Host       = "https://partner.shopeemobile.com"                                 // host untuk production
// )

// func generateSign(baseString, key string) string {
// 	mac := hmac.New(sha256.New, []byte(key))
// 	mac.Write([]byte(baseString))
// 	return hex.EncodeToString(mac.Sum(nil))
// }

// func main() {
// 	code := "46654279446d6c4e78466a6d7a4e7a55" // kode hasil callback terbaru
// 	shopID := 380921117                        // shop_id hasil callback
// 	timestamp := time.Now().Unix()
// 	path := "/api/v2/auth/token/get"

// 	// generate tanda tangan/sign
// 	baseString := fmt.Sprintf("%d%s%d", PartnerID, path, timestamp)
// 	sign := generateSign(baseString, PartnerKey)

// 	url := fmt.Sprintf("%s%s?partner_id=%d&timestamp=%d&sign=%s", Host, path, PartnerID, timestamp, sign)

// 	body := map[string]interface{}{
// 		"code":       code,
// 		"shop_id":    shopID,
// 		"partner_id": PartnerID,
// 	}
// 	jsonBody, _ := json.Marshal(body)

// 	req, _ := http.NewRequest("POST", url, bytes.NewBuffer(jsonBody))
// 	req.Header.Set("Content-Type", "application/json")

// 	client := &http.Client{}
// 	resp, err := client.Do(req)
// 	if err != nil {
// 		panic(err)
// 	}
// 	defer resp.Body.Close()

// 	responseBody, _ := io.ReadAll(resp.Body)
// 	fmt.Println(string(responseBody))
// }

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

// Konstanta Shopee Partner
const (
	PartnerID  = 2013107
	PartnerKey = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	Host       = "https://partner.shopeemobile.com"
)

// generateSign membuat signature SHA256
func generateSign(baseString, key string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

// Handler utama untuk Vercel
func Handler(w http.ResponseWriter, r *http.Request) {
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		http.Error(w, "DATABASE_URL not found", http.StatusInternalServerError)
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
	err = db.QueryRow(`SELECT code, shop_id FROM shopee_callbacks ORDER BY id DESC LIMIT 1`).Scan(&code, &shopID)
	if err != nil {
		http.Error(w, "Query failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	timestamp := time.Now().Unix()
	path := "/api/v2/auth/token/get"

	// Buat tanda tangan/sign
	baseString := fmt.Sprintf("%d%s%d", PartnerID, path, timestamp)
	sign := generateSign(baseString, PartnerKey)

	url := fmt.Sprintf("%s%s?partner_id=%d&timestamp=%d&sign=%s", Host, path, PartnerID, timestamp, sign)

	// Buat body permintaan
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
		http.Error(w, "Request failed: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)

	// Kembalikan hasil respons ke browser
	w.Header().Set("Content-Type", "application/json")
	w.Write(responseBody)
}

// Agar bisa jalan di Vercel
func main() {
	http.HandleFunc("/", Handler)
	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}
	fmt.Println("Server running on port", port)
	http.ListenAndServe(":"+port, nil)
}

package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

const (
	PartnerID  = 2013107                                                            // pakai Partner ID Production
	PartnerKey = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465" // pakai Partner Key Production
	Host       = "https://partner.shopeemobile.com"                                 // host untuk production
)

func generateSign(baseString, key string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

func main() {
	code := "4c636771635744754d736b5251547077" // kode hasil callback terbaru
	shopID := 380921117                        // shop_id hasil callback
	timestamp := time.Now().Unix()
	path := "/api/v2/auth/token/get"

	// generate tanda tangan/sign
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
		panic(err)
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)
	fmt.Println(string(responseBody))
}

package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

// ====== KONFIGURASI SHOPEE ======
const (
	PartnerID  = 2013107
	PartnerKey = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	Host       = "https://partner.shopeemobile.com"
)

// ====== FUNGSI PEMBUAT SIGN ======
func generateSign(baseString, key string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

// ====== HANDLER UNTUK API /get-token ======
func getTokenHandler(w http.ResponseWriter, r *http.Request) {
	code := "4c636771635744754d736b5251547077" // hasil callback terbaru
	shopID := 380921117                        // shop_id hasil callback
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
		http.Error(w, fmt.Sprintf("Gagal: %v", err), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)
	w.Header().Set("Content-Type", "application/json")
	w.Write(responseBody)
}

// ====== MAIN FUNCTION ======
func main() {
	http.HandleFunc("/get-token", getTokenHandler)

	fmt.Println("🚀 Server berjalan di http://localhost:8080")
	fmt.Println("Endpoint aktif di: http://localhost:8080/get-token")
	http.ListenAndServe(":8080", nil)
}

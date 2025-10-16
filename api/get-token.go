package handler

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
	PartnerID  = 2013107
	PartnerKey = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	Host       = "https://partner.shopeemobile.com"
)

func generateSign(baseString, key string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

// Handler adalah fungsi utama yang akan dipanggil Vercel
func Handler(w http.ResponseWriter, r *http.Request) {
	// Ambil parameter dari URL (misal ?code=xxx&shop_id=xxx)
	code := r.URL.Query().Get("code")
	shopID := r.URL.Query().Get("shop_id")

	if code == "" || shopID == "" {
		http.Error(w, "Missing 'code' or 'shop_id' parameter", http.StatusBadRequest)
		return
	}

	timestamp := time.Now().Unix()
	path := "/api/v2/auth/token/get"

	// generate sign
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

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		http.Error(w, "Failed to request token: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	responseBody, _ := io.ReadAll(resp.Body)

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(resp.StatusCode)
	w.Write(responseBody)
}

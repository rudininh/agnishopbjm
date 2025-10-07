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
	code := "744745586b53784672556a7167764c61" // kode hasil callback terbaru
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

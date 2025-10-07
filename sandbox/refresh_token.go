package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"net/http"
	"time"
)

func main() {
	partnerID := 1189715
	partnerKey := "shpk6974696744505755436768596869596b646e704e54724258565457706276" // ganti dengan punya kamu
	shopID := 225989475
	refreshToken := "56624757634173776a4d6f6b47647373"

	timestamp := time.Now().Unix()
	path := "/api/v2/auth/access_token/get"
	baseString := fmt.Sprintf("%d%s%d", partnerID, path, timestamp)

	// bikin sign
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	sign := hex.EncodeToString(h.Sum(nil))

	url := fmt.Sprintf("https://openplatform.sandbox.test-stable.shopee.sg%s?partner_id=%d&timestamp=%d&sign=%s",
		path, partnerID, timestamp, sign)

	// body JSON
	body := map[string]interface{}{
		"partner_id":    partnerID,
		"shop_id":       shopID,
		"refresh_token": refreshToken,
	}
	jsonBody, _ := json.Marshal(body)

	// request
	req, err := http.NewRequest("POST", url, bytes.NewBuffer(jsonBody))
	if err != nil {
		panic(err)
	}
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		panic(err)
	}
	defer resp.Body.Close()

	respBody, _ := ioutil.ReadAll(resp.Body)
	fmt.Println(string(respBody))
}

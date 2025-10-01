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
	partnerID := "1189715"                                                           // ganti dengan partner_id kamu
	partnerKey := "shpk6974696744505755436768596869596b646e704e54724258565457706276" // ganti dengan partner_key kamu
	shopID := "225989475"                                                            // shop_id dari redirect
	code := "674f4b4146694f58555356414d4a7244"                                       // code dari redirect

	timestamp := time.Now().Unix()
	path := "/api/v2/auth/token/get"
	baseString := fmt.Sprintf("%s%s%d", partnerID, path, timestamp)

	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	sign := hex.EncodeToString(h.Sum(nil))

	url := fmt.Sprintf("https://openplatform.sandbox.test-stable.shopee.sg%s?partner_id=%s&timestamp=%d&sign=%s",
		path, partnerID, timestamp, sign)

	// body JSON
	body := map[string]interface{}{
		"code":       code,
		"shop_id":    shopID,
		"partner_id": partnerID,
	}

	jsonBody, _ := json.Marshal(body)

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

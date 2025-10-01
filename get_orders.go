package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io/ioutil"
	"net/http"
	"time"
)

func main() {
	partnerID := "1189715"
	partnerKey := "shpk6974696744505755436768596869596b646e704e54724258565457706276" // yang asli dari Shopee
	shopID := "225989475"
	accessToken := "eyJhbGciOiJIUzI1NiJ9.CNPOSBABGOOm4WsgASi9xPPGBjCJsaLwCzgBQAE.aBZMVol_DlYNMmp4oDSK1VbVbmuoa9NtI9hmSURZi14"
	host := "https://openplatform.sandbox.test-stable.shopee.sg"
	path := "/api/v2/order/get_order_list"
	timestamp := time.Now().Unix()

	// --- bikin sign ---
	baseString := fmt.Sprintf("%s%s%d%s%s", partnerID, path, timestamp, accessToken, shopID)
	mac := hmac.New(sha256.New, []byte(partnerKey))
	mac.Write([]byte(baseString))
	sign := hex.EncodeToString(mac.Sum(nil))

	// --- build URL ---
	now := time.Now().Unix()
	oneDayAgo := now - 86400 // 24 jam kebelakang

	url := fmt.Sprintf("%s%s?partner_id=%s&timestamp=%d&sign=%s&shop_id=%s&access_token=%s"+
		"&time_range_field=create_time&time_from=%d&time_to=%d&page_size=20",
		host, path, partnerID, timestamp, sign, shopID, accessToken, oneDayAgo, now)

	// --- GET request ---
	resp, err := http.Get(url)
	if err != nil {
		panic(err)
	}
	defer resp.Body.Close()

	body, _ := ioutil.ReadAll(resp.Body)
	fmt.Println(string(body))
}

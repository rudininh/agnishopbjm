package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"time"
)

const (
	PartnerID   = "2013107"
	PartnerKey  = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	Host        = "https://partner.shopeemobile.com" // gunakan .com, bukan .co.id
	ShopID      = "380921117"
	AccessToken = "435045784556457a4b5164456247504b"
)

func GenerateSign(baseString, key string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

func main() {
	timestamp := time.Now().UTC().Unix()
	path := "/api/v2/order/get_order_list"

	// format base string: partner_id + path + timestamp + access_token + shop_id
	baseString := PartnerID + path + fmt.Sprintf("%d", timestamp) + AccessToken + ShopID
	sign := GenerateSign(baseString, PartnerKey)

	timeTo := time.Now().Unix()
	timeFrom := timeTo - 15*24*60*60 // 15 hari ke belakang
	timeRangeField := "create_time"

	// ✅ tambahkan order_status agar filter-nya jelas
	// orderStatus := "READY_TO_SHIP"

	url := fmt.Sprintf(
		"%s%s?partner_id=%s&timestamp=%d&sign=%s&shop_id=%s&access_token=%s&time_range_field=%s&time_from=%d&time_to=%d&page_size=20",
		Host, path, PartnerID, timestamp, sign, ShopID, AccessToken, timeRangeField, timeFrom, timeTo)

	fmt.Println("Base string :", baseString)
	fmt.Println("Sign        :", sign)
	fmt.Println("Timestamp   :", timestamp)
	fmt.Println("Request URL :", url)

	resp, err := http.Get(url)
	if err != nil {
		panic(err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	fmt.Println("=== Shopee Get Order List Response ===")
	fmt.Println(string(body))
	fmt.Println("======================================")
}

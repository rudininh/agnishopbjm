// package main

// import (
// 	"crypto/hmac"
// 	"crypto/sha256"
// 	"encoding/hex"
// 	"encoding/json"
// 	"fmt"
// 	"io"
// 	"net/http"
// 	"net/url"
// 	"time"
// )

// const (
// 	PartnerID   = 2013107                                                            // Partner ID production kamu
// 	PartnerKey  = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465" // Partner Key production kamu
// 	AccessToken = "476141706148584c7279554f4459454b"                                 // access_token hasil dari langkah sebelumnya
// 	ShopID      = 380921117                                                          // shop_id kamu
// 	Host        = "https://partner.shopeemobile.com"
// )

// func generateSign(partnerKey, baseString string) string {
// 	h := hmac.New(sha256.New, []byte(partnerKey))
// 	h.Write([]byte(baseString))
// 	return hex.EncodeToString(h.Sum(nil))
// }

// func main() {
// 	path := "/api/v2/order/get_order_list"
// 	timestamp := time.Now().Unix()
// a
// 	// Query parameter
// 	params := url.Values{}
// 	params.Add("partner_id", fmt.Sprintf("%d", PartnerID))
// 	params.Add("timestamp", fmt.Sprintf("%d", timestamp))
// 	params.Add("shop_id", fmt.Sprintf("%d", ShopID))
// 	params.Add("access_token", AccessToken)
// 	params.Add("time_range_field", "create_time")
// 	params.Add("time_from", "1730500000") // contoh rentang waktu (ubah sesuai kebutuhan)
// 	params.Add("time_to", fmt.Sprintf("%d", timestamp))
// 	params.Add("page_size", "20")

// 	// Sign string
// 	baseString := fmt.Sprintf("%d%s%d", PartnerID, path, timestamp)
// 	sign := generateSign(baseString, PartnerKey)

// 	// URL lengkap
// 	fullURL := fmt.Sprintf("%s%s?%s&sign=%s", Host, path, params.Encode(), sign)

// 	resp, err := http.Get(fullURL)
// 	if err != nil {
// 		panic(err)
// 	}
// 	defer resp.Body.Close()

// 	body, _ := io.ReadAll(resp.Body)

// 	var pretty map[string]interface{}
// 	json.Unmarshal(body, &pretty)
// 	b, _ := json.MarshalIndent(pretty, "", "  ")
// 	fmt.Println(string(b))
// }

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
	partnerID := "2013107"
	partnerKey := "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	shopID := "380921117"
	accessToken := "476141706148584c7279554f4459454b"
	timestamp := time.Now().Unix()

	path := "/api/v2/order/get_order_list"

	baseString := fmt.Sprintf("%s%s%d%s%s", partnerID, path, timestamp, accessToken, shopID)
	sign := generateSign(partnerKey, baseString)

	url := fmt.Sprintf("https://partner.shopeemobile.com%s?partner_id=%s&timestamp=%d&sign=%s&shop_id=%s&access_token=%s",
		path, partnerID, timestamp, sign, shopID, accessToken)

	resp, err := http.Get(url)
	if err != nil {
		panic(err)
	}
	defer resp.Body.Close()

	body, _ := ioutil.ReadAll(resp.Body)
	fmt.Println(string(body))
}

func generateSign(partnerKey, baseString string) string {
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	return hex.EncodeToString(h.Sum(nil))
}

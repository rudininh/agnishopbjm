package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"strconv"
	"time"
)

func main() {

	timest := strconv.FormatInt(time.Now().Unix(), 10)
	host := "https://openplatform.sandbox.test-stable.shopee.sg"
	path := "/api/v2/shop/auth_partner"
	redirectUrl := "https://www.shopee.co.id/"
	partnerId := strconv.Itoa(1189715)
	partnerKey := "shpk6974696744505755436768596869596b646e704e54724258565457706276"
	baseString := fmt.Sprintf("%s%s%s", partnerId, path, timest)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	sign := hex.EncodeToString(h.Sum(nil))
	url := fmt.Sprintf(host+path+"?partner_id=%s&timestamp=%s&sign=%s&redirect=%s", partnerId, timest, sign, redirectUrl)
	fmt.Println(url)
}

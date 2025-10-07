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
	host := "https://partner.test.shopeemobile.com"
	path := "/api/v2/shop/auth_partner"
	redirectUrl := "https://www.baidu.com/"
	partnerId := strconv.Itoa(2006566)
	partnerKey := "1391fd986fe8ec7569bebed75b0c33ee35eb5a305bed7038657a5cd5f75b1c88"
	baseString := fmt.Sprintf("%s%s%s", partnerId, path, timest)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	sign := hex.EncodeToString(h.Sum(nil))
	url := fmt.Sprintf(host+path+"?partner_id=%s&timestamp=%s&sign=%s&redirect=%s", partnerId, timest, sign, redirectUrl)
	fmt.Println(url)
}

package main

import (
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "fmt"
    "time"
)

func main() {
    partnerID := "1189715" // dari dashboard Shopee
    partnerKey := "YOUR_PARTNER_KEY" // ambil dari dashboard
    path := "/api/v2/shop/auth_partner" 
    timestamp := time.Now().Unix()

    // Generate signature
    baseString := fmt.Sprintf("%s%s%d", partnerID, path, timestamp)
    mac := hmac.New(sha256.New, []byte(partnerKey))
    mac.Write([]byte(baseString))
    sign := hex.EncodeToString(mac.Sum(nil))

    fmt.Println("Partner ID:", partnerID)
    fmt.Println("Timestamp:", timestamp)
    fmt.Println("Sign:", sign)

    // URL redirect Shopee auth
    url := fmt.Sprintf("https://partner.test.shopeemobile.com%s?partner_id=%s&timestamp=%d&sign=%s&redirect=http://localhost.com",
        path, partnerID, timestamp, sign)

    fmt.Println("Authorize URL:", url)
}

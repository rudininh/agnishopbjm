package main

import (
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "fmt"
    "time"
)

func main() {
    partnerID := "1189715"
    partnerKey := "ISI_PARTNER_KEY"
    redirect := "http://localhost.com"

    path := "/api/v2/shop/auth_partner"
    timestamp := time.Now().Unix()

    // signature
    base := fmt.Sprintf("%s%s%d", partnerID, path, timestamp)
    mac := hmac.New(sha256.New, []byte(partnerKey))
    mac.Write([]byte(base))
    sign := hex.EncodeToString(mac.Sum(nil))

    // URL untuk authorize
    url := fmt.Sprintf(
        "https://open.sandbox.test-stable.shopee.com/auth?auth_type=seller&partner_id=1189715&redirect_uri=http%3A%2F%2Flocalhost.com&response_type=code",
        path, partnerID, timestamp, sign, redirect,
    )

    fmt.Println("Buka URL ini di browser:")
    fmt.Println(url)
}

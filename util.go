package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
)

func GenerateSign(baseString, secret string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

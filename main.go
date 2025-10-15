package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"log"
	"os/exec"
	"runtime"
	"time"
)

const (
	PartnerID  = 2013107
	PartnerKey = "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	Host       = "https://partner.shopeemobile.com"
	Redirect   = "https://agnishopbjm.vercel.app/api/callback"
)

func main() {
	timestamp := time.Now().Unix()
	path := "/api/v2/shop/auth_partner"
	baseString := fmt.Sprintf("%d%s%d", PartnerID, path, timestamp)
	sign := generateSign(baseString, PartnerKey)

	authURL := fmt.Sprintf(
		"%s%s?partner_id=%d&timestamp=%d&sign=%s&redirect=%s",
		Host, path, PartnerID, timestamp, sign, Redirect,
	)

	fmt.Println("=== Shopee Authorization URL ===")
	fmt.Println(authURL)
	fmt.Println("===============================")

	openBrowser(authURL)
}

func generateSign(baseString, key string) string {
	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(baseString))
	return hex.EncodeToString(mac.Sum(nil))
}

func openBrowser(url string) {
	var cmd string
	var args []string

	switch runtime.GOOS {
	case "windows":
		cmd = "rundll32"
		args = []string{"url.dll,FileProtocolHandler", url}
	case "darwin":
		cmd = "open"
		args = []string{url}
	default: // linux, freebsd, dll
		cmd = "xdg-open"
		args = []string{url}
	}

	if err := exec.Command(cmd, args...).Start(); err != nil {
		log.Printf("Gagal membuka browser: %v", err)
	}
}

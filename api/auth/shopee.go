package main

import (
	"fmt"
	"log"
	"net/http"
	"os/exec"
	"runtime"

	"agnishopbjm/api/handler"
)

func main() {
	// === Serve file statis ===
	fs := http.FileServer(http.Dir("./public"))
	http.Handle("/", fs)

	// === API routes ===
	http.HandleFunc("/api/get-items", handler.Handler)
	http.HandleFunc("/api/auth/shopee", authShopeeHandler) // 🟢 Tambahan route auth Shopee

	url := "http://localhost:8080/dashboard.html"
	fmt.Println("🚀 Server berjalan di", url)
	openBrowser(url)

	log.Fatal(http.ListenAndServe(":8080", nil))
}

// === Handler Auth Shopee ===
func authShopeeHandler(w http.ResponseWriter, r *http.Request) {
	// Redirect ke halaman auth Shopee resmi
	clientID := "YOUR_SHOPEE_PARTNER_ID"
	redirectURL := "http://localhost:8080/api/auth/callback/shopee"
	authURL := fmt.Sprintf("https://partner.shopeemobile.com/api/v2/shop/auth_partner?partner_id=%s&redirect=%s", clientID, redirectURL)

	http.Redirect(w, r, authURL, http.StatusFound)
}

// === Buka browser otomatis ===
func openBrowser(url string) {
	var err error
	switch runtime.GOOS {
	case "windows":
		err = exec.Command("rundll32", "url.dll,FileProtocolHandler", url).Start()
	case "linux":
		err = exec.Command("xdg-open", url).Start()
	case "darwin":
		err = exec.Command("open", url).Start()
	default:
		fmt.Println("❌ Tidak bisa membuka browser otomatis di OS ini.")
	}
	if err != nil {
		fmt.Println("❌ Gagal membuka browser:", err)
	}
}

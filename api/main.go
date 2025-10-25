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
	// === Serve file statis (HTML, CSS, JS, gambar, dll) ===
	fs := http.FileServer(http.Dir("./public"))
	http.Handle("/", fs)

	// === API route ===
	http.HandleFunc("/api/handler/get-items", handler.Handler)
	// http.HandleFunc("/api/get-token", handler.GetTokenHandler) // kalau kamu punya handler token

	// === Jalankan server ===
	url := "http://localhost:8080/stok-shopee.html"
	fmt.Println("🚀 Server berjalan di", url)

	// === Otomatis buka browser ===
	openBrowser(url)

	log.Fatal(http.ListenAndServe(":8080", nil))
}

// Fungsi untuk buka browser otomatis
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

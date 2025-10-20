package main

import (
	"fmt"
	"log"
	"net/http"

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
	fmt.Println("🚀 Server berjalan di http://localhost:8080")
	log.Fatal(http.ListenAndServe(":8080", nil))
}

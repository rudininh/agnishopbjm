package main

import (
	"log"
	"net/http"

	"agnishopbjm/api/handler"
)

func main() {
	http.HandleFunc("/get-items", handler.Handler)
	log.Println("Server berjalan di http://localhost:8080/get-items")
	log.Fatal(http.ListenAndServe(":8080", nil))
}

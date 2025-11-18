package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"tiktokshop/open/sdk_golang/apis"
	"time"
)

var (
	appKey      = "6i1cagd9f0p83"
	appSecret   = "3710881f177a1e6b03664cc91d8a3516001a0bc7"
	accessToken = "ROW_fEm1uwAAAADzg8qrc-oxg3vJ6aa81jlxT3PGJjiOXRP-TS7K6Yf32hJjIiw5XGhkGfBm7Ohs2HrD2jQoDVYlWVmqzD8WVcb18J1kK4Htsn0j_xGyfX1UGJ5O39hhRXrCPmNTq4nXbVEYmrKPpJqj84JeYyXNc_TWrwm0WiRsMOJ4bTUEzMB35w"
)

func GetTiktokShopsHandler(w http.ResponseWriter, r *http.Request) {
	// ========== Ambil data dari TikTok API ==========
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(appKey, appSecret)
	apiClient := apis.NewAPIClient(configuration)

	req := apiClient.AuthorizationV202309API.Authorization202309ShopsGet(context.Background())
	req = req.XTtsAccessToken(accessToken)
	req = req.ContentType("application/json")

	resp, httpResp, err := req.Execute()
	if err != nil || httpResp.StatusCode != 200 {
		http.Error(w, fmt.Sprintf("TikTok API error: %v", err), http.StatusBadRequest)
		return
	}

	data := resp.GetData()

	// TikTok kadang return data kosong, jadi cek jumlahnya
	if len(data.Shops) == 0 {
		http.Error(w, "No shops returned from TikTok", http.StatusBadRequest)
		return
	}

	// ========== Koneksi Database via ENV ==========
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		http.Error(w, "DATABASE_URL not set", http.StatusInternalServerError)
		return
	}

	db, err := sql.Open("postgres", dbURL)
	if err != nil {
		http.Error(w, "Database connection failed: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer db.Close()

	// Timeout biar serverless tidak hanging
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	// ========== Simpan ke Database ==========
	for _, shop := range data.Shops {
		_, err := db.ExecContext(ctx, `
			INSERT INTO tiktok_shops (id, code, name, region, seller_type, cipher)
			VALUES ($1, $2, $3, $4, $5, $6)
			ON CONFLICT (id) DO UPDATE SET
				code = EXCLUDED.code,
				name = EXCLUDED.name,
				region = EXCLUDED.region,
				seller_type = EXCLUDED.seller_type,
				cipher = EXCLUDED.cipher;
		`,
			shop.Id,
			shop.Code,
			shop.Name,
			shop.Region,
			shop.SellerType,
			shop.Cipher,
		)

		if err != nil {
			http.Error(w, "DB insert error: "+err.Error(), http.StatusInternalServerError)
			return
		}
	}

	// ========== Kirim data ke Dashboard ==========
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(data)
}

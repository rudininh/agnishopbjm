package handler

import (
	"agnishopbjm/tiktok"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"tiktokshop/open/sdk_golang/apis"
	"time"
)

func GetTiktokShopsHandler(w http.ResponseWriter, r *http.Request) {

	// ========== Catch Panic ==========
	defer func() {
		if rec := recover(); rec != nil {
			fmt.Printf("[PANIC] %v\n", rec)
			http.Error(w, "Internal Server Error (panic)", 500)
		}
	}()

	fmt.Println("=== GetTiktokShopsHandler START ===")

	// ========== Load Config ==========
	cfg, err := tiktok.LoadTikTokConfig()
	if err != nil {
		fmt.Println("[CONFIG ERROR]", err)
		http.Error(w, "Config error: "+err.Error(), 500)
		return
	}

	fmt.Println("Config loaded OK")

	// ========== Call TikTok API ==========
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(cfg.AppKey, cfg.AppSecret)
	apiClient := apis.NewAPIClient(configuration)

	req := apiClient.AuthorizationV202309API.Authorization202309ShopsGet(context.Background())
	req = req.XTtsAccessToken(cfg.AccessToken)
	req = req.ContentType("application/json")

	resp, httpResp, err := req.Execute()
	if err != nil {
		fmt.Println("[TIKTOK API ERROR]", err)
		http.Error(w, "TikTok API error: "+err.Error(), 400)
		return
	}

	if httpResp.StatusCode != 200 {
		fmt.Println("[TIKTOK STATUS ERROR]", httpResp.StatusCode)
		http.Error(w, "TikTok API HTTP error", httpResp.StatusCode)
		return
	}

	data := resp.GetData()
	fmt.Println("TikTok API OK, shops:", len(data.Shops))

	if len(data.Shops) == 0 {
		fmt.Println("[EMPTY SHOPS]")
		http.Error(w, "No shops returned from TikTok", 400)
		return
	}

	// ========== Connect DB ==========
	dbURL := os.Getenv("DATABASE_URL")
	if dbURL == "" {
		fmt.Println("[ENV ERROR] DATABASE_URL not set")
		http.Error(w, "DATABASE_URL missing", 500)
		return
	}

	db, err := sql.Open("postgres", dbURL)
	if err != nil {
		fmt.Println("[DB OPEN ERROR]", err)
		http.Error(w, "DB connect error: "+err.Error(), 500)
		return
	}
	defer db.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	// ========== Save shops ==========
	for _, shop := range data.Shops {
		fmt.Println("Saving shop:", shop.Id, shop.Name)

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
			fmt.Println("[DB INSERT ERROR]", err)
			http.Error(w, "DB insert error: "+err.Error(), 500)
			return
		}
	}

	// ========== Return JSON ==========
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(data)

	fmt.Println("=== GetTiktokShopsHandler END ===")
}

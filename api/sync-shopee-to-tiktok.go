package handler

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"

	"agnishopbjm/tiktok"
)

// =======================
// ===== DB STRUCT =======
// =======================

type SyncRow struct {
	TikTokProductID string
	TikTokSKU       string
	ShopeeModelID   string
	Stock           int64
}

// =======================
// ===== REQUEST BODY ====
// =======================

type InventoryUpdateRequest struct {
	Skus []InventorySKU `json:"skus"`
}

type InventorySKU struct {
	ID        string               `json:"id"`
	Inventory []InventoryWarehouse `json:"inventory"`
}

type InventoryWarehouse struct {
	Quantity int64 `json:"quantity"`
}

// =======================================
// ===== HTTP HANDLER =====================
// =======================================

func SyncShopeeStockToTikTokHandler(w http.ResponseWriter, r *http.Request) {

	ctx := context.Background()

	// ===== LOAD CONFIG (FIXED) =====
	cfg, err := tiktok.LoadTikTokConfig()
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}

	// ===== DB =====
	dbConn, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, "DB error: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer dbConn.Close(ctx)

	// ===== QUERY DATA =====
	rows, err := dbConn.Query(ctx, `
		SELECT
			sm.tiktok_product_id,
			sm.tiktok_sku,
			sm.shopee_model_id,
			spm.stock
		FROM sku_mapping sm
		JOIN shopee_product_model spm
			ON spm.model_id = sm.shopee_model_id
		WHERE
			sm.tiktok_product_id IS NOT NULL
			AND sm.tiktok_sku IS NOT NULL
			AND sm.shopee_item_id IS NOT NULL
			AND sm.shopee_model_id IS NOT NULL
	`)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	defer rows.Close()

	success := 0
	failed := 0
	results := make([]map[string]interface{}, 0)

	for rows.Next() {

		var row SyncRow
		if err := rows.Scan(
			&row.TikTokProductID,
			&row.TikTokSKU,
			&row.ShopeeModelID,
			&row.Stock,
		); err != nil {
			failed++
			continue
		}

		err = updateTikTokInventory(
			ctx,
			cfg,
			row.TikTokProductID,
			row.TikTokSKU,
			row.Stock,
		)

		if err != nil {
			failed++
			results = append(results, map[string]interface{}{
				"product_id": row.TikTokProductID,
				"sku":        row.TikTokSKU,
				"stock":      row.Stock,
				"status":     "FAILED",
				"error":      err.Error(),
			})
		} else {
			success++
			results = append(results, map[string]interface{}{
				"product_id": row.TikTokProductID,
				"sku":        row.TikTokSKU,
				"stock":      row.Stock,
				"status":     "SUCCESS",
			})
		}

		time.Sleep(150 * time.Millisecond) // avoid rate limit
	}

	// ===== RESPONSE =====
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"success": success,
		"failed":  failed,
		"items":   results,
	})
}

// =======================================
// ===== CORE TIKTOK UPDATE FUNCTION ======
// =======================================

func updateTikTokInventory(
	ctx context.Context,
	cfg interface{}, // <- TIDAK tergantung tiktok.Config
	productID string,
	skuID string,
	quantity int64,
) error {

	// type assertion aman (jika struct private/public)
	conf := cfg.(interface {
		GetAccessToken() string
		GetAppKey() string
		GetAppSecret() string
		GetCipher() string
		GetShopID() string
	})

	payload := InventoryUpdateRequest{
		Skus: []InventorySKU{
			{
				ID: skuID,
				Inventory: []InventoryWarehouse{
					{Quantity: quantity},
				},
			},
		},
	}

	bodyBytes, _ := json.Marshal(payload)

	baseURL := fmt.Sprintf(
		"https://open-api.tiktokglobalshop.com/product/202309/products/%s/inventory/update",
		productID,
	)

	u, _ := url.Parse(baseURL)
	q := u.Query()
	q.Set("access_token", conf.GetAccessToken())
	q.Set("app_key", conf.GetAppKey())
	q.Set("shop_cipher", conf.GetCipher())
	q.Set("shop_id", conf.GetShopID())
	q.Set("version", "202309")
	q.Set("timestamp", fmt.Sprintf("%d", time.Now().Unix()))
	u.RawQuery = q.Encode()

	req, _ := http.NewRequestWithContext(
		ctx,
		http.MethodPost,
		u.String(),
		bytes.NewBuffer(bodyBytes),
	)

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("x-tts-access-token", conf.GetAccessToken())

	// ===== SIGN =====
	sign := tiktok.CalSign(req, conf.GetAppSecret())
	q.Set("sign", sign)
	u.RawQuery = q.Encode()
	req.URL = u

	client := &http.Client{Timeout: 20 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(resp.Body)

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("http %d: %s", resp.StatusCode, string(raw))
	}

	var result map[string]interface{}
	_ = json.Unmarshal(raw, &result)

	if code, ok := result["code"].(float64); ok && code != 0 {
		return fmt.Errorf("tiktok error: %v", result["message"])
	}

	return nil
}

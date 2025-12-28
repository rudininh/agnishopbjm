package handler

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"agnishopbjm/tiktok"
)

// =======================
// ===== DB STRUCT =======
// =======================

type SyncRow struct {
	TikTokProductID string
	TikTokSKU       string
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
	WarehouseID string `json:"warehouse_id"`
	Quantity    int64  `json:"quantity"`
}

// =======================================
// ===== HTTP HANDLER =====================
// =======================================

func SyncShopeeStockToTikTokHandler(w http.ResponseWriter, r *http.Request) {

	defer func() {
		if rec := recover(); rec != nil {
			log.Println("[PANIC]", rec)
			http.Error(w, "Internal Server Error", 500)
		}
	}()

	ctx := context.Background()
	log.Println("=== SyncShopeeStockToTikTokHandler START ===")

	// ===== LOAD CONFIG =====
	cfg, err := tiktok.LoadTikTokConfig()
	if err != nil {
		http.Error(w, err.Error(), 500)
		return
	}

	// ===== DB =====
	dbConn, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, err.Error(), 500)
		return
	}
	defer dbConn.Close(ctx)

	// ❗ FIX SQL: TOLAK STRING KOSONG
	rows, err := dbConn.Query(ctx, `
		SELECT
			sm.tiktok_product_id,
			sm.tiktok_sku,
			spm.stock
		FROM sku_mapping sm
		JOIN shopee_product_model spm
			ON spm.model_id = sm.shopee_model_id
		WHERE
			NULLIF(sm.tiktok_product_id, '') IS NOT NULL
			AND NULLIF(sm.tiktok_sku, '') IS NOT NULL
	`)
	if err != nil {
		http.Error(w, err.Error(), 500)
		return
	}
	defer rows.Close()

	success := 0
	failed := 0
	skipped := 0

	for rows.Next() {

		var row SyncRow
		if err := rows.Scan(
			&row.TikTokProductID,
			&row.TikTokSKU,
			&row.Stock,
		); err != nil {
			failed++
			continue
		}

		// ===== GUARD WAJIB (ANTI INVALID PATH) =====
		row.TikTokProductID = strings.TrimSpace(row.TikTokProductID)
		row.TikTokSKU = strings.TrimSpace(row.TikTokSKU)

		if row.TikTokProductID == "" || row.TikTokSKU == "" {
			log.Printf("[SKIP] empty product/sku product=%q sku=%q",
				row.TikTokProductID, row.TikTokSKU)
			skipped++
			continue
		}

		err := updateTikTokProductInventory(
			ctx,
			cfg,
			row.TikTokProductID,
			row.TikTokSKU,
			row.Stock,
		)

		if err != nil {
			log.Printf(
				"[FAILED] product=%s sku=%s err=%v",
				row.TikTokProductID,
				row.TikTokSKU,
				err,
			)
			failed++
		} else {
			success++
		}

		time.Sleep(120 * time.Millisecond)
	}

	log.Printf(
		"=== FINISHED | success=%d failed=%d skipped=%d ===",
		success, failed, skipped,
	)

	_ = json.NewEncoder(w).Encode(map[string]int{
		"success": success,
		"failed":  failed,
		"skipped": skipped,
	})
}

// =======================================
// ===== CORE INVENTORY UPDATE ============
// =======================================

func updateTikTokProductInventory(
	ctx context.Context,
	cfg *tiktok.TikTokConfig,
	productID string,
	skuID string,
	quantity int64,
) error {

	if cfg == nil {
		return fmt.Errorf("config nil")
	}

	payload := InventoryUpdateRequest{
		Skus: []InventorySKU{
			{
				ID: skuID,
				Inventory: []InventoryWarehouse{
					{
						WarehouseID: cfg.WarehouseID, // FIXED ID
						Quantity:    quantity,
					},
				},
			},
		},
	}

	body, _ := json.Marshal(payload)

	// ❗ BASE URL TIDAK DIUBAH
	baseURL := fmt.Sprintf(
		"https://open-api.tiktokglobalshop.com/product/202309/products/%s/inventory/update",
		productID,
	)

	reqURL, _ := url.Parse(baseURL)
	q := reqURL.Query()
	q.Set("app_key", cfg.AppKey)
	q.Set("shop_cipher", cfg.Cipher)
	q.Set("shop_id", cfg.ShopID)
	q.Set("timestamp", strconv.FormatInt(time.Now().Unix(), 10))
	q.Set("version", "202309")
	reqURL.RawQuery = q.Encode()

	req, _ := http.NewRequestWithContext(
		ctx,
		http.MethodPost,
		reqURL.String(),
		bytes.NewBuffer(body),
	)

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("x-tts-access-token", cfg.AccessToken)

	// ✅ SIGN REQUEST (BENAR)
	sign := tiktok.CalSign(req, cfg.AppSecret)
	q.Set("sign", sign)
	reqURL.RawQuery = q.Encode()
	req.URL = reqURL

	client := &http.Client{Timeout: 20 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(resp.Body)
	log.Printf(
		"TikTok SKU[%s] Product[%s] [%d]: %s",
		skuID, productID, resp.StatusCode, raw,
	)

	if resp.StatusCode != 200 {
		return fmt.Errorf("http %d: %s", resp.StatusCode, raw)
	}

	var res map[string]interface{}
	_ = json.Unmarshal(raw, &res)

	if code, ok := res["code"].(float64); ok && code != 0 {
		return fmt.Errorf("tiktok error: %v", res["message"])
	}

	return nil
}

package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/http"
)

// =========================================
//  STRUCT RESPONSE
// =========================================

type StockMasterRow struct {
	ID              int64  `json:"id"`
	InternalSKU     string `json:"internal_sku"`
	ProductIDShopee string `json:"product_id_shopee"`
	ProductIDTikTok string `json:"product_id_tiktok"`
	ProductName     string `json:"product_name"`
	VariantName     string `json:"variant_name"`
	StockQty        int64  `json:"stock_qty"`
	TikTokSKU       string `json:"tiktok_sku"`
	StatusTikTok    string `json:"status_tiktok"`
	UpdatedAt       string `json:"updated_at"`
}

// =========================================
//  HANDLER: /api/get-stock-master
// =========================================

func StockMasterHandler(w http.ResponseWriter, r *http.Request) {

	w.Header().Set("Content-Type", "application/json")
	ctx := context.Background()

	// OPEN DB CONNECTION
	db, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error": "%v"}`, err), http.StatusInternalServerError)
		return
	}
	defer db.Close(ctx)

	// ============================================================
	// SQL: JOIN Shopee stock_master dengan TikTok (FULL MATCHING)
	// ============================================================
	rows, err := db.Query(ctx, `
        SELECT 
            sm.id,
            sm.internal_sku,
            sm.product_id_shopee,
            sm.product_id_tiktok,
            sm.product_name,
            sm.variant_name,
            sm.stock_qty,
            sm.updated_at::text,

            tp.product_name AS tiktok_product_name,
            tp.sku_name AS tiktok_variant_name,

            CASE
                WHEN tp.product_name IS NOT NULL AND tp.sku_name IS NOT NULL THEN 'MATCH'
                WHEN tp.product_name IS NOT NULL AND tp.sku_name IS NULL THEN 'VARIANT MISSING'
                WHEN tp.product_name IS NULL THEN 'PRODUCT MISSING'
                ELSE 'UNKNOWN'
            END AS status_tiktok

        FROM stock_master sm
        LEFT JOIN tiktok_products tp
            ON LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
           AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
        ORDER BY sm.product_name, sm.variant_name
    `)

	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error": "Gagal query: %v"}`, err), http.StatusInternalServerError)
		return
	}

	list := []StockMasterRow{}

	for rows.Next() {

		var row StockMasterRow
		var tiktokProductName sql.NullString
		var tiktokVariantName sql.NullString
		var statusTikTok sql.NullString

		err := rows.Scan(
			&row.ID,
			&row.InternalSKU,
			&row.ProductIDShopee,
			&row.ProductIDTikTok,
			&row.ProductName,
			&row.VariantName,
			&row.StockQty,
			&row.UpdatedAt,

			&tiktokProductName,
			&tiktokVariantName,
			&statusTikTok,
		)

		if err != nil {
			fmt.Println("Scan error:", err)
			continue
		}

		// ASSIGN NULL-SAFE VALUES
		row.TikTokSKU = tiktokVariantName.String
		row.StatusTikTok = statusTikTok.String

		list = append(list, row)
	}

	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": list,
	})
}

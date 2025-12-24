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

type StockMasterSummary struct {
	TotalMatch          int64 `json:"total_match"`
	TotalVariantMissing int64 `json:"total_variant_missing"`
	TotalProductMissing int64 `json:"total_product_missing"`
	TotalAll            int64 `json:"total_all"`
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
	// 1️⃣ SUMMARY COUNTER
	// ============================================================
	var summary StockMasterSummary

	err = db.QueryRow(ctx, `
		SELECT
			COUNT(*) FILTER (WHERE tp.product_name IS NOT NULL AND tp.sku_name IS NOT NULL) AS total_match,
			COUNT(*) FILTER (WHERE tp.product_name IS NOT NULL AND tp.sku_name IS NULL)     AS total_variant_missing,
			COUNT(*) FILTER (WHERE tp.product_name IS NULL)                                 AS total_product_missing,
			COUNT(*)                                                                        AS total_all
		FROM stock_master sm
		LEFT JOIN tiktok_products tp
			ON LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
	`).Scan(
		&summary.TotalMatch,
		&summary.TotalVariantMissing,
		&summary.TotalProductMissing,
		&summary.TotalAll,
	)

	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error": "Gagal ambil summary: %v"}`, err), http.StatusInternalServerError)
		return
	}

	// ============================================================
	// 2️⃣ DATA LIST (MATCH DI ATAS)
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

			tp.sku_name AS tiktok_sku,

			CASE
				WHEN tp.product_name IS NOT NULL AND tp.sku_name IS NOT NULL THEN 'MATCH'
				WHEN tp.product_name IS NOT NULL AND tp.sku_name IS NULL THEN 'VARIANT MISSING'
				WHEN tp.product_name IS NULL THEN 'PRODUCT MISSING'
				ELSE 'UNKNOWN'
			END AS status_tiktok,

			CASE
				WHEN tp.product_name IS NOT NULL AND tp.sku_name IS NOT NULL THEN 1
				WHEN tp.product_name IS NOT NULL AND tp.sku_name IS NULL THEN 2
				WHEN tp.product_name IS NULL THEN 3
				ELSE 4
			END AS status_order

		FROM stock_master sm
		LEFT JOIN tiktok_products tp
			ON LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
		   AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))

		ORDER BY
			status_order,
			sm.product_name,
			sm.variant_name
	`)

	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error": "Gagal query data: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer rows.Close()

	list := []StockMasterRow{}

	for rows.Next() {

		var row StockMasterRow
		var tiktokSKU sql.NullString
		var statusTikTok string
		var statusOrder int

		err := rows.Scan(
			&row.ID,
			&row.InternalSKU,
			&row.ProductIDShopee,
			&row.ProductIDTikTok,
			&row.ProductName,
			&row.VariantName,
			&row.StockQty,
			&row.UpdatedAt,

			&tiktokSKU,
			&statusTikTok,
			&statusOrder,
		)

		if err != nil {
			fmt.Println("Scan error:", err)
			continue
		}

		row.TikTokSKU = tiktokSKU.String
		row.StatusTikTok = statusTikTok

		list = append(list, row)
	}

	// ============================================================
	// 3️⃣ RESPONSE FINAL
	// ============================================================
	json.NewEncoder(w).Encode(map[string]interface{}{
		"summary": summary,
		"items":   list,
	})
}

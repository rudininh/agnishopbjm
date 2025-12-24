package handler

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/http"
)

// =========================================
// STRUCT RESPONSE
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
// HANDLER
// =========================================

func StockMasterHandler(w http.ResponseWriter, r *http.Request) {

	w.Header().Set("Content-Type", "application/json")
	ctx := context.Background()

	db, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	defer db.Close(ctx)

	// ============================================================
	// 1️⃣ SUMMARY (ANTI DUPLIKASI — PALING PENTING)
	// ============================================================
	var summary StockMasterSummary

	err = db.QueryRow(ctx, `
		SELECT
			COUNT(*) FILTER (
				WHERE EXISTS (
					SELECT 1 FROM tiktok_products tp
					WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
					  AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
				)
			) AS total_match,

			COUNT(*) FILTER (
				WHERE EXISTS (
					SELECT 1 FROM tiktok_products tp
					WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
				)
				AND NOT EXISTS (
					SELECT 1 FROM tiktok_products tp
					WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
					  AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
				)
			) AS total_variant_missing,

			COUNT(*) FILTER (
				WHERE NOT EXISTS (
					SELECT 1 FROM tiktok_products tp
					WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
				)
			) AS total_product_missing,

			COUNT(*) AS total_all

		FROM stock_master sm
	`).Scan(
		&summary.TotalMatch,
		&summary.TotalVariantMissing,
		&summary.TotalProductMissing,
		&summary.TotalAll,
	)

	if err != nil {
		http.Error(w, fmt.Sprintf("Summary error: %v", err), http.StatusInternalServerError)
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
				WHEN tp.sku_name IS NOT NULL THEN 'MATCH'
				WHEN EXISTS (
					SELECT 1 FROM tiktok_products tpx
					WHERE LOWER(TRIM(tpx.product_name)) = LOWER(TRIM(sm.product_name))
				) THEN 'VARIANT MISSING'
				ELSE 'PRODUCT MISSING'
			END AS status_tiktok,

			CASE
				WHEN tp.sku_name IS NOT NULL THEN 1
				WHEN EXISTS (
					SELECT 1 FROM tiktok_products tpx
					WHERE LOWER(TRIM(tpx.product_name)) = LOWER(TRIM(sm.product_name))
				) THEN 2
				ELSE 3
			END AS status_order

		FROM stock_master sm
		LEFT JOIN tiktok_products tp
			ON LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
		   AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))

		ORDER BY status_order, sm.product_name, sm.variant_name
	`)

	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	defer rows.Close()

	items := []StockMasterRow{}

	for rows.Next() {
		var row StockMasterRow
		var tiktokSKU sql.NullString

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
			&row.StatusTikTok,
			new(int),
		)

		if err != nil {
			fmt.Println("Scan error:", err)
			continue
		}

		row.TikTokSKU = tiktokSKU.String
		items = append(items, row)
	}

	// ============================================================
	// 3️⃣ RESPONSE
	// ============================================================
	json.NewEncoder(w).Encode(map[string]interface{}{
		"summary": summary,
		"items":   items,
	})
}

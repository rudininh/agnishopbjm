package handler

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
)

// =========================================
// STRUCT RESPONSE (FINAL)
// =========================================

type StockMasterRow struct {
	ID           int64  `json:"id"`
	ProductName  string `json:"product_name"`
	VariantName  string `json:"variant_name"`
	StockShopee  int64  `json:"stock_shopee"`
	StockTikTok  int64  `json:"stock_tiktok"`
	StatusTikTok string `json:"status_tiktok"`
	UpdatedAt    string `json:"updated_at"`
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
	// 1️⃣ SUMMARY (AKURAT = JUMLAH stock_master)
	// ============================================================
	var summary StockMasterSummary

	err = db.QueryRow(ctx, `
		SELECT
			COUNT(*) FILTER (
				WHERE EXISTS (
					SELECT 1
					FROM tiktok_products tp
					WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
					  AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
				)
			) AS total_match,

			COUNT(*) FILTER (
				WHERE EXISTS (
					SELECT 1
					FROM tiktok_products tp
					WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
				)
				AND NOT EXISTS (
					SELECT 1
					FROM tiktok_products tp
					WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
					  AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
				)
			) AS total_variant_missing,

			COUNT(*) FILTER (
				WHERE NOT EXISTS (
					SELECT 1
					FROM tiktok_products tp
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
			sm.product_name,
			sm.variant_name,
			sm.stock_qty                            AS stock_shopee,
			COALESCE(tp.stock_qty, 0)               AS stock_tiktok,
			sm.updated_at::text,

			CASE
				WHEN tp.sku_name IS NOT NULL THEN 'MATCH'
				WHEN EXISTS (
					SELECT 1
					FROM tiktok_products tpx
					WHERE LOWER(TRIM(tpx.product_name)) = LOWER(TRIM(sm.product_name))
				) THEN 'VARIANT MISSING'
				ELSE 'PRODUCT MISSING'
			END AS status_tiktok,

			CASE
				WHEN tp.sku_name IS NOT NULL THEN 1
				WHEN EXISTS (
					SELECT 1
					FROM tiktok_products tpx
					WHERE LOWER(TRIM(tpx.product_name)) = LOWER(TRIM(sm.product_name))
				) THEN 2
				ELSE 3
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
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	defer rows.Close()

	items := []StockMasterRow{}

	for rows.Next() {
		var row StockMasterRow
		var statusOrder int

		err := rows.Scan(
			&row.ID,
			&row.ProductName,
			&row.VariantName,
			&row.StockShopee,
			&row.StockTikTok,
			&row.UpdatedAt,
			&row.StatusTikTok,
			&statusOrder,
		)

		if err != nil {
			fmt.Println("Scan error:", err)
			continue
		}

		items = append(items, row)
	}

	// ============================================================
	// 3️⃣ RESPONSE FINAL
	// ============================================================
	json.NewEncoder(w).Encode(map[string]interface{}{
		"summary": summary,
		"items":   items,
	})
}

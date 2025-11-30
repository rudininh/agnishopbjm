package handler

import (
	"context"
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
	UpdatedAt       string `json:"updated_at"`
}

// =========================================
//  HANDLER: /api/stock-master
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

	// QUERY STOCK MASTER
	rows, err := db.Query(ctx, `
        SELECT 
            id,
            internal_sku,
            product_id_shopee,
            product_id_tiktok,
            product_name,
            variant_name,
            stock_qty,
            updated_at
        FROM stock_master
        ORDER BY product_name, variant_name
    `)

	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error": "Gagal query: %v"}`, err), http.StatusInternalServerError)
		return
	}

	list := []StockMasterRow{}

	for rows.Next() {
		var row StockMasterRow
		err := rows.Scan(
			&row.ID,
			&row.InternalSKU,
			&row.ProductIDShopee,
			&row.ProductIDTikTok,
			&row.ProductName,
			&row.VariantName,
			&row.StockQty,
			&row.UpdatedAt,
		)
		if err != nil {
			fmt.Println("Scan error:", err)
			continue
		}

		list = append(list, row)
	}

	// SEND JSON
	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": list,
	})
}

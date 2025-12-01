package handler

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"agnishopbjm/tiktok"
)

// Response row for frontend
type SyncResultRow struct {
	InternalSKU string `json:"internal_sku"`
	ProductName string `json:"product_name"`
	VariantName string `json:"variant_name"`
	Result      string `json:"result"`
	Detail      string `json:"detail,omitempty"`
}

// Handler untuk melakukan sync Shopee -> TikTok
func SyncShopeeToTikTokHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	ctx := context.Background()

	// load tiktok config (untuk create API)
	cfg, err := tiktok.LoadTikTokConfig()
	if err != nil {
		http.Error(w, "TikTok config error: "+err.Error(), http.StatusInternalServerError)
		return
	}

	// open db
	db, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, fmt.Sprintf("DB conn error: %v", err), http.StatusInternalServerError)
		return
	}
	defer db.Close(ctx)

	// 1) Ambil daftar varian Shopee yang perlu di-sync.
	//    Kriteria: stock_master.product_id_shopee IS NOT NULL
	//    dan product_id_tiktok IS NULL (belum terkait ke TikTok)
	//    atau sku_mapping tidak punya tiktok_sku mapping
	rows, err := db.Query(ctx, `
		SELECT sm.internal_sku, sm.product_id_shopee, sm.product_name, sm.variant_name, sm.stock_qty
		FROM stock_master sm
		LEFT JOIN sku_mapping smap ON smap.internal_sku = sm.internal_sku
		WHERE sm.product_id_shopee IS NOT NULL
		  AND (sm.product_id_tiktok IS NULL OR smap.tiktok_sku IS NULL)
		ORDER BY sm.product_name, sm.variant_name
	`)
	if err != nil {
		http.Error(w, fmt.Sprintf("Query error: %v", err), http.StatusInternalServerError)
		return
	}
	defer rows.Close()

	results := []SyncResultRow{}

	// Loop tiap varian -> create pada TikTok jika perlu
	for rows.Next() {
		var internalSKU, productIDShopee, productName, variantName string
		var stockQty int64
		if err := rows.Scan(&internalSKU, &productIDShopee, &productName, &variantName, &stockQty); err != nil {
			results = append(results, SyncResultRow{
				InternalSKU: internalSKU,
				ProductName: productName,
				VariantName: variantName,
				Result:      "error",
				Detail:      "scan error: " + err.Error(),
			})
			continue
		}

		// Trim/normalize
		internalSKU = strings.TrimSpace(internalSKU)
		productIDShopee = strings.TrimSpace(productIDShopee)
		variantName = strings.TrimSpace(variantName)
		productName = strings.TrimSpace(productName)

		// Check existing mapping in sku_mapping (again)
		var existingTikTokSKU, existingTikTokProductID sqlNullString
		err = db.QueryRow(ctx, `
			SELECT tiktok_sku, tiktok_product_id FROM sku_mapping WHERE internal_sku = $1
		`, internalSKU).Scan(&existingTikTokSKU, &existingTikTokProductID)

		// If row exists and has tiktok_sku/product_id, we can mark success (or update stock_master)
		if err == nil && existingTikTokProductID.Valid && existingTikTokSKU.Valid {
			// update stock_master.product_id_tiktok if empty
			if _, err := db.Exec(ctx, `
				UPDATE stock_master SET product_id_tiktok = $1, updated_at = NOW() WHERE internal_sku = $2
			`, existingTikTokProductID.String, internalSKU); err != nil {
				// still continue but log
				results = append(results, SyncResultRow{InternalSKU: internalSKU, ProductName: productName, VariantName: variantName, Result: "warning", Detail: "failed update stock_master: " + err.Error()})
			} else {
				results = append(results, SyncResultRow{InternalSKU: internalSKU, ProductName: productName, VariantName: variantName, Result: "skipped", Detail: "already mapped to TikTok SKU: " + existingTikTokSKU.String})
			}
			continue
		}

		// ---- Create variant in TikTok ----
		// TODO: Anda perlu isi payload sesuai API TikTok Anda.
		// Contoh alur:
		//  - Cari product TikTok tujuan (map dari product_id_shopee ke product_id_tiktok jika ada)
		//  - Jika product belum ada di TikTok: Anda bisa skip atau buat product dulu (lebih kompleks)
		//  - Panggil API create variant / model di TikTok untuk product tertentu
		//
		// We'll call a helper (pseudo) function CreateTikTokVariant that returns tiktokSKU and tiktokProductID.
		tiktokSKU, tiktokProductID, apiErr := CreateTikTokVariant(ctx, cfg, productIDShopee, productName, variantName, stockQty)
		if apiErr != nil {
			// gagal membuat varian
			results = append(results, SyncResultRow{
				InternalSKU: internalSKU,
				ProductName: productName,
				VariantName: variantName,
				Result:      "failed",
				Detail:      apiErr.Error(),
			})
			// small delay & continue
			time.Sleep(100 * time.Millisecond)
			continue
		}

		// ---- simpan mapping sku_mapping (update atau insert) ----
		// update
		ct, uerr := db.Exec(ctx, `
			UPDATE sku_mapping
			SET tiktok_sku = $1, tiktok_product_id = $2, updated_at = NOW()
			WHERE internal_sku = $3
		`, tiktokSKU, tiktokProductID, internalSKU)
		if uerr != nil {
			results = append(results, SyncResultRow{InternalSKU: internalSKU, ProductName: productName, VariantName: variantName, Result: "error", Detail: "update mapping error: " + uerr.Error()})
		} else if ct.RowsAffected() == 0 {
			// insert
			_, ierr := db.Exec(ctx, `
				INSERT INTO sku_mapping (tiktok_sku, tiktok_product_id, internal_sku, updated_at)
				VALUES ($1,$2,$3,NOW())
			`, tiktokSKU, tiktokProductID, internalSKU)
			if ierr != nil {
				results = append(results, SyncResultRow{InternalSKU: internalSKU, ProductName: productName, VariantName: variantName, Result: "error", Detail: "insert mapping error: " + ierr.Error()})
			} else {
				results = append(results, SyncResultRow{InternalSKU: internalSKU, ProductName: productName, VariantName: variantName, Result: "ok", Detail: "created and mapped: " + tiktokSKU})
			}
		} else {
			results = append(results, SyncResultRow{InternalSKU: internalSKU, ProductName: productName, VariantName: variantName, Result: "ok", Detail: "updated mapping: " + tiktokSKU})
		}

		// ---- update stock_master.product_id_tiktok and stock value ----
		if _, uerr := db.Exec(ctx, `
			UPDATE stock_master SET product_id_tiktok = $1, stock_qty = $2, updated_at = NOW() WHERE internal_sku = $3
		`, tiktokProductID, stockQty, internalSKU); uerr != nil {
			// non-fatal
			results = append(results, SyncResultRow{InternalSKU: internalSKU, ProductName: productName, VariantName: variantName, Result: "warning", Detail: "update stock_master failed: " + uerr.Error()})
		}

		// small delay to avoid throttle
		time.Sleep(150 * time.Millisecond)
	}

	// Return summary
	json.NewEncoder(w).Encode(map[string]interface{}{
		"rows": results,
	})
}

// --------------------
// Helper & stubs
// --------------------

// sqlNullString simplifies scanning nullable text
type sqlNullString struct {
	String string
	Valid  bool
}

// CreateTikTokVariant : helper stub
// - Anda harus mengganti implementasi ini dengan panggilan API TikTok yang sesuai.
// - Sebagai output kita return (tiktokSKU, tiktokProductID, error)
func CreateTikTokVariant(ctx context.Context, cfg *tiktok.Config, productIDShopee, productName, variantName string, stockQty int64) (string, string, error) {
	// === BEGIN TODO ===
	// Implement actual API call to TikTok to create variant/model for the product.
	// Typical flow:
	// 1) Determine TikTok product id to attach the variant to. (you may maintain mapping Shopee->TikTok product elsewhere)
	// 2) Build request body with variant values (sku, price, inventory)
	// 3) Sign/request via your tiktok SDK or HTTP client
	// 4) Parse response, extract tiktok SKU id and product id
	// For now return stub:
	return "TIKSKU-STUB-" + strings.ReplaceAll(variantName, " ", "-"), "TIKPROD-STUB-" + productIDShopee, nil
	// === END TODO ===
}

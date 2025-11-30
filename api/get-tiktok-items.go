package handler

import (
	"agnishopbjm/tiktok"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"tiktokshop/open/sdk_golang/apis"
	product_v202502 "tiktokshop/open/sdk_golang/models/product/v202502"

	"github.com/jackc/pgx/v5"
)

// ===== Structs =====
type FixedProduct struct {
	ProductID   string      `json:"product_id"`
	ProductName string      `json:"product_name"`
	SKUs        []FixedSKU  `json:"skus"`
	Raw         interface{} `json:"raw,omitempty"`
}

type FixedSKU struct {
	SKUName   string `json:"sku_name"`
	StockQty  int64  `json:"stock_qty"`
	Price     int64  `json:"price"`
	Subtotal  int64  `json:"subtotal"`
	TikTokSKU string `json:"tiktok_sku,omitempty"`
}

// NOTE: getDBConn(ctx) should be defined elsewhere in package (returns *pgx.Conn).
// If not present, add your DB connection helper in this package. This code expects it.

func GetAllProductsHandler(w http.ResponseWriter, r *http.Request) {

	defer func() {
		if rec := recover(); rec != nil {
			fmt.Println("[PANIC]", rec)
			http.Error(w, "Internal Server Error", 500)
		}
	}()

	fmt.Println("=== GetAllProductsHandler START ===")

	cfg, err := tiktok.LoadTikTokConfig()
	if err != nil {
		http.Error(w, "Config error: "+err.Error(), 500)
		return
	}

	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(cfg.AppKey, cfg.AppSecret)
	apiClient := apis.NewAPIClient(configuration)

	ctx := context.Background()

	// open DB connection (expect getDBConn exists in package)
	var dbConn *pgx.Conn
	dbConn, dbErr := getDBConn(ctx)
	if dbErr != nil {
		// If DB not available, we continue but skip DB updates.
		fmt.Println("⚠️ DB connection disabled:", dbErr)
		dbConn = nil
	} else {
		defer func() {
			_ = dbConn.Close(ctx)
		}()
	}

	// ===== SEARCH PRODUCT =====
	searchReq := apiClient.ProductV202502API.Product202502ProductsSearchPost(context.Background())
	searchReq = searchReq.XTtsAccessToken(cfg.AccessToken)
	searchReq = searchReq.ContentType("application/json")
	searchReq = searchReq.PageSize(100)
	searchReq = searchReq.ShopCipher(cfg.Cipher)

	body := product_v202502.NewProduct202502SearchProductsRequestBody()
	body.SetStatus("ALL")
	searchReq = searchReq.Product202502SearchProductsRequestBody(*body)

	searchResp, _, err := searchReq.Execute()
	if err != nil {
		http.Error(w, "Search API error: "+err.Error(), 500)
		return
	}

	if searchResp.GetCode() != 0 {
		http.Error(w, "Search error: "+searchResp.GetMessage(), 500)
		return
	}

	var root map[string]interface{}
	bt, _ := json.Marshal(searchResp.GetData())
	_ = json.Unmarshal(bt, &root)

	arr, ok := root["products"].([]interface{})
	if !ok {
		http.Error(w, "Products list not found", 500)
		return
	}

	results := []FixedProduct{}

	// ===== LOOP DETAIL =====
	for _, item := range arr {

		pm, ok := item.(map[string]interface{})
		if !ok {
			continue
		}

		productID := fmt.Sprintf("%v", pm["id"])
		if productID == "" {
			continue
		}

		fmt.Println("Fetching detail:", productID)

		timestamp := time.Now().Unix()

		// Detail endpoint
		base := fmt.Sprintf("https://open-api.tiktokglobalshop.com/product/202309/products/%s", productID)

		reqURL, _ := url.Parse(base)
		q := reqURL.Query()
		q.Set("timestamp", fmt.Sprintf("%d", timestamp))
		q.Set("app_key", cfg.AppKey)
		q.Set("shop_cipher", cfg.Cipher)
		q.Set("shop_id", cfg.ShopID)
		q.Set("version", "202309")
		reqURL.RawQuery = q.Encode()

		req, _ := http.NewRequest("GET", reqURL.String(), nil)
		req.Header.Set("x-tts-access-token", cfg.AccessToken)
		req.Header.Set("Content-Type", "application/json")

		sign := tiktok.CalSign(req, cfg.AppSecret)
		q.Set("sign", sign)
		reqURL.RawQuery = q.Encode()
		req.URL = reqURL

		client := &http.Client{Timeout: 20 * time.Second}
		resp, err := client.Do(req)
		if err != nil {
			fmt.Printf("❌ fetch detail error product %s: %v\n", productID, err)
			continue
		}

		raw, _ := io.ReadAll(resp.Body)
		resp.Body.Close()

		// Parse detail JSON
		var detail map[string]interface{}
		if err := json.Unmarshal(raw, &detail); err != nil {
			fmt.Printf("⚠️ Failed parse detail for %s: %v\n", productID, err)
			continue
		}

		data, _ := detail["data"].(map[string]interface{})
		if data == nil {
			fmt.Printf("⚠️ No data field for product %s\n", productID)
			continue
		}

		productName := fmt.Sprintf("%v", data["title"])

		skuArr, _ := data["skus"].([]interface{})
		listSKU := []FixedSKU{}

		for _, s := range skuArr {
			sm, ok := s.(map[string]interface{})
			if !ok {
				continue
			}

			// Get tiktok SKU id if present
			tiktokSKU := ""
			if v, ok := sm["id"]; ok {
				tiktokSKU = fmt.Sprintf("%v", v)
			} else if v, ok := sm["sku_id"]; ok {
				tiktokSKU = fmt.Sprintf("%v", v)
			} else if v, ok := sm["seller_sku"]; ok {
				tiktokSKU = fmt.Sprintf("%v", v)
			}

			// variant name from sales_attributes (preferred)
			variantName := ""
			if attrs, ok := sm["sales_attributes"].([]interface{}); ok {
				var names []string
				for _, a := range attrs {
					if amap, ok := a.(map[string]interface{}); ok {
						if v, ok := amap["value_name"].(string); ok && v != "" {
							names = append(names, v)
						} else if v, ok := amap["original_value_name"].(string); ok && v != "" {
							names = append(names, v)
						}
					}
				}
				if len(names) > 0 {
					variantName = strings.Join(names, " / ")
				}
			}
			// fallback names
			if variantName == "" {
				if v, ok := sm["seller_sku"].(string); ok && v != "" {
					variantName = v
				} else if v, ok := sm["sku_name"].(string); ok && v != "" {
					variantName = v
				} else if v, ok := sm["name"].(string); ok && v != "" {
					variantName = v
				} else {
					variantName = "Default Variant"
				}
			}

			// price
			var price int64
			if pmv, ok := sm["price"].(map[string]interface{}); ok {
				price = parseInt(pmv["sale_price"])
				if price == 0 {
					price = parseInt(pmv["tax_exclusive_price"])
				}
			} else {
				price = parseInt(sm["price"])
			}

			// stock
			stockQty := int64(0)
			if stockList, ok := sm["inventory"].([]interface{}); ok && len(stockList) > 0 {
				if firstInv, ok := stockList[0].(map[string]interface{}); ok {
					stockQty = parseInt(firstInv["quantity"])
				}
			} else {
				if v := parseInt(sm["stock"]); v != 0 {
					stockQty = v
				}
			}

			// Build FixedSKU and append
			fsku := FixedSKU{
				SKUName:   variantName,
				StockQty:  stockQty,
				Price:     price,
				Subtotal:  price * stockQty,
				TikTokSKU: tiktokSKU,
			}
			listSKU = append(listSKU, fsku)

			// ---- Option A logic: exact-match update only ----
			if dbConn != nil {
				// 1) Find existing internal SKU in stock_master by exact product_name + variant_name
				var internalSKU string
				err := dbConn.QueryRow(ctx, `
					SELECT internal_sku
					FROM stock_master
					WHERE product_name = $1 AND variant_name = $2
					LIMIT 1
				`, productName, variantName).Scan(&internalSKU)

				if err != nil {
					// No row found or other error -> do not insert anything (Option A)
					if err == pgx.ErrNoRows {
						// exact match not found -> skip update
						fmt.Printf("⏭️ exact match not found for product='%s' variant='%s' -> skipping DB update\n", productName, variantName)
					} else {
						fmt.Printf("⚠️ error querying stock_master for product='%s' variant='%s' : %v\n", productName, variantName, err)
					}
					// continue to next SKU (no DB write)
				} else {
					// found internalSKU -> update stock_master
					ct, err := dbConn.Exec(ctx, `
						UPDATE stock_master
						SET product_id_tiktok = $1, stock_qty = $2, updated_at = NOW()
						WHERE internal_sku = $3
					`, productID, stockQty, internalSKU)

					if err != nil {
						fmt.Printf("❌ stock_master UPDATE error internal_sku=%s : %v\n", internalSKU, err)
					} else {
						fmt.Printf("✅ stock_master updated internal_sku=%s stock=%d (RowsAffected=%d)\n", internalSKU, stockQty, ct.RowsAffected())
					}

					// Update sku_mapping only if mapping row exists (no insert)
					ct2, err2 := dbConn.Exec(ctx, `
						UPDATE sku_mapping
						SET tiktok_sku = $1, tiktok_product_id = $2, updated_at = NOW()
						WHERE internal_sku = $3
					`, tiktokSKU, productID, internalSKU)
					if err2 != nil {
						fmt.Printf("⚠️ sku_mapping UPDATE error internal_sku=%s : %v\n", internalSKU, err2)
					} else {
						if ct2.RowsAffected() == 0 {
							// mapping row doesn't exist -> as Option A we DO NOT INSERT
							fmt.Printf("⏭️ sku_mapping row not found for internal_sku=%s -> skipping insert (Option A)\n", internalSKU)
						} else {
							fmt.Printf("✅ sku_mapping updated internal_sku=%s (tiktok_sku=%s)\n", internalSKU, tiktokSKU)
						}
					}
				}
			}
		} // end for each sku

		product := FixedProduct{
			ProductID:   productID,
			ProductName: productName,
			SKUs:        listSKU,
			Raw:         data,
		}
		results = append(results, product)

		// small delay to avoid rate limit
		time.Sleep(100 * time.Millisecond)
	}

	// Send JSON to frontend
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"items": results,
	})
}

// parseInt reads a few possible types and returns int64
func parseInt(v interface{}) int64 {
	if v == nil {
		return 0
	}
	switch x := v.(type) {
	case float64:
		return int64(x)
	case float32:
		return int64(x)
	case int:
		return int64(x)
	case int64:
		return x
	case json.Number:
		if i, err := x.Int64(); err == nil {
			return i
		}
		if f, err := x.Float64(); err == nil {
			return int64(f)
		}
		return 0
	case string:
		var n int64
		fmt.Sscan(x, &n)
		return n
	default:
		return 0
	}
}

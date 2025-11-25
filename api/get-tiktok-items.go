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
)

// ===== Structs =====
type FixedProduct struct {
	ProductID   string      `json:"product_id"`
	ProductName string      `json:"product_name"`
	SKUs        []FixedSKU  `json:"skus"`
	Raw         interface{} `json:"raw,omitempty"`
}

type FixedSKU struct {
	SKUName  string `json:"sku_name"`
	StockQty int64  `json:"stock_qty"`
	Price    int64  `json:"price"`
	Subtotal int64  `json:"subtotal"`
}

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
	dbConn, dbErr := getDBConn(ctx)
	if dbErr != nil {
		fmt.Println("⚠️ DB connection disabled:", dbErr)
	} else {
		defer dbConn.Close(ctx)
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
	json.Unmarshal(bt, &root)

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

			// variant name
			skuName := ""
			if attrs, ok := sm["sales_attributes"].([]interface{}); ok {
				var names []string
				for _, a := range attrs {
					if amap, ok := a.(map[string]interface{}); ok {
						if v, ok := amap["value_name"].(string); ok && v != "" {
							names = append(names, v)
						}
					}
				}
				if len(names) > 0 {
					skuName = strings.Join(names, " / ")
				}
			}
			if skuName == "" {
				if v, ok := sm["seller_sku"].(string); ok && v != "" {
					skuName = v
				} else if v, ok := sm["sku_name"].(string); ok && v != "" {
					skuName = v
				} else {
					skuName = "Default Variant"
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

			listSKU = append(listSKU, FixedSKU{
				SKUName:  skuName,
				StockQty: stockQty,
				Price:    price,
				Subtotal: price * stockQty,
			})
		}

		product := FixedProduct{
			ProductID:   productID,
			ProductName: productName,
			SKUs:        listSKU,
			Raw:         data,
		}

		results = append(results, product)

		// === SAVE PER SKU (JALUR A) ===
		if dbConn != nil {
			for _, sku := range listSKU {

				_, err := dbConn.Exec(ctx, `
            INSERT INTO tiktok_products
                (product_id, product_name, sku_name, stock_qty, price, subtotal, updated_at)
            VALUES ($1,$2,$3,$4,$5,$6,NOW())
            ON CONFLICT (product_id, sku_name)
            DO UPDATE SET
                product_name = EXCLUDED.product_name,
                stock_qty   = EXCLUDED.stock_qty,
                price       = EXCLUDED.price,
                subtotal    = EXCLUDED.subtotal,
                updated_at  = NOW()
        `, productID, productName, sku.SKUName, sku.StockQty, sku.Price, sku.Subtotal)

				if err != nil {
					fmt.Printf("❌ DB insert failed product %s / SKU %s: %v\n", productID, sku.SKUName, err)
				} else {
					fmt.Printf("✅ DB saved product %s / SKU %s\n", productID, sku.SKUName)
				}
			}
		}

		time.Sleep(100 * time.Millisecond)
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": results,
	})
}

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
	}
	return 0
}

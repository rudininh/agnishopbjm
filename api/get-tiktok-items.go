package handler

import (
	"agnishopbjm/tiktok"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"

	"tiktokshop/open/sdk_golang/apis"
	product_v202502 "tiktokshop/open/sdk_golang/models/product/v202502"
)

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

	// ======================================
	// ============ SEARCH PRODUCT ===========
	// ======================================

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

	// Convert TikTok response → Map
	var root map[string]interface{}
	bt, _ := json.Marshal(searchResp.GetData())
	json.Unmarshal(bt, &root)

	arr, ok := root["products"].([]interface{})
	if !ok {
		http.Error(w, "Products list not found", 500)
		return
	}

	results := []FixedProduct{}

	// ======================================
	// ============= LOOP DETAIL =============
	// ======================================

	for _, item := range arr {

		pm := item.(map[string]interface{})

		productID := fmt.Sprintf("%v", pm["id"])
		fmt.Println("Fetching detail:", productID)

		timestamp := time.Now().Unix()

		// Endpoint V2
		base := fmt.Sprintf(
			"https://open-api.tiktokglobalshop.com/product/202309/products/%s",
			productID,
		)

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

		// SIGN
		sign := tiktok.CalSign(req, cfg.AppSecret)
		q.Set("sign", sign)
		reqURL.RawQuery = q.Encode()
		req.URL = reqURL

		client := &http.Client{Timeout: 20 * time.Second}
		resp, err := client.Do(req)
		if err != nil {
			continue
		}

		raw, _ := io.ReadAll(resp.Body)
		resp.Body.Close()

		// Parse detail
		var detail map[string]interface{}
		json.Unmarshal(raw, &detail)

		// ========== Extract fields ==========
		data, _ := detail["data"].(map[string]interface{})

		productName := fmt.Sprintf("%v", data["title"])

		// SKUs
		skuArr, _ := data["skus"].([]interface{})

		listSKU := []FixedSKU{}

		for _, s := range skuArr {
			sm := s.(map[string]interface{})

			priceMap := sm["price"].(map[string]interface{})
			price := parseInt(priceMap["sale_price"])

			stockList, _ := sm["inventory"].([]interface{})
			stockQty := int64(0)
			if len(stockList) > 0 {
				stockQty = parseInt(stockList[0].(map[string]interface{})["quantity"])
			}

			skuName := fmt.Sprintf("%v", sm["seller_sku"])

			listSKU = append(listSKU, FixedSKU{
				SKUName:  skuName,
				StockQty: stockQty,
				Price:    price,
				Subtotal: price * stockQty,
			})
		}

		// Push
		results = append(results, FixedProduct{
			ProductID:   productID,
			ProductName: productName,
			SKUs:        listSKU,
		})
	}

	// Send JSON to frontend
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": results,
	})
}

func parseInt(v interface{}) int64 {
	switch x := v.(type) {
	case float64:
		return int64(x)
	case string:
		var n int64
		fmt.Sscan(x, &n)
		return n
	}
	return 0
}

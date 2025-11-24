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

type ProductDetailResponse struct {
	ProductID string          `json:"product_id"`
	Detail    json.RawMessage `json:"detail"`
	Error     string          `json:"error,omitempty"`
}

func GetAllProductsHandler(w http.ResponseWriter, r *http.Request) {

	defer func() {
		if rec := recover(); rec != nil {
			fmt.Println("[PANIC]", rec)
			http.Error(w, "Internal Server Error", 500)
		}
	}()

	fmt.Println("=== GetAllProductsHandler START ===")

	// Load config
	cfg, err := tiktok.LoadTikTokConfig()
	if err != nil {
		http.Error(w, "Config error: "+err.Error(), 500)
		return
	}

	// INIT SDK
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(cfg.AppKey, cfg.AppSecret)
	apiClient := apis.NewAPIClient(configuration)

	// ======================================================
	// =============== STEP 1: SEARCH PRODUCTS ===============
	// ======================================================

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

	// === IMPORTANT: GetData() → Convert to Map ===
	data := searchResp.GetData()

	var dataMap map[string]interface{}
	tmp, _ := json.Marshal(data)
	json.Unmarshal(tmp, &dataMap)

	// Extract "products" array
	productsRaw, ok := dataMap["products"].([]interface{})
	if !ok {
		http.Error(w, "Products list not found in TikTok response", 500)
		return
	}

	fmt.Println("Search returned products:", len(productsRaw))

	// ======================================================
	// ======= STEP 2: LOOP CALL GET /DETAILS API ===========
	// ======================================================

	results := []ProductDetailResponse{}

	for _, pr := range productsRaw {

		pm := pr.(map[string]interface{})

		// Product ID bisa beragam: product_id, product_id_str, id...
		productID := ""

		if v, ok := pm["product_id"]; ok {
			productID = fmt.Sprintf("%v", v)
		}
		if v, ok := pm["product_id_str"]; ok {
			productID = fmt.Sprintf("%v", v)
		}
		if productID == "" {
			productID = fmt.Sprintf("%v", pm["id"])
		}

		fmt.Println("Fetching detail for product:", productID)

		// Build timestamp
		timestamp := time.Now().Unix()

		// Base URL
		baseURL := "https://open-api.tiktokglobalshop.com/api/products/details"

		// Build request WITHOUT sign
		reqURL, _ := url.Parse(baseURL)
		q := reqURL.Query()

		q.Set("access_token", cfg.AccessToken)
		q.Set("app_key", cfg.AppKey)
		q.Set("product_id", productID)
		q.Set("shop_cipher", cfg.Cipher)
		q.Set("shop_id", cfg.ShopID)
		q.Set("timestamp", fmt.Sprintf("%d", timestamp))
		q.Set("version", "202306")

		reqURL.RawQuery = q.Encode()

		// Build request object (needed for CalSign)
		req, _ := http.NewRequest("GET", reqURL.String(), nil)
		req.Header.Set("x-tts-access-token", cfg.AccessToken)

		// === HITUNG SIGN DI SINI ===
		sign := tiktok.CalSign(req, cfg.AppSecret)

		// Tambahkan sign ke query
		q.Set("sign", sign)
		reqURL.RawQuery = q.Encode()

		// Replace req.URL with new signed URL
		req.URL = reqURL

		fmt.Println("DETAIL URL SIGNED:", req.URL.String())

		client := &http.Client{Timeout: 15 * time.Second}
		resp, err := client.Do(req)

		if err != nil {
			results = append(results, ProductDetailResponse{
				ProductID: productID,
				Error:     "HTTP Request Error: " + err.Error(),
			})
			continue
		}

		raw, _ := io.ReadAll(resp.Body)
		resp.Body.Close()

		// LOG RAW RESPONSE
		fmt.Println("RAW DETAIL RESPONSE for", productID, ":")
		fmt.Println(string(raw))

		// Cek jika TikTok error
		var detailCheck map[string]interface{}
		json.Unmarshal(raw, &detailCheck)
		if code, ok := detailCheck["code"].(float64); ok && code != 0 {
			msg, _ := detailCheck["message"].(string)
			fmt.Printf("API ERROR for %s → Code: %.0f, Msg: %s\n", productID, code, msg)
		}

		if resp.StatusCode != 200 {
			results = append(results, ProductDetailResponse{
				ProductID: productID,
				Error:     fmt.Sprintf("HTTP Status %d", resp.StatusCode),
			})
			continue
		}

		results = append(results, ProductDetailResponse{
			ProductID: productID,
			Detail:    raw,
		})
	}
}

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
	// ========== STEP 2: LOOP CALL GET /DETAILS API =========
	// ======================================================

	results := []ProductDetailResponse{}

	for _, pr := range productsRaw {

		pm := pr.(map[string]interface{})

		// ==========================================
		// Ambil Product ID (lebih aman & fleksibel)
		// ==========================================
		productID := ""
		if v, ok := pm["product_id"]; ok {
			productID = fmt.Sprintf("%v", v)
		}
		if v, ok := pm["product_id_str"]; ok && fmt.Sprintf("%v", v) != "" {
			productID = fmt.Sprintf("%v", v)
		}
		if productID == "" {
			if v, ok := pm["id"]; ok {
				productID = fmt.Sprintf("%v", v)
			}
		}

		fmt.Println("Fetching detail V2 for product:", productID)

		timestamp := time.Now().Unix()

		// ==========================================
		// ENDPOINT V2
		// ==========================================
		baseURL := fmt.Sprintf(
			"https://open-api.tiktokglobalshop.com/product/202309/products/%s",
			productID,
		)

		reqURL, _ := url.Parse(baseURL)
		q := reqURL.Query()

		// sesuai cURL terbaru TikTok
		q.Set("timestamp", fmt.Sprintf("%d", timestamp))
		q.Set("app_key", cfg.AppKey)
		q.Set("shop_cipher", cfg.Cipher)
		q.Set("shop_id", cfg.ShopID)
		q.Set("version", "202309")

		reqURL.RawQuery = q.Encode()

		req, _ := http.NewRequest("GET", reqURL.String(), nil)

		// V2 WAJIB pakai access token di header
		req.Header.Set("x-tts-access-token", cfg.AccessToken)
		req.Header.Set("Content-Type", "application/json")

		// SIGN • penting
		sign := tiktok.CalSign(req, cfg.AppSecret)
		q.Set("sign", sign)
		reqURL.RawQuery = q.Encode()
		req.URL = reqURL

		fmt.Println("SIGNED V2 URL:", req.URL.String())

		client := &http.Client{Timeout: 20 * time.Second}
		resp, err := client.Do(req)

		if err != nil {
			results = append(results, ProductDetailResponse{
				ProductID: productID,
				Error:     err.Error(),
			})
			continue
		}

		raw, _ := io.ReadAll(resp.Body)
		resp.Body.Close()

		fmt.Println("RAW RESPONSE:", string(raw))

		results = append(results, ProductDetailResponse{
			ProductID: productID,
			Detail:    raw,
		})
	}

	// ======================================================
	// =============== RETURN JSON KE FRONTEND ===============
	// ======================================================
	fmt.Println("=== Mengirim hasil JSON ke frontend ===")

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)

	err = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":  "success",
		"total":   len(results),
		"results": results,
	})
	if err != nil {
		fmt.Println("Encode error:", err)
	}
}

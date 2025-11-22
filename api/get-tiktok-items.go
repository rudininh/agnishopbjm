package handler

import (
	"agnishopbjm/tiktok"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"tiktokshop/open/sdk_golang/apis"
	product_v202502 "tiktokshop/open/sdk_golang/models/product/v202502"
)

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

	// 🔍 DEBUG: Print request search
	fmt.Println("=== SEARCH REQUEST DEBUG ===")
	fmt.Println("AccessToken:", cfg.AccessToken)
	fmt.Println("ShopCipher:", cfg.Cipher)
	bb, _ := json.MarshalIndent(body, "", "  ")
	fmt.Println("RequestBody:", string(bb))

	searchResp, respHTTP, err := searchReq.Execute()
	if err != nil {
		fmt.Println("[Search Execute Error]", err)
		http.Error(w, "Search API error", 500)
		return
	}

	fmt.Println("Search HTTP Status:", respHTTP.StatusCode)

	if searchResp.GetCode() != 0 {
		fmt.Println("[Search API Error]", searchResp.GetCode(), searchResp.GetMessage())
		http.Error(w, "Search error: "+searchResp.GetMessage(), 500)
		return
	}

	searchData := searchResp.GetData()
	products := searchData.GetProducts()

	fmt.Println("Search returned products:", len(products))

	if len(products) == 0 {
		http.Error(w, "No products found", 404)
		return
	}

	// ======================================================
	// ========== STEP 2: LOOP GET DETAIL PER PRODUCT =======
	// ======================================================

	details := []interface{}{}

	for _, p := range products {
		productID := p.GetId()

		fmt.Println("=========================================")
		fmt.Println("Fetching detail for:", productID)
		fmt.Println("=========================================")

		// GUNAKAN API V202502 (bukan 202309)
		getReq := apiClient.ProductV202309API.Product202309ProductsProductIdGet(
			context.Background(),
		)

		getReq = getReq.XTtsAccessToken(cfg.AccessToken)
		getReq = getReq.ContentType("application/json")
		getReq = getReq.ProductId(productID)
		getReq = getReq.ShopCipher(cfg.Cipher)
		getReq = getReq.Locale("en")

		fmt.Println("=== DETAIL REQUEST DEBUG ===")
		fmt.Println("ProductID:", productID)
		fmt.Println("ShopCipher:", cfg.Cipher)
		fmt.Println("AccessToken:", cfg.AccessToken)

		getResp, getHTTP, err := getReq.Execute()
		if err != nil {
			fmt.Println("[Detail Execute Error]", err)
			continue
		}

		fmt.Println("Detail HTTP Status:", getHTTP.StatusCode)

		if getResp.GetCode() != 0 {
			fmt.Println("[Detail Error]", getResp.GetCode(), getResp.GetMessage())
			continue
		}

		details = append(details, getResp.GetData())
	}

	// ======================================================
	// ============== RETURN JSON KE KLIENT ===============
	// ======================================================

	output := map[string]interface{}{
		"total_products": len(details),
		"products":       details,
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(output)

	fmt.Println("=== GetAllProductsHandler END ===")
}

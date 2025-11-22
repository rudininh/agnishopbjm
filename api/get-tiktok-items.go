package handler

import (
	"agnishopbjm/tiktok"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"tiktokshop/open/sdk_golang/apis"
)

func GetTiktokProductHandler(w http.ResponseWriter, r *http.Request) {

	// ========== Catch Panic ==========
	defer func() {
		if rec := recover(); rec != nil {
			fmt.Printf("[PANIC] %v\n", rec)
			http.Error(w, "Internal Server Error (panic)", 500)
		}
	}()

	fmt.Println("=== GetTiktokShopsHandler START ===")

	// ========== Load Config ==========
	cfg, err := tiktok.LoadTikTokConfig()
	if err != nil {
		fmt.Println("[CONFIG ERROR]", err)
		http.Error(w, "Config error: "+err.Error(), 500)
		return
	}

	fmt.Println("Config loaded OK")

	// ========== Call TikTok API ==========
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(cfg.AppKey, cfg.AppSecret)
	apiClient := apis.NewAPIClient(configuration)
	request := apiClient.ProductV202309API.Product202309ProductsProductIdGet(context.Background(), "1733288272623863742")
	request = request.XTtsAccessToken(cfg.AccessToken)
	request = request.ContentType("application/json")
	request = request.ReturnUnderReviewVersion(true)
	request = request.ReturnDraftVersion(true)
	request = request.Locale("en")
	request = request.ShopCipher(cfg.Cipher)
	resp, httpResp, err := request.Execute()

	if err != nil || httpResp.StatusCode != 200 {
		fmt.Printf("productsRequest err:%v resbody:%s", err, httpResp.Body)
		return
	}
	if resp == nil {
		fmt.Printf("response is nil")
		return
	}
	if resp.GetCode() != 0 {
		fmt.Printf("response business is error, errorCode:%d errorMessage:%s", resp.GetCode(), resp.GetMessage())
		return
	}
	respDataJson, _ := json.MarshalIndent(resp.GetData(), "", "  ")
	fmt.Println("response data:", string(respDataJson))
	return

	// ========== Return JSON ==========
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(respDataJson)

	fmt.Println("=== GetTiktokShopsHandler END ===")
}

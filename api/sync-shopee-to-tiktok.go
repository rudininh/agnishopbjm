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

// =======================
// ====== STRUCTS ========
// =======================

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

// =======================================
// ====== HTTP HANDLER: TIKTOK ============
// =======================================

func GetAllProductsHandler(w http.ResponseWriter, r *http.Request) {

	defer func() {
		if rec := recover(); rec != nil {
			fmt.Println("[PANIC]", rec)
			http.Error(w, "Internal Server Error", http.StatusInternalServerError)
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

	// ===== DB CONNECTION =====
	dbConn, err := getDBConn(ctx)
	if err != nil {
		fmt.Println("⚠️ DB connection disabled:", err)
		dbConn = nil
	} else {
		defer dbConn.Close(ctx)
	}

	// ===== SEARCH PRODUCTS =====
	searchReq := apiClient.ProductV202502API.
		Product202502ProductsSearchPost(ctx).
		XTtsAccessToken(cfg.AccessToken).
		ContentType("application/json").
		PageSize(100).
		ShopCipher(cfg.Cipher)

	body := product_v202502.NewProduct202502SearchProductsRequestBody()
	body.SetStatus("ALL")
	searchReq = searchReq.Product202502SearchProductsRequestBody(*body)

	searchResp, _, err := searchReq.Execute()
	if err != nil {
		http.Error(w, "Search API error: "+err.Error(), 500)
		return
	}

	if searchResp.GetCode() != 0 {
		http.Error(w, searchResp.GetMessage(), 500)
		return
	}

	var root map[string]interface{}
	bt, _ := json.Marshal(searchResp.GetData())
	_ = json.Unmarshal(bt, &root)

	products, ok := root["products"].([]interface{})
	if !ok {
		http.Error(w, "Products list not found", 500)
		return
	}

	results := []FixedProduct{}

	// ===== LOOP PRODUCT DETAIL =====
	for _, item := range products {

		pm, ok := item.(map[string]interface{})
		if !ok {
			continue
		}

		productID := fmt.Sprintf("%v", pm["id"])
		if productID == "" {
			continue
		}

		fmt.Println("Fetching detail:", productID)

		product, err := fetchTikTokProductDetail(
			ctx,
			cfg,
			productID,
			dbConn,
		)
		if err != nil {
			fmt.Println("⚠️ detail error:", err)
			continue
		}

		results = append(results, product)

		time.Sleep(100 * time.Millisecond)
	}

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"items": results,
	})
}

// =====================================================
// ====== HELPER: FETCH TIKTOK PRODUCT DETAIL ===========
// =====================================================

func fetchTikTokProductDetail(
	ctx context.Context,
	cfg *tiktok.Config,
	productID string,
	dbConn *pgx.Conn,
) (FixedProduct, error) {

	timestamp := time.Now().Unix()
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

	sign := tiktok.CalSign(req, cfg.AppSecret)
	q.Set("sign", sign)
	reqURL.RawQuery = q.Encode()
	req.URL = reqURL

	client := &http.Client{Timeout: 20 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return FixedProduct{}, err
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(resp.Body)

	var detail map[string]interface{}
	if err := json.Unmarshal(raw, &detail); err != nil {
		return FixedProduct{}, err
	}

	data, _ := detail["data"].(map[string]interface{})
	if data == nil {
		return FixedProduct{}, fmt.Errorf("no data")
	}

	productName := fmt.Sprintf("%v", data["title"])
	skuArr, _ := data["skus"].([]interface{})

	listSKU := []FixedSKU{}

	for _, s := range skuArr {
		sm, ok := s.(map[string]interface{})
		if !ok {
			continue
		}

		variantName := resolveVariantName(sm)
		stockQty := resolveStock(sm)
		price := resolvePrice(sm)

		fsku := FixedSKU{
			SKUName:   variantName,
			StockQty:  stockQty,
			Price:     price,
			Subtotal:  price * stockQty,
			TikTokSKU: fmt.Sprintf("%v", sm["id"]),
		}
		listSKU = append(listSKU, fsku)
	}

	return FixedProduct{
		ProductID:   productID,
		ProductName: productName,
		SKUs:        listSKU,
		Raw:         data,
	}, nil
}

// =================================
// ====== UTILITY FUNCTIONS =========
// =================================

func resolveVariantName(sm map[string]interface{}) string {
	if attrs, ok := sm["sales_attributes"].([]interface{}); ok {
		var names []string
		for _, a := range attrs {
			if m, ok := a.(map[string]interface{}); ok {
				if v, ok := m["value_name"].(string); ok {
					names = append(names, v)
				}
			}
		}
		if len(names) > 0 {
			return strings.Join(names, " / ")
		}
	}
	if v, ok := sm["seller_sku"].(string); ok && v != "" {
		return v
	}
	return "Default Variant"
}

func resolveStock(sm map[string]interface{}) int64 {
	if inv, ok := sm["inventory"].([]interface{}); ok && len(inv) > 0 {
		if m, ok := inv[0].(map[string]interface{}); ok {
			return parseInt(m["quantity"])
		}
	}
	return 0
}

func resolvePrice(sm map[string]interface{}) int64 {
	if p, ok := sm["price"].(map[string]interface{}); ok {
		return parseInt(p["sale_price"])
	}
	return parseInt(sm["price"])
}

func parseInt(v interface{}) int64 {
	switch x := v.(type) {
	case float64:
		return int64(x)
	case int64:
		return x
	case int:
		return int64(x)
	case string:
		var n int64
		fmt.Sscan(x, &n)
		return n
	default:
		return 0
	}
}

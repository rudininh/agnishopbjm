package handler

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"

	"github.com/jackc/pgx/v5"
)

// ===== Structs =====
type TokenData struct {
	ShopID      int64  `json:"shop_id"`
	AccessToken string `json:"access_token"`
}

type ShopeeItemListResponse struct {
	Response struct {
		Item []struct {
			ItemID int64 `json:"item_id"`
		} `json:"item"`
	} `json:"response"`
	Error   string `json:"error"`
	Message string `json:"message"`
}

type ShopeeItemInfoResponse struct {
	Response struct {
		ItemList []struct {
			ItemID   int64  `json:"item_id"`
			ItemName string `json:"item_name"`
			Stock    int64  `json:"stock"`
			Price    string `json:"price"`
			SKU      string `json:"item_sku"`
		} `json:"item_list"`
	} `json:"response"`
	Error   string `json:"error"`
	Message string `json:"message"`
}

// ===== Database Connection =====
func getDBConn(ctx context.Context) (*pgx.Conn, error) {
	conn, err := pgx.Connect(ctx, os.Getenv("DATABASE_URL"))
	if err != nil {
		return nil, fmt.Errorf("gagal konek DB: %v", err)
	}
	return conn, nil
}

// ===== Generate Shopee Signature =====
func generateShopeeSign(partnerID int64, path, accessToken string, shopID int64, timestamp int64, partnerKey string) string {
	// base_string = partner_id + path + timestamp + access_token + shop_id
	baseString := fmt.Sprintf("%d%s%d%s%d", partnerID, path, timestamp, accessToken, shopID)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	return hex.EncodeToString(h.Sum(nil))
}

// ===== Handler utama =====
func Handler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")

	ctx := context.Background()
	conn, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
		return
	}
	defer conn.Close(ctx)

	var token TokenData
	err = conn.QueryRow(ctx, "SELECT shop_id, access_token FROM shopee_tokens ORDER BY created_at DESC LIMIT 1").
		Scan(&token.ShopID, &token.AccessToken)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil token dari DB: %v"}`, err), http.StatusInternalServerError)
		return
	}

	partnerIDStr := "2013107"
	partnerKey := "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"

	fmt.Println("=== DEBUG ENV ===")
	fmt.Println("partnerID =", partnerIDStr)
	fmt.Println("partnerKey =", partnerKey)
	fmt.Println("shopID =", token.ShopID)
	fmt.Println("accessToken =", token.AccessToken)

	var partnerID int64
	fmt.Sscanf(partnerIDStr, "%d", &partnerID)

	// === STEP 1: GET ITEM LIST ===
	timestamp := time.Now().Unix()
	path := "/api/v2/product/get_item_list"

	sign := generateShopeeSign(partnerID, path, token.AccessToken, token.ShopID, timestamp, partnerKey)

	url := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&sign=%s&timestamp=%d&shop_id=%d&access_token=%s&offset=0&page_size=100&item_status=NORMAL",
		path, partnerID, sign, timestamp, token.ShopID, token.AccessToken,
	)

	// 🟢 DEBUG URL yang digunakan
	fmt.Println("=== DEBUG STEP 1 ===")
	fmt.Println("URL GET_ITEM_LIST:", url)

	resp, err := http.Get(url)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item list: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	fmt.Printf("DEBUG RAW Shopee GET_ITEM_LIST response: %s\n", string(body))

	var listRes ShopeeItemListResponse
	if err := json.Unmarshal(body, &listRes); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parsing item list: %v","raw":%q}`, err, string(body)), http.StatusInternalServerError)
		return
	}

	// 🟡 Tambahkan debug parsed
	fmt.Printf("DEBUG PARSED listRes: %+v\n", listRes)

	if listRes.Error != "" {
		fmt.Printf("DEBUG Shopee Error Response: %+v\n", listRes)
		http.Error(w, fmt.Sprintf(`{"error":"Shopee API error: %s","message":%q}`, listRes.Error, listRes.Message), http.StatusBadRequest)
		return
	}

	if len(listRes.Response.Item) == 0 {
		json.NewEncoder(w).Encode(map[string]interface{}{
			"items": []interface{}{},
			"note":  "Tidak ada item ditemukan dari Shopee",
		})
		return
	}

	// === STEP 2: GET ITEM INFO ===
	var itemIDs []int64
	for _, item := range listRes.Response.Item {
		itemIDs = append(itemIDs, item.ItemID)
	}
	itemIDsJSON, _ := json.Marshal(map[string][]int64{"item_id_list": itemIDs})

	path2 := "/api/v2/product/get_item_base_info"
	timestamp2 := time.Now().Unix()
	sign2 := generateShopeeSign(partnerID, path2, token.AccessToken, token.ShopID, timestamp2, partnerKey)

	url2 := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&sign=%s&timestamp=%d&shop_id=%d&access_token=%s&item_id_list=40&need_tax_info=true&need_complaint_policy=true",
		path2, partnerID, sign2, timestamp2, token.ShopID, token.AccessToken,
	)

	// === STEP 2: GET ITEM INFO ===
	var itemIDs []int64
	for _, item := range listRes.Response.Item {
		itemIDs = append(itemIDs, item.ItemID)
	}

	bodyData := map[string]interface{}{
		"item_id_list":          itemIDs,
		"need_tax_info":         true,
		"need_complaint_policy": true,
	}
	jsonBody, _ := json.Marshal(bodyData)

	path2 := "/api/v2/product/get_item_base_info"
	timestamp2 := time.Now().Unix()
	sign2 := generateShopeeSign(partnerID, path2, token.AccessToken, token.ShopID, timestamp2, partnerKey)

	url2 := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s",
		path2, partnerID, token.ShopID, timestamp2, token.AccessToken, sign2,
	)

	fmt.Println("=== DEBUG STEP 2 ===")
	fmt.Println("URL GET_ITEM_INFO:", url2)
	fmt.Println("Body JSON:", string(jsonBody))

	req, _ := http.NewRequest("POST", url2, bytes.NewBuffer(jsonBody))
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{}
	resp2, err := client.Do(req)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item info: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp2.Body.Close()

	body2, _ := io.ReadAll(resp2.Body)
	fmt.Printf("DEBUG RAW Shopee GET_ITEM_INFO response: %s\n", string(body2))

	var infoRes ShopeeItemInfoResponse
	if err := json.Unmarshal(body2, &infoRes); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parsing item info: %v","raw":%q}`, err, string(body2)), http.StatusInternalServerError)
		return
	}

	fmt.Printf("DEBUG PARSED infoRes: %+v\n", infoRes)

	if infoRes.Error != "" {
		fmt.Printf("DEBUG Shopee Error Info Response: %+v\n", infoRes)
		http.Error(w, fmt.Sprintf(`{"error":"Shopee API error: %s","message":%q}`, infoRes.Error, infoRes.Message), http.StatusBadRequest)
		return
	}

	// === STEP 3: RETURN RESULT ===
	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": infoRes.Response.ItemList,
		"count": len(infoRes.Response.ItemList),
	})
}

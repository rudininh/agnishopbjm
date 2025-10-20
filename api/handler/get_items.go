package handler

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strings"
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

	var partnerID int64
	fmt.Sscanf(partnerIDStr, "%d", &partnerID)

	// === STEP 1: GET ITEM LIST ===
	timestamp := time.Now().Unix()
	path := "/api/v2/product/get_item_list"

	sign := generateShopeeSign(partnerID, path, token.AccessToken, token.ShopID, timestamp, partnerKey)

	url := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&offset=0&page_size=100&item_status=NORMAL",
		path, partnerID, token.ShopID, timestamp, token.AccessToken, sign,
	)

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

	if listRes.Error != "" {
		http.Error(w, fmt.Sprintf(`{"error":"Shopee API error: %s","message":%q}`, listRes.Error, listRes.Message), http.StatusBadRequest)
		return
	}

	if len(listRes.Response.Item) == 0 {
		json.NewEncoder(w).Encode(map[string]interface{}{
			"items": []interface{}{},
			"note":  "Tidak ada item diupdate dalam 24 jam terakhir",
		})
		return
	}

	// === STEP 2: GET ITEM INFO ===
	var itemIDs []int64
	for _, item := range listRes.Response.Item {
		itemIDs = append(itemIDs, item.ItemID)
	}

	// Ubah slice []int64 jadi string "28525635095,27474912433,..."
	var itemIDStrings []string
	for _, id := range itemIDs {
		itemIDStrings = append(itemIDStrings, fmt.Sprintf("%d", id))
	}
	itemIDJoined := strings.Join(itemIDStrings, ",")

	path2 := "/api/v2/product/get_item_base_info"
	timestamp2 := time.Now().Unix()
	sign2 := generateShopeeSign(partnerID, path2, token.AccessToken, token.ShopID, timestamp2, partnerKey)

	url2 := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_id_list=%s&need_tax_info=true&need_complaint_policy=true",
		path2, partnerID, token.ShopID, timestamp2, token.AccessToken, sign2, itemIDJoined,
	)

	fmt.Println("URL GET_ITEM_BASE_INFO:", url2)

	// === REQUEST KE SHOPEE API ===
	resp2, err := http.Get(url2)
	if err != nil {
		log.Fatal("Gagal GET item info:", err)
	}
	defer resp2.Body.Close()

	body2, _ := io.ReadAll(resp2.Body)
	fmt.Println("DEBUG RAW Shopee GET_ITEM_INFO response:", string(body2))

	// Struct untuk decode JSON Shopee
	var result struct {
		Error     string `json:"error"`
		Message   string `json:"message"`
		Warning   string `json:"warning"`
		RequestID string `json:"request_id"`
		Response  struct {
			ItemList []struct {
				ItemID   int64  `json:"item_id"`
				ItemName string `json:"item_name"`
			} `json:"item_list"`
		} `json:"response"`
	}

	json.Unmarshal(body2, &result)

	// 🔧 Hapus warning yang tidak penting
	if strings.Contains(result.Warning, "fail to get channel estimated_shipping_fee") {
		result.Warning = ""
	}

	fmt.Printf("Hasil tanpa warning: %+v\n", result)

}

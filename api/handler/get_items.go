package handler

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
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

	resp, err := http.Get(url)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item list: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
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
			"note":  "Tidak ada item ditemukan di toko",
		})
		return
	}

	// === STEP 2: GET ITEM INFO ===
	var itemIDs []int64
	for _, item := range listRes.Response.Item {
		itemIDs = append(itemIDs, item.ItemID)
	}
	var idStrings []string
	for _, id := range itemIDs {
		idStrings = append(idStrings, fmt.Sprintf("%d", id))
	}
	itemIDJoined := strings.Join(idStrings, ",")

	path2 := "/api/v2/product/get_item_base_info"
	timestamp2 := time.Now().Unix()
	sign2 := generateShopeeSign(partnerID, path2, token.AccessToken, token.ShopID, timestamp2, partnerKey)

	url2 := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_id_list=%s",
		path2, partnerID, token.ShopID, timestamp2, token.AccessToken, sign2, itemIDJoined,
	)

	resp2, err := http.Get(url2)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item base info: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp2.Body.Close()

	body2, _ := io.ReadAll(resp2.Body)
	var baseInfo struct {
		Response struct {
			ItemList []struct {
				ItemID   int64  `json:"item_id"`
				ItemName string `json:"item_name"`
				ItemSKU  string `json:"item_sku"`
				Stock    int64  `json:"stock"`
				Price    string `json:"price"`
			} `json:"item_list"`
		} `json:"response"`
	}
	json.Unmarshal(body2, &baseInfo)

	// === STEP 3: GET MODEL LIST ===
	path3 := "/api/v2/product/get_model_list"
	timestamp3 := time.Now().Unix()
	sign3 := generateShopeeSign(partnerID, path3, token.AccessToken, token.ShopID, timestamp3, partnerKey)

	url3 := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_id_list=%s",
		path3, partnerID, token.ShopID, timestamp3, token.AccessToken, sign3, itemIDJoined,
	)

	resp3, err := http.Get(url3)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil model list: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp3.Body.Close()

	body3, _ := io.ReadAll(resp3.Body)
	var modelRes struct {
		Response struct {
			Item []struct {
				ItemID    int64 `json:"item_id"`
				ModelList []struct {
					ModelID   int64  `json:"model_id"`
					SKU       string `json:"model_sku"`
					StockInfo struct {
						NormalStock int64 `json:"normal_stock"`
					} `json:"stock_info"`
					PriceInfo struct {
						OriginalPrice string `json:"original_price"`
					} `json:"price_info"`
				} `json:"model"`
			} `json:"item"`
		} `json:"response"`
	}
	json.Unmarshal(body3, &modelRes)

	// === Gabungkan hasil base info + model info ===
	finalItems := []map[string]interface{}{}
	for _, base := range baseInfo.Response.ItemList {
		itemData := map[string]interface{}{
			"nama":  base.ItemName,
			"sku":   base.ItemSKU,
			"stok":  base.Stock,
			"harga": base.Price,
		}

		// Override jika ada model
		for _, m := range modelRes.Response.Item {
			if m.ItemID == base.ItemID && len(m.ModelList) > 0 {
				itemData["sku"] = m.ModelList[0].SKU
				itemData["stok"] = m.ModelList[0].StockInfo.NormalStock
				itemData["harga"] = m.ModelList[0].PriceInfo.OriginalPrice
				break
			}
		}

		finalItems = append(finalItems, itemData)
	}

	// === Kirim hasil akhir ke frontend ===
	json.NewEncoder(w).Encode(map[string]interface{}{
		"count": len(finalItems),
		"items": finalItems,
	})
}

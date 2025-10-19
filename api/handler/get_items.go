package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"time"
)

// Struct respons Shopee
type GetItemListResponse struct {
	Error    string `json:"error"`
	Message  string `json:"message"`
	Response struct {
		Item []struct {
			ItemID int64 `json:"item_id"`
		} `json:"item"`
		TotalCount int  `json:"total_count"`
		HasNext    bool `json:"has_next_page"`
	} `json:"response"`
}

type GetItemInfoResponse struct {
	Error    string `json:"error"`
	Message  string `json:"message"`
	Response struct {
		ItemList []struct {
			ItemID    int64  `json:"item_id"`
			ItemName  string `json:"item_name"`
			ItemSKU   string `json:"item_sku"`
			PriceInfo []struct {
				OriginalPrice float64 `json:"original_price"`
			} `json:"price_info"`
			StockInfoV2 struct {
				SummaryInfo struct {
					TotalAvailableStock int `json:"total_available_stock"`
				} `json:"summary_info"`
			} `json:"stock_info_v2"`
		} `json:"item_list"`
	} `json:"response"`
}

func generateSign(path, partnerKey string, body string) (string, int64) {
	timestamp := time.Now().Unix()
	signString := fmt.Sprintf("%s%s%d", path, partnerKey, timestamp)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(signString))
	return hex.EncodeToString(h.Sum(nil)), timestamp
}

func main() {
	// Ambil data dari environment (bisa kamu ubah langsung di sini)
	partnerID := int64(2013107)
	partnerKey := "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"
	shopID := int64(380921117)
	accessToken := "5642524266556b416759714f6d7a6155"

	fmt.Println("=== DEBUG ENV ===")
	fmt.Println("partnerID =", partnerID)
	fmt.Println("shopID =", shopID)

	// === STEP 1: GET_ITEM_LIST ===
	path := "/api/v2/product/get_item_list"
	sign, timestamp := generateSign(path, partnerKey, "")
	url := fmt.Sprintf("https://partner.shopeemobile.com%s?partner_id=%d&sign=%s&timestamp=%d&shop_id=%d&access_token=%s&offset=0&page_size=10&item_status=NORMAL",
		path, partnerID, sign, timestamp, shopID, accessToken)

	fmt.Println("=== DEBUG STEP 1 ===")
	fmt.Println("URL GET_ITEM_LIST:", url)

	resp, err := http.Get(url)
	if err != nil {
		log.Fatal("Error request GET_ITEM_LIST:", err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)

	var listRes GetItemListResponse
	_ = json.Unmarshal(body, &listRes)

	fmt.Println("DEBUG RAW Shopee GET_ITEM_LIST response:", string(body))

	if listRes.Error != "" {
		fmt.Println(`{"error":"Gagal ambil item list: ` + listRes.Error + `"}`)
		os.Exit(1)
	}

	if len(listRes.Response.Item) == 0 {
		fmt.Println(`{"items":[],"note":"Tidak ada item ditemukan di toko ini"}`)
		return
	}

	// === STEP 2: GET_ITEM_BASE_INFO ===
	itemIDs := []int64{}
	for _, it := range listRes.Response.Item {
		itemIDs = append(itemIDs, it.ItemID)
	}

	// Ambil satu per satu jika error batch
	fmt.Println("=== DEBUG STEP 2 ===")
	bodyData := map[string]interface{}{
		"item_id_list": itemIDs,
	}
	bodyBytes, _ := json.Marshal(bodyData)

	path2 := "/api/v2/product/get_item_base_info"
	sign2, timestamp2 := generateSign(path2, partnerKey, string(bodyBytes))
	url2 := fmt.Sprintf("https://partner.shopeemobile.com%s?partner_id=%d&access_token=%s&timestamp=%d&shop_id=%d&sign=%s",
		path2, partnerID, accessToken, timestamp2, shopID, sign2)

	req, _ := http.NewRequest("POST", url2, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")

	resp2, err := http.DefaultClient.Do(req)
	if err != nil {
		log.Fatal("Error request GET_ITEM_INFO:", err)
	}
	defer resp2.Body.Close()
	body2, _ := io.ReadAll(resp2.Body)

	fmt.Println("DEBUG RAW Shopee GET_ITEM_INFO response:", string(body2))

	var infoRes GetItemInfoResponse
	_ = json.Unmarshal(body2, &infoRes)

	if infoRes.Error != "" {
		fmt.Printf(`{"error":"Shopee API error: %s","message":"%s"}`, infoRes.Error, infoRes.Message)
		return
	}

	if len(infoRes.Response.ItemList) == 0 {
		fmt.Println(`{"items":[],"note":"Tidak ada item diupdate dalam 24 jam terakhir"}`)
		return
	}

	// === FORMAT OUTPUT ===
	fmt.Println("\n=== HASIL PRODUK ===")
	fmt.Printf("%-5s %-40s %-15s %-10s %-10s\n", "No", "Nama Produk", "SKU", "Stok", "Harga")
	for i, item := range infoRes.Response.ItemList {
		harga := 0.0
		if len(item.PriceInfo) > 0 {
			harga = item.PriceInfo[0].OriginalPrice
		}
		fmt.Printf("%-5d %-40s %-15s %-10d %-10.2f\n",
			i+1, item.ItemName, item.ItemSKU, item.StockInfoV2.SummaryInfo.TotalAvailableStock, harga)
	}
}

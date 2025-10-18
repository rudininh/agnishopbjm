package main

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
}

func getDBConn(ctx context.Context) (*pgx.Conn, error) {
	conn, err := pgx.Connect(ctx, os.Getenv("DATABASE_URL"))
	if err != nil {
		return nil, fmt.Errorf("gagal konek DB: %v", err)
	}
	return conn, nil
}

func Handler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")

	ctx := context.Background()
	conn, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, err.Error(), 500)
		return
	}
	defer conn.Close(ctx)

	var token TokenData
	err = conn.QueryRow(ctx, "SELECT shop_id, access_token FROM shopee_tokens ORDER BY created_at DESC LIMIT 1").Scan(&token.ShopID, &token.AccessToken)
	if err != nil {
		http.Error(w, fmt.Sprintf("Gagal ambil token dari DB: %v", err), 500)
		return
	}

	partnerID := os.Getenv("SHOPEE_PARTNER_ID")
	partnerKey := os.Getenv("SHOPEE_PARTNER_KEY")
	timestamp := time.Now().Unix()

	baseString := fmt.Sprintf("%s/api/v2/product/get_item_list%s%d", partnerID, token.AccessToken, timestamp)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	sign := hex.EncodeToString(h.Sum(nil))

	url := fmt.Sprintf("https://partner.shopeemobile.com/api/v2/product/get_item_list?partner_id=%s&timestamp=%d&access_token=%s&shop_id=%d&sign=%s&page_size=20",
		partnerID, timestamp, token.AccessToken, token.ShopID, sign)

	resp, err := http.Get(url)
	if err != nil {
		http.Error(w, fmt.Sprintf("Gagal ambil item list: %v", err), 500)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	var listRes ShopeeItemListResponse
	json.Unmarshal(body, &listRes)

	if len(listRes.Response.Item) == 0 {
		w.Write([]byte(`{"items": []}`))
		return
	}

	var itemIDs []int64
	for _, item := range listRes.Response.Item {
		itemIDs = append(itemIDs, item.ItemID)
	}

	itemIDsJson, _ := json.Marshal(map[string][]int64{"item_id_list": itemIDs})

	timestamp2 := time.Now().Unix()
	baseString2 := fmt.Sprintf("%s/api/v2/product/get_item_base_info%s%d", partnerID, token.AccessToken, timestamp2)
	h2 := hmac.New(sha256.New, []byte(partnerKey))
	h2.Write([]byte(baseString2))
	sign2 := hex.EncodeToString(h2.Sum(nil))

	url2 := fmt.Sprintf("https://partner.shopeemobile.com/api/v2/product/get_item_base_info?partner_id=%s&timestamp=%d&access_token=%s&shop_id=%d&sign=%s",
		partnerID, timestamp2, token.AccessToken, token.ShopID, sign2)

	req, _ := http.NewRequest("POST", url2, bytes.NewBuffer(itemIDsJson))
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{}
	resp2, err := client.Do(req)
	if err != nil {
		http.Error(w, fmt.Sprintf("Gagal ambil item info: %v", err), 500)
		return
	}
	defer resp2.Body.Close()

	body2, _ := io.ReadAll(resp2.Body)
	var infoRes ShopeeItemInfoResponse
	json.Unmarshal(body2, &infoRes)

	json.NewEncoder(w).Encode(map[string]interface{}{
		"items": infoRes.Response.ItemList,
	})
}

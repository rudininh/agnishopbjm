package handler

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"sort"
	"time"

	"agnishopbjm/sdk_golang/apis"
)

var (
	appKey      = "6i1cagd9f0p83"
	appSecret   = "3710881f177a1e6b03664cc91d8a3516001a0bc7"
	accessToken = "ROW_AzS9-QAAAADzg8qrc-oxg3vJ6aa81jlxT3PGJjiOXRP-TS7K6Yf32hJjIiw5XGhkGfBm7Ohs2HrD2jQoDVYlWVmqzD8WVcb18J1kK4Htsn0j_xGyfX1UGFRchLx7LBpfamcbkoSh0-eEvln9zTIucaSptXrCaqqnFRvZaU60pfJRU43rzjSKIg"
)

func generateSign(appKey, accessToken, timestamp string) string {
	params := map[string]string{
		"access_token": accessToken,
		"app_key":      appKey,
		"shop_id":      "",
		"timestamp":    timestamp,
		"version":      "202309",
	}

	keys := make([]string, 0, len(params))
	for k := range params {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	raw := ""
	for _, k := range keys {
		raw += k + params[k]
	}

	h := hmac.New(sha256.New, []byte(appSecret))
	h.Write([]byte(raw))
	return hex.EncodeToString(h.Sum(nil))
}

func AuthorizationGetShops() {
	timestamp := fmt.Sprintf("%d", time.Now().Unix())
	sign := generateSign(appKey, accessToken, timestamp)

	// ⚡ FORCE override query parameters (SDK tidak mengisi ini)
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(appKey, appSecret)

	// Override base path → biar SDK pakai URL lengkap kita
	configuration.Host = "open-api.tiktokglobalshop.com"

	apiClient := apis.NewAPIClient(configuration)

	// Build request paksa sama dengan CURL
	req := apiClient.AuthorizationV202309API.
		Authorization202309ShopsGet(context.Background()).
		XTtsAccessToken(accessToken).
		ContentType("application/json")

	// Inject query params wajib
	req = req.ShopId("")               // &shop_id=
	req = req.Version("202309")        // &version=202309
	req = req.Timestamp(timestamp)     // &timestamp=xxx
	req = req.AppKey(appKey)           // &app_key=
	req = req.AccessToken(accessToken) // &access_token=
	req = req.Sign(sign)               // &sign=

	resp, httpResp, err := req.Execute()

	if err != nil || httpResp.StatusCode != 200 {
		fmt.Println("ERROR:", err)
		fmt.Println("HTTP RAW RESPONSE:", httpResp)
		return
	}

	if resp == nil {
		fmt.Println("response is nil")
		return
	}

	if resp.GetCode() != 0 {
		fmt.Printf("API Error code=%d msg=%s\n", resp.GetCode(), resp.GetMessage())
		return
	}

	jb, _ := json.MarshalIndent(resp.GetData(), "", "  ")
	fmt.Println("RESP DATA:", string(jb))
}

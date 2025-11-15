package handler

import (
	"agnishopbjm/sdk_golang/apis"
	"context"
	"encoding/json"
	"fmt"
)

var (
	appKey      = "6i1cagd9f0p83"
	appSecret   = "3710881f177a1e6b03664cc91d8a3516001a0bc7"
	accessToken = "ROW_ySadvQAAAADzg8qrc-oxg3vJ6aa81jlxT3PGJjiOXRP-TS7K6Yf32hJjIiw5XGhkGfBm7Ohs2HrD2jQoDVYlWVmqzD8WVcb18J1kK4Htsn0j_xGyfX1UGOxGT_j_lSNyxlA0DxQ-NJostcZDtLmhSoTbMUZpgdQ1RRQzCbnWEu5d0u65VukNdA"
)

func TestSDK() {
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(appKey, appSecret)
	apiClient := apis.NewAPIClient(configuration)
	request := apiClient.AuthorizationV202309API.Authorization202309ShopsGet(context.Background())
	request = request.XTtsAccessToken(accessToken)
	request = request.ContentType("application/json")
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
}

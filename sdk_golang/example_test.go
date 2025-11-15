package sdk_golang

import (
	"context"
	"fmt"
	"testing"

	"tiktokshop/open/sdk_golang/apis"
    "tiktokshop/open/sdk_golang/utils"

	product_v202309 "tiktokshop/open/sdk_golang/models/product/v202309"
)

var (
	appKey    = "67tg51bc4rv3j"
	appSecret = "f82ef61bb7d7c95956085a343fa21009297afd12"
	token     = "TTP_TNaijwAAAAA-gihZtv4n_iLNli0HGps9-vcjhiBVdj8sdRTGOGZZuP0FFFUZ_-jpqPLUx1ob2AWaWdzDi5He_ZzmgBwqItLDWZvzIJM9uctYrbSFuXlaQXpM-iR9A1CmNXLkBaLMHUnZpYybAkE2m8o18zxPoDZBzmIKRuF2DlDY6WyUhtGeR1I4Ny75nR9dEl1sd2sLjR0"
    cipher    = "TTP_PNhwygAAAACxBhF2wVkPB3p9iP1SwbJC"
)

func TestExample(t *testing.T) {
	appKey = "59odsg"
	appSecret = "825c80ec0e93026725c8e4d90acc3d70ebb9159c"
	at := apis.NewAccessToken(appKey, appSecret)
	refreshToken, _ := at.RefreshToken(
		"ROW_VHM8ggAAAACY1uJ1_SaFJx8sZUAYBPn8nQlcip0pew4O-1VZC5ZXS3r90B0oPER9SW9JF3JKcaY")
	fmt.Println("refreshToken= ", refreshToken)

	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(appKey, appSecret)
	apiClient := apis.NewAPIClient(configuration)
	request := apiClient.SellerV202309API.Seller202309ShopsGet(context.Background())
	request = request.XTtsAccessToken(refreshToken)
	request = request.ContentType("application/json")
	resp, httpRes, err := request.Execute()
	if err != nil || httpRes.StatusCode != 200 {
        fmt.Printf("request err:%v resbody:%s", err, httpRes.Body)
        return
    }
    if resp == nil {
        fmt.Printf("response is nil")
        return
    }
    if resp.GetCode() != 0 {
        fmt.Printf("response business is error! errorCode:%d errorMessage:%s", resp.GetCode(), resp.GetMessage())
        return
    }
    fmt.Println("resp data := ", resp.GetData())
}

func TestOrder202309OrdersGet(t *testing.T) {
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(appKey, appSecret)
	apiClient := apis.NewAPIClient(configuration)
	request := apiClient.OrderV202309API.Order202309OrdersGet(context.Background())
	request = request.XTtsAccessToken(token)
	request = request.ContentType("application/json")
	request = request.ShopCipher(cipher)
	request = request.Ids([]string{
		"576487744574100418",
		"576487745724715360"})
	resp, httpRes, err := request.Execute()
	if err != nil || httpRes.StatusCode != 200 {
        fmt.Printf("request err:%v resbody:%s", err, httpRes.Body)
        return
    }
	if resp == nil {
		fmt.Printf("response is nil")
		return
	}
	if resp.GetCode() != 0 {
		fmt.Printf("response business is error! errorCode:%d errorMessage:%s", resp.GetCode(), resp.GetMessage())
		return
	}
	fmt.Println("resp data := ", resp.GetData())
}

func TestRefreshToken(t *testing.T) {
	at := apis.NewAccessToken(appKey, appSecret)
	refreshToken, _ := at.RefreshToken(
		"TTP_3_J2AwAAAAByHATtcAn_QjzNPEETo1Q4hWr8FUpKYadtk0x_8jCX3C2k4IoV7Kg1-g7iNrXQJMs")
	fmt.Println("refreshToken= ", refreshToken)
}

func TestProduct202309ProductsSearchPost(t *testing.T) {
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(appKey, appSecret)
	apiClient := apis.NewAPIClient(configuration)
	request := apiClient.ProductV202309API.Product202309ProductsSearchPost(context.Background())
	request = request.ContentType("application/json")
	request = request.XTtsAccessToken(token)
	request = request.ShopCipher(cipher)
	request = request.PageSize(1)
	reqBody := product_v202309.Product202309SearchProductsRequestBody{
		Status: utils.PtrString("ALL"),
	}
	request = request.Product202309SearchProductsRequestBody(reqBody)
	resp, httpRes, err := request.Execute()
	fmt.Println("resp := ", resp)
	fmt.Println("httpRes StatusCode := ", httpRes)
	fmt.Println("err error() := ", err)
	fmt.Println("data := ", resp.GetData())
}

func TestListingSchemasGet(t *testing.T) {
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(appKey, appSecret)
	apiClient := apis.NewAPIClient(configuration)
	request := apiClient.ProductV202401API.Product202401ListingSchemasGet(context.Background())
	request = request.ContentType("application/json")
	request = request.XTtsAccessToken(token)
	request = request.CategoryIds([]int32{1, 2})
	resp, httpRes, err := request.Execute()
	if err != nil || httpRes.StatusCode != 200 {
        fmt.Printf("request err:%v resbody:%s", err, httpRes.Body)
        return
    }
	if resp == nil {
		fmt.Printf("response is nil")
		return
	}
	if resp.GetCode() != 0 {
		fmt.Printf("response business is error! errorCode:%d errorMessage:%s", resp.GetCode(), resp.GetMessage())
		return
	}
	fmt.Println("resp data := ", resp.GetData())
}

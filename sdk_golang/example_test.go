package sdk_golang

import (
	"context"
	"fmt"
	"testing"

	"agnishopbjm/sdk_golang/apis"
	"agnishopbjm/sdk_golang/utils"

	product_v202309 "agnishopbjm/sdk_golang/models/product/v202309"
)

var (
	appKey    = "6i1cagd9f0p83"
	appSecret = "3710881f177a1e6b03664cc91d8a3516001a0bc7"
	token     = "ROW_ENHTKAAAAADzg8qrc-oxg3vJ6aa81jlxT3PGJjiOXRP-TS7K6Yf32hJjIiw5XGhkGfBm7Ohs2HrD2jQoDVYlWVmqzD8WVcb18J1kK4Htsn0j_xGyfX1UGEjQ0wHeHBaLgcxc9fLQPOVqni_K1EdWZryLibhvZ_qNl9kWhXrRQDMBpLE4oUXaQw"
	cipher    = "TTP_PNhwygAAAACxBhF2wVkPB3p9iP1SwbJC"
)

func TestExample(t *testing.T) {
	appKey = "59o6i1cagd9f0p83dsg"
	appSecret = "3710881f177a1e6b03664cc91d8a3516001a0bc7"
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
		"ROW_x63bjwAAAAB9bHzBvcYwMqjFYjUc4pC9V2wxYiM9BDki0_Eplb4tzuaT9Rn5pUlbMNt1v7jAmPc")
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

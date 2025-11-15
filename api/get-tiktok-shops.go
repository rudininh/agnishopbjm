package handler

import (
	"context"
	"encoding/json"
	"fmt"

	"tiktokshop/open/sdk_golang/apis"
)

var (
	appKey    = "6i1cagd9f0p83"
	appSecret = "3710881f177a1e6b03664cc91d8a3516001a0bc7"
	authCode  = "ROW_exSC2AAAAAB8l5P-lFKcuj7exgqe6kx3W6DtyyDCPPdYtbreqSCutZ1Ebedl_szc1B6pNZAwNC2XjmEG7ySdg0k000_nMgyonTxzvmTxtlN6qU2cn7VlBjkb60QBpIqnCXb1ssKjvMFCN2U_UMaO1-p1ZSelAMZcb8BVKAT_jLkenWPBTiNlfb9mnlCDskAOIReZbQsS-dV_8cT6hpXoPtGi0odSzh7TyPn0xGT7Vl-nlpv5YZRYovjW9uZT25Y26ngxVVGii2fUY_UV3jagBT_GTY1LcqVAzYlGhU47qtDeDQbwOAssuWv_XBCc4K-NnnuOaa6PuuZzngUQFmvzcjXiFWNbrkrqR882V_DSDOKg92WWA2t7GYF6iZ7etP-0RwdjYykI4eK4MNw1RkEoIOkSe3L658bOuXe4I2fqI_Gdt7W--M_xVsuT_g6ueL5ZMi-GmGfBBskui7SD5qsIeTTH4HO1RVZbAqEEgQ9j1epy3O7jcUySut1iK8wKVrCbbbj0lJRbqtuHLzo_kmro2Ap90_jrJe01zT7p3QMI5Q3KyXRhapfRhw"
)

func authorization202309GetAuthorizedShopsGet() {
	configuration := apis.NewConfiguration()
	configuration.AddAppInfo(appKey, appSecret)
	apiClient := apis.NewAPIClient(configuration)
	request := apiClient.AuthorizationV202309API.Authorization202309ShopsGet(context.Background())
	request = request.XTtsAccessToken("TS7K6Yf32hJjIiw5XGhkGfBm7Ohs2HrD2jQoDVYlWVmqzD8WVcb18J1kK4Htsn0j_xGyfX1UGEjQ0wHeHBaLgcxc9fLQPOVqni_K1EdWZryLibhvZ_qNl9kWhXrRQDMBpLE4oUXaQw")
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

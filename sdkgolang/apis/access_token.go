package apis

import (
	"encoding/json"
	"io"
	"io/ioutil"
	"log"
	"net/http"
)

const (
	GetTokenUrl     = "https://auth.tiktok-shops.com/api/v2/token/get"
	RefreshTokenUrl = "https://auth.tiktok-shops.com/api/v2/token/refresh"

	GrantTypeAuthorizedCode = "authorized_code"
	GrantTypeRefreshToken   = "refresh_token"

	MethodGet = "GET"
)

type AccessToken struct {
	AppKey    string
	AppSecret string
}

type ResponseInfo struct {
	Code    int       `json:"code"`
	Message string    `json:"message"`
	Data    TokenInfo `json:"data"`
}

type TokenInfo struct {
	AccessToken          string   `json:"access_token"`
	AccessTokenExpireIn  int      `json:"access_token_expire_in"`
	RefreshToken         string   `json:"refresh_token"`
	RefreshTokenExpireIn int      `json:"refresh_token_expire_in"`
	OpenID               string   `json:"open_id"`
	SellerName           string   `json:"seller_name"`
	SellerBaseRegion     string   `json:"seller_base_region"`
	UserType             int      `json:"user_type"`
	GrantedScopes        []string `json:"granted_scopes"`
}

func NewAccessToken(appKey, appSecret string) *AccessToken {
	return &AccessToken{
		AppKey:    appKey,
		AppSecret: appSecret,
	}
}

func (a *AccessToken) GetToken(authCode string) (*ResponseInfo, error) {
	req, _ := http.NewRequest(MethodGet, GetTokenUrl, nil)
	q := req.URL.Query()
	q.Add("app_key", a.AppKey)
	q.Add("app_secret", a.AppSecret)
	q.Add("auth_code", authCode)
	q.Add("grant_type", GrantTypeAuthorizedCode)
	req.URL.RawQuery = q.Encode()

	res, err := doRequest(MethodGet, req.URL.String(), nil)
	if err != nil {
		log.Printf("AccessToken.GetToken doRequest err:%v", err)
		return nil, err
	}
	response := &ResponseInfo{}
	if parseErr := json.Unmarshal(res, response); parseErr != nil {
		log.Printf("AccessToken.GetToken response unmarshal err:%v", parseErr)
		return nil, parseErr
	}
	return response, nil
}

func (a *AccessToken) RefreshToken(refreshToken string) (string, error) {
	req, _ := http.NewRequest(MethodGet, RefreshTokenUrl, nil)
	q := req.URL.Query()
	q.Add("app_key", a.AppKey)
	q.Add("app_secret", a.AppSecret)
	q.Add("refresh_token", refreshToken)
	q.Add("grant_type", GrantTypeRefreshToken)
	req.URL.RawQuery = q.Encode()

	res, err := doRequest(MethodGet, req.URL.String(), nil)
	if err != nil {
		log.Printf("AccessToken.RefreshToken doRequest err:%v", err)
		return "", err
	}
	response := &ResponseInfo{}
	if parseErr := json.Unmarshal(res, response); parseErr != nil {
		log.Printf("AccessToken.RefreshToken Unmarshal err:%v", parseErr)
		return "", parseErr
	}
	return response.Data.AccessToken, nil
}

func doRequest(method, url string, reqBody io.Reader) ([]byte, error) {
	client := &http.Client{}
	req, err := http.NewRequest(method, url, nil)

	if err != nil {
		log.Printf("AccessToken.doRequest NewRequest err:%v", err)
		return []byte{}, err
	}
	res, err := client.Do(req)
	if err != nil {
		log.Printf("AccessToken.doRequest request err:%v", err)
		return []byte{}, err
	}
	defer res.Body.Close()

	body, err := ioutil.ReadAll(res.Body)
	if err != nil {
		log.Printf("AccessToken.doRequest ReadAll err:%v", err)
		return []byte{}, err
	}
	return body, nil
}

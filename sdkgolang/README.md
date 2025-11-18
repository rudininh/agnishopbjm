# Golang SDK

### Prerequisites

Before integrating TikTok Shop API SDK into your project and making your first API call with the SDK, you must [create a test seller account](https://partner.tiktokshop.com/docv2/page/6789f75a38b3f103167690dc) and [generate a test access token](https://partner.tiktokshop.com/docv2/page/6789f75d2dccb8030e8dece5).

### Integrate Golang SDK

Online version of this document: [https://partner.tiktokshop.com/docv2/page/67c83e0799a75104986ae498](Tiktok Shop API SDK)

#### Prerequisites

Ensure your project meets all of the following conditions:

- GoLang 1.18+

#### Installation

1. Unzip the downloaded package to get the source code folder.
2. Copy the source code folder to your project directory.
3. Download the dependencies in the SDK package. Run the following commands in the root directory of the project:

```Bash
go get github.com/stretchr/testify/assert
go get golang.org/x/net/context
```

1. Add TikTok Shop API SDK to the modules required in your project. Add the following content in `go.mod`:

```Go
// replace 1.0.0 with the version number of the SDK you downloaded.
require tiktokshop/open/<name-of-the-SDK-folder> v1.0.0
replace tiktokshop/open/<name-of-the-SDK-folder> => ./sdk_golang
```

1. Compile the project using `go build` command.If there are no errors, the SDK import is successful.

#### Initialize API Request Client

Import the corresponding module.

```Go
import (
    "context"
    "fmt"
    "testing"

    "tiktokshop/open/sdk_golang/apis"
    "tiktokshop/open/sdk_golang/utils"

    product_v202502 "tiktokshop/open/sdk_golang/models/product/v202502"
)
```

Set authorisation information for the API request.

```Go
var (
    // To get your AppKey and Secret, see [Step 6 - Create a TikTok Shop App (OAuth client)](https://partner.tiktokshop.com/docv2/page/6789f74e23ae4b030c389e76#Back%20To%20Top)
    appKey      = "67tg51w21"
    appSecret   = "f82ee75y9fj499297afd12"
    // See [Generate a test access token](https://partner.tiktokshop.com/docv2/page/6789f75d2dccb8030e8dece5)
    accessToken = "TTP_TNaijwAtyFuXlaQXpM-iR9A1CmNXLkBaLMHUnZr5v6zxPoDZBzmIK55dEl1sd2sLjR0"
    // Shop cipher. See [Search Products](https://partner.tiktokshop.com/docv2/page/67b837b685619104a6846369?external_id=67b837b685619104a6846369#Request_Query)
    cipher      = "TTP_Pe83TYBCEJ08n374hn"
)
```

Create a new instance for `Product``202502``ProductsSearchPost`.

```Go
configuration := apis.NewConfiguration()
configuration.AddAppInfo(appKey, appSecret)
apiClient := apis.NewAPIClient(configuration)
request := apiClient.ProductV202502API.Product202502ProductsSearchPost(context.Background())
request = request.ContentType("application/json")
request = request.XTtsAccessToken(token)
request = request.ShopCipher(cipher)
request = request.PageSize(1)
reqBody := product_v202502.Product202502SearchProductsRequestBody{
Status: utils.PtrString("ALL"),
}
request = request.Product202502SearchProductsRequestBody(reqBody)
```

#### Make API Request and Access API Response

Every time you make an API request, TTSPC sends you back a response. Access the response and handle possible errors.

```Go
resp, httpRes, err := request.Execute()
fmt.Println("resp := ", resp)
fmt.Println("httpRes StatusCode := ", httpRes)
fmt.Println("err error() := ", err)
fmt.Println("data := ", resp.GetData())
```

If the API request succeeds, you will get a result which resembles the following content:

```Go
{
"code": 0,
"data": {
"next_page_token": "b2Zmc2V0PTAK",
"products": [
{
"audit": {
"status": "AUDITING"
},
"create_time": 1234567890,
"id": "1729592969712207008",
"integrated_platform_statuses": [
{
"platform": "TOKOPEDIA",
"status": "PLATFORM_DEACTIVATED"
}
/* ... */
}
```
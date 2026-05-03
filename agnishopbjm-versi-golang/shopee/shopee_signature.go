package shopee

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"strconv"
)

/*
=====================================================
Generate Shopee Signature
sign = HMAC_SHA256(partner_id + path + timestamp)
=====================================================
*/
func GenerateShopeeSign(
	partnerID int64,
	partnerKey string,
	path string,
	timestamp int64,
) string {

	baseString :=
		strconv.FormatInt(partnerID, 10) +
			path +
			strconv.FormatInt(timestamp, 10)

	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))

	return hex.EncodeToString(h.Sum(nil))
}

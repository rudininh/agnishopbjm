package tiktok

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"mime"
	"net/http"
	"sort"
)

func CalSign(req *http.Request, secret string) string {

	queries := req.URL.Query()

	// List all keys except sign & access_token
	keys := make([]string, 0)
	for k := range queries {
		if k != "sign" && k != "access_token" {
			keys = append(keys, k)
		}
	}

	sort.Strings(keys)

	// Build input = path + (k+v)
	input := req.URL.Path
	for _, key := range keys {
		input += key + queries.Get(key)
	}

	// Append body ONLY if not multipart AND body not nil
	mediaType, _, _ := mime.ParseMediaType(req.Header.Get("Content-Type"))

	if req.Body != nil && mediaType != "multipart/form-data" {
		body, _ := io.ReadAll(req.Body)

		input += string(body)

		// reset body
		req.Body = io.NopCloser(bytes.NewBuffer(body))
	}

	// wrap with secret
	input = secret + input + secret

	// SHA256 sign
	h := hmac.New(sha256.New, []byte(secret))
	h.Write([]byte(input))

	return hex.EncodeToString(h.Sum(nil))
}

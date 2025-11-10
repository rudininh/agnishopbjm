package handler

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
)

// ===== Structs =====
type TokenData struct {
	ShopID      int64  `json:"shop_id"`
	AccessToken string `json:"access_token"`
}

// ===== Database Connection =====
func getDBConn(ctx context.Context) (*pgx.Conn, error) {
	conn, err := pgx.Connect(ctx, os.Getenv("DATABASE_URL"))
	if err != nil {
		return nil, fmt.Errorf("gagal konek DB: %v", err)
	}
	return conn, nil
}

// ===== Generate Shopee Signature =====
func generateShopeeSign(partnerID int64, path, accessToken string, shopID int64, timestamp int64, partnerKey string) string {
	baseString := fmt.Sprintf("%d%s%d%s%d", partnerID, path, timestamp, accessToken, shopID)
	h := hmac.New(sha256.New, []byte(partnerKey))
	h.Write([]byte(baseString))
	return hex.EncodeToString(h.Sum(nil))
}

// ===== Helper functions =====

// parseNumber tries to interpret v as float64/int64/or string numeric and returns int64 value (no decimals)
func parseNumber(v interface{}) (int64, bool) {
	if v == nil {
		return 0, false
	}
	switch t := v.(type) {
	case float64:
		return int64(t), true
	case float32:
		return int64(t), true
	case int:
		return int64(t), true
	case int64:
		return t, true
	case json.Number:
		i, err := t.Int64()
		if err == nil {
			return i, true
		}
		f, err := t.Float64()
		if err == nil {
			return int64(f), true
		}
		return 0, false
	case string:
		// remove commas, currency signs, etc
		s := strings.ReplaceAll(t, ",", "")
		s = strings.TrimSpace(s)
		if s == "" {
			return 0, false
		}
		// try int
		if i, err := strconv.ParseInt(s, 10, 64); err == nil {
			return i, true
		}
		// try float
		if f, err := strconv.ParseFloat(s, 64); err == nil {
			return int64(f), true
		}
		return 0, false
	default:
		return 0, false
	}
}

// rupiahFromMicros attempts to convert a "micros" price to rupiah integer.
// Many Shopee fields store price in micros (e.g., 123450000 -> Rp 1,234,500).
// We'll try dividing by 100000 (common) and also fallback to other heuristics.
func rupiahFromMicros(m int64) int64 {
	// guard: if micros looks huge, divide; otherwise return as-is
	// Common conversion factor from Shopee: price in micros (1e5) -> divide by 100000
	if m == 0 {
		return 0
	}
	// If number length > 6, divide
	abs := m
	if abs < 0 {
		abs = -abs
	}
	if abs > 1000000 {
		return m / 100000
	}
	// small numbers: maybe already in rupiah
	return m
}

// formatRupiah adds thousand separators. Example: 1234500 -> "Rp 1.234.500"
func formatRupiah(v int64) string {
	neg := v < 0
	if neg {
		v = -v
	}
	s := strconv.FormatInt(v, 10)
	var parts []string
	for len(s) > 3 {
		parts = append([]string{s[len(s)-3:]}, parts...)
		s = s[:len(s)-3]
	}
	if s != "" {
		parts = append([]string{s}, parts...)
	}
	out := strings.Join(parts, ".")
	if out == "" {
		out = "0"
	}
	if neg {
		return fmt.Sprintf("-Rp %s", out)
	}
	return fmt.Sprintf("Rp %s", out)
}

// tryGetFromMap walks nested maps safely: m["response"]["item_list"]
func tryGetMap(m map[string]interface{}, keys ...string) (interface{}, bool) {
	var cur interface{} = m
	for _, k := range keys {
		if curMap, ok := cur.(map[string]interface{}); ok {
			cur, ok = curMap[k]
			if !ok {
				return nil, false
			}
		} else {
			return nil, false
		}
	}
	return cur, true
}

// ===== Handler utama =====
func Handler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")

	ctx := context.Background()
	conn, err := getDBConn(ctx)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
		return
	}
	defer conn.Close(ctx)

	var token TokenData
	err = conn.QueryRow(ctx, "SELECT shop_id, access_token FROM shopee_tokens ORDER BY created_at DESC LIMIT 1").
		Scan(&token.ShopID, &token.AccessToken)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil token dari DB: %v"}`, err), http.StatusInternalServerError)
		return
	}

	partnerIDStr := "2013107"
	partnerKey := "shpk5a76537146704b44656a4a6f4f685271464b596b71557353544a71436465"

	var partnerID int64
	fmt.Sscanf(partnerIDStr, "%d", &partnerID)

	// === STEP 1: GET ITEM LIST ===
	timestamp := time.Now().Unix()
	path := "/api/v2/product/get_item_list"
	sign := generateShopeeSign(partnerID, path, token.AccessToken, token.ShopID, timestamp, partnerKey)

	url := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&offset=0&page_size=100&item_status=NORMAL",
		path, partnerID, token.ShopID, timestamp, token.AccessToken, sign,
	)

	resp, err := http.Get(url)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item list: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	var listRes map[string]interface{}
	if err := json.Unmarshal(body, &listRes); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parsing item list: %v","raw":%q}`, err, string(body)), http.StatusInternalServerError)
		return
	}

	// ambil item_id list
	itemListRaw, ok := tryGetMap(listRes, "response", "item")
	if !ok {
		// fallback: kalau struktur lain
		itemListRaw, _ = listRes["response"]
	}
	itemsArray, ok := itemListRaw.([]interface{})
	if !ok || len(itemsArray) == 0 {
		json.NewEncoder(w).Encode(map[string]interface{}{
			"items": []interface{}{},
			"note":  "Tidak ada item ditemukan di toko",
		})
		return
	}

	var itemIDs []int64
	for _, it := range itemsArray {
		if imap, ok := it.(map[string]interface{}); ok {
			if idv, ok := imap["item_id"]; ok {
				if id, ok := parseNumber(idv); ok {
					itemIDs = append(itemIDs, id)
				}
			}
		}
	}

	// join item IDs
	var idStrings []string
	for _, id := range itemIDs {
		idStrings = append(idStrings, fmt.Sprintf("%d", id))
	}
	itemIDJoined := strings.Join(idStrings, ",")

	// === STEP 2: GET ITEM BASE INFO ===
	path2 := "/api/v2/product/get_item_base_info"
	timestamp2 := time.Now().Unix()
	sign2 := generateShopeeSign(partnerID, path2, token.AccessToken, token.ShopID, timestamp2, partnerKey)

	url2 := fmt.Sprintf(
		"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_id_list=%s",
		path2, partnerID, token.ShopID, timestamp2, token.AccessToken, sign2, itemIDJoined,
	)

	resp2, err := http.Get(url2)
	if err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal ambil item base info: %v"}`, err), http.StatusInternalServerError)
		return
	}
	defer resp2.Body.Close()

	body2, _ := io.ReadAll(resp2.Body)
	var baseInfo map[string]interface{}
	if err := json.Unmarshal(body2, &baseInfo); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parse base info: %v","raw":%q}`, err, string(body2)), http.StatusInternalServerError)
		return
	}

	// === STEP 3: GET MODEL LIST (per item) ===
	modelLookup := map[int64][]map[string]interface{}{}

	for _, id := range itemIDs {
		path3 := "/api/v2/product/get_model_list"
		timestamp3 := time.Now().Unix()
		sign3 := generateShopeeSign(partnerID, path3, token.AccessToken, token.ShopID, timestamp3, partnerKey)

		url3 := fmt.Sprintf(
			"https://partner.shopeemobile.com%s?partner_id=%d&shop_id=%d&timestamp=%d&access_token=%s&sign=%s&item_id=%d",
			path3, partnerID, token.ShopID, timestamp3, token.AccessToken, sign3, id,
		)

		// ✅ Cetak URL di CMD
		fmt.Println("🔍 URL Get Item Model:", url3)

		resp3, err := http.Get(url3)
		if err != nil {
			fmt.Println("❌ Gagal ambil model list:", err)
			continue
		}
		body3, _ := io.ReadAll(resp3.Body)
		resp3.Body.Close()

		var modelInfo map[string]interface{}
		if err := json.Unmarshal(body3, &modelInfo); err != nil {
			fmt.Println("⚠️ Gagal parse JSON model list:", err)
			continue
		}

		responseData, ok := modelInfo["response"].(map[string]interface{})
		if !ok {
			fmt.Println("⚠️ Tidak ada field 'response' pada model list:", string(body3))
			continue
		}

		modelArr, ok := responseData["model"].([]interface{})
		if !ok {
			fmt.Println("⚠️ Tidak ada array 'model' pada response:", string(body3))
			continue
		}

		var models []map[string]interface{}
		for _, m := range modelArr {
			if mMap, ok := m.(map[string]interface{}); ok {
				modelSKU, _ := mMap["model_sku"].(string)
				modelName, _ := mMap["model_name"].(string)

				// === Ambil harga dari array price_info ===
				var price int64
				if priceList, ok := mMap["price_info"].([]interface{}); ok && len(priceList) > 0 {
					if pInfo, ok := priceList[0].(map[string]interface{}); ok {
						if cp, ok := pInfo["current_price"]; ok {
							if n, ok := parseNumber(cp); ok {
								price = rupiahFromMicros(n)
							}
						}
					}
				}

				// === Ambil stok dari stock_info_v2.summary_info.total_available_stock ===
				var stock int64
				if stockV2, ok := mMap["stock_info_v2"].(map[string]interface{}); ok {
					if summary, ok := stockV2["summary_info"].(map[string]interface{}); ok {
						if sa, ok := summary["total_available_stock"]; ok {
							if n, ok := parseNumber(sa); ok {
								stock = n
							}
						}
					}
				}

				// ✅ Cetak ke CMD
				fmt.Printf("   ➜ SKU: %s | Varian: %s | Harga: %s | Stok: %d\n",
					modelSKU, modelName, formatRupiah(price), stock)

				models = append(models, map[string]interface{}{
					"model_sku": modelSKU,
					"name":      modelName,
					"price":     price,
					"stock":     stock,
				})
			}
		}

		modelLookup[id] = models
		time.Sleep(500 * time.Millisecond)
	}

	// Ambil item_list dari baseInfo
	var baseItems []interface{}
	if b, ok := tryGetMap(baseInfo, "response", "item_list"); ok {
		if arr, ok := b.([]interface{}); ok {
			baseItems = arr
		}
	}
	// fallback: kadang bernama "item_list" di root response differently
	if baseItems == nil {
		if v, ok := baseInfo["response"]; ok {
			if rv, ok := v.(map[string]interface{}); ok {
				for _, candidate := range []string{"item_list", "item", "items"} {
					if x, ok := rv[candidate]; ok {
						if arr, ok := x.([]interface{}); ok {
							baseItems = arr
							break
						}
					}
				}
			}
		}
	}

	// === Siapkan hasil akhir ===
	finalItems := []map[string]interface{}{}

	for idx, bi := range baseItems {
		var itemName string
		var itemID int64
		var baseSku string
		var priceInt int64
		var stockInt int64

		if bimap, ok := bi.(map[string]interface{}); ok {
			// nama produk
			if v, ok := bimap["item_name"]; ok {
				if s, ok := v.(string); ok {
					itemName = s
				}
			}
			// item_id
			if v, ok := bimap["item_id"]; ok {
				if id, ok := parseNumber(v); ok {
					itemID = id
				}
			}
			// SKU produk
			if v, ok := bimap["item_sku"]; ok {
				if s, ok := v.(string); ok {
					baseSku = s
				}
			}
			// harga utama
			if pi, ok := bimap["price_info"].(map[string]interface{}); ok {
				if cp, ok := pi["current_price"]; ok {
					if n, ok := parseNumber(cp); ok {
						priceInt = rupiahFromMicros(n)
					}
				}
				if priceInt == 0 {
					if op, ok := pi["original_price"]; ok {
						if n, ok := parseNumber(op); ok {
							priceInt = rupiahFromMicros(n)
						}
					}
				}
			}
			// fallback jika ada top-level "price"
			if priceInt == 0 {
				if pv, ok := bimap["price"]; ok {
					if n, ok := parseNumber(pv); ok {
						priceInt = rupiahFromMicros(n)
					}
				}
			}
			// stok utama
			if si, ok := bimap["stock_info"].(map[string]interface{}); ok {
				if ns, ok := si["normal_stock"]; ok {
					if n, ok := parseNumber(ns); ok {
						stockInt = n
					}
				}
			}
			if stockInt == 0 {
				if sv, ok := bimap["stock"]; ok {
					if n, ok := parseNumber(sv); ok {
						stockInt = n
					}
				}
			}
		}

		// Ambil daftar varian (model)
		var variants []map[string]interface{}
		if models, ok := modelLookup[itemID]; ok {
			variants = models
		}

		// Cetak ke CMD untuk debugging
		fmt.Printf("📦 Produk: %s | SKU: %s | Harga: %s | Stok: %d | Jumlah Varian: %d\n",
			itemName, baseSku, formatRupiah(priceInt), stockInt, len(variants))

		// Format harga ke string
		hargaStr := ""
		if priceInt != 0 {
			hargaStr = formatRupiah(priceInt)
		}

		// Simpan hasil ke array final
		item := map[string]interface{}{
			"no":     idx + 1,
			"nama":   itemName,
			"sku":    baseSku,
			"stok":   stockInt,
			"harga":  hargaStr,
			"models": variants, // ✅ tambahkan varian ke JSON
		}

		finalItems = append(finalItems, item)
	}

	// Kirim hasil akhir ke browser (JSON)
	out := map[string]interface{}{
		"count": len(finalItems),
		"items": finalItems,
	}
	json.NewEncoder(w).Encode(out)

	fmt.Println("📦 URL Get Item List:", url)
	fmt.Println("🧾 URL Get Item Base Info:", url2)
	// ===== Helper Functions =====
	for idx, bi := range baseItems {
		fmt.Printf("\n🟡 [DEBUG] Base item index %d: %+v\n", idx, bi)

		var (
			itemName, description, currency, status string
			itemID, categoryID, priceMin, priceMax, priceBeforeDiscount,
			stock, sold, likedCount, historicalSold int64
			rating float64
		)

		if bimap, ok := bi.(map[string]interface{}); ok {
			itemName, _ = bimap["item_name"].(string)
			description, _ = bimap["description"].(string)
			currency, _ = bimap["currency"].(string)
			status, _ = bimap["item_status"].(string)

			if v, ok := parseNumber(bimap["item_id"]); ok {
				itemID = v
			}
			if v, ok := parseNumber(bimap["category_id"]); ok {
				categoryID = v
			}
			if v, ok := parseNumber(bimap["price_min"]); ok {
				priceMin = rupiahFromMicros(v)
			}
			if v, ok := parseNumber(bimap["price_max"]); ok {
				priceMax = rupiahFromMicros(v)
			}
			if v, ok := parseNumber(bimap["price_before_discount"]); ok {
				priceBeforeDiscount = rupiahFromMicros(v)
			}
			if v, ok := parseNumber(bimap["stock"]); ok {
				stock = v
			}
			if v, ok := parseNumber(bimap["sold"]); ok {
				sold = v
			}
			if v, ok := parseNumber(bimap["liked_count"]); ok {
				likedCount = v
			}
			if v, ok := parseNumber(bimap["historical_sold"]); ok {
				historicalSold = v
			}
			if v, ok := bimap["rating_star"].(float64); ok {
				rating = v
			}

			// === INSERT KE TABEL PRODUCT ===
			fmt.Printf("🟢 [PRODUCT] Inserting item_id=%d, name=%s\n", itemID, itemName)
			_, err := conn.Exec(ctx, `
			INSERT INTO product (
				item_id, shop_id, name, description, category_id,
				price_min, price_max, price_before_discount,
				currency, stock, sold, liked_count, rating,
				historical_sold, status, create_time, update_time, is_active
			)
			VALUES (
				$1,$2,$3,$4,$5,
				$6,$7,$8,
				$9,$10,$11,$12,$13,
				$14,$15,NOW(),NOW(),TRUE
			)
			ON CONFLICT (item_id) DO UPDATE
			SET
				name=$3,
				description=$4,
				category_id=$5,
				price_min=$6,
				price_max=$7,
				price_before_discount=$8,
				currency=$9,
				stock=$10,
				sold=$11,
				liked_count=$12,
				rating=$13,
				historical_sold=$14,
				status=$15,
				update_time=NOW();
		`,
				itemID, token.ShopID, itemName, description, categoryID,
				priceMin, priceMax, priceBeforeDiscount,
				currency, stock, sold, likedCount, rating,
				historicalSold, status,
			)
			if err != nil {
				fmt.Printf("❌ Gagal insert ke product item_id=%d: %v\n", itemID, err)
			} else {
				fmt.Printf("✅ Sukses insert ke product item_id=%d\n", itemID)
			}

			// === PRODUCT_MODEL (VARIAN) ===
			models, ok := modelLookup[itemID]
			if !ok || len(models) == 0 {
				// fallback: beberapa produk simpan varian di "models"
				if mlist, ok := bimap["models"].([]interface{}); ok {
					for _, mm := range mlist {
						if mmap, ok := mm.(map[string]interface{}); ok {
							models = append(models, mmap)
						}
					}
				}
			}

			for _, m := range models {
				var (
					modelName  string
					modelSKU   string
					modelPrice int64
					modelStock int64
				)

				modelName, _ = m["name"].(string)
				if modelName == "" {
					modelName, _ = m["model_name"].(string)
				}
				modelSKU, _ = m["model_sku"].(string)
				if v, ok := parseNumber(m["price"]); ok {
					modelPrice = rupiahFromMicros(v)
				}
				if v, ok := parseNumber(m["stock"]); ok {
					modelStock = v
				}

				fmt.Printf("🟢 [MODEL] Menyimpan varian %s (SKU: %s) untuk item_id=%d\n", modelName, modelSKU, itemID)

				_, err := conn.Exec(ctx, `
		INSERT INTO product_model (item_id, name, price, stock, sku, status)
		VALUES ($1, $2, $3, $4, $5, 'ACTIVE')
		ON CONFLICT (item_id, sku) DO UPDATE
		SET price = EXCLUDED.price,
			stock = EXCLUDED.stock,
			name = EXCLUDED.name,
			status = 'ACTIVE';
	`,
					itemID,
					modelName,
					modelPrice,
					modelStock,
					modelSKU,
				)

				if err != nil {
					fmt.Printf("⚠️ Gagal insert model: %v\n", err)
				} else {
					fmt.Printf("✅ Varian [%s] berhasil disimpan\n", modelName)
				}
			}

			// === PRODUCT_IMAGE (GAMBAR) ===
			fmt.Printf("🟡 [DEBUG] Mengecek gambar & atribut untuk item_id=%d\n", itemID)
			if imgInfo, ok := bimap["image"].(map[string]interface{}); ok {
				fmt.Printf("🟢 [IMAGE] Ada image map untuk item_id=%d\n", itemID)
				if imgList, ok := imgInfo["image_url_list"].([]interface{}); ok {
					fmt.Printf("🟢 [IMAGE] Jumlah gambar: %d\n", len(imgList))
					for _, img := range imgList {
						if url, ok := img.(string); ok {
							_, err := conn.Exec(ctx, `
							 INSERT INTO product_image (item_id, image_url)
							VALUES ($1, $2)
							ON CONFLICT (item_id, image_url) DO NOTHING;
						`, itemID, url)
							if err != nil {
								fmt.Printf("❌ Gagal insert gambar: %v\n", err)
							} else {
								fmt.Printf("✅ Gambar disimpan: %s\n", url)
							}
						}
					}
				}
			}

			// === PRODUCT_ATTRIBUTE (ATRIBUT) ===
			if attrs, ok := bimap["attribute_list"].([]interface{}); ok {
				fmt.Printf("🟢 [ATTR] Jumlah atribut: %d\n", len(attrs))
				for _, a := range attrs {
					if amap, ok := a.(map[string]interface{}); ok {
						// Tangani nama atribut
						name := ""
						if v, ok := amap["attribute_name"].(string); ok {
							name = v
						} else if v, ok := amap["original_attribute_name"].(string); ok {
							name = v
						}

						// Tangani nilai atribut (bisa langsung atau dalam array)
						value := ""
						if v, ok := amap["attribute_value"].(string); ok && v != "" {
							value = v
						} else if list, ok := amap["attribute_value_list"].([]interface{}); ok && len(list) > 0 {
							// Ambil semua original_value_name lalu gabungkan jadi satu string
							var vals []string
							for _, lv := range list {
								if m, ok := lv.(map[string]interface{}); ok {
									if vv, ok := m["original_value_name"].(string); ok {
										vals = append(vals, vv)
									}
								}
							}
							value = strings.Join(vals, ", ")
						}

						if name != "" && value != "" {
							_, err := conn.Exec(ctx, `
				 INSERT INTO product_attribute (item_id, attribute_name, attribute_value)
				VALUES ($1, $2, $3)
				ON CONFLICT (item_id, attribute_name, attribute_value) DO NOTHING;
				`, itemID, name, value)
							if err != nil {
								fmt.Printf("❌ Gagal insert atribut: %v\n", err)
							} else {
								fmt.Printf("✅ Atribut disimpan: %s = %s\n", name, value)
							}
						}
					}
				}
			}

			fmt.Printf("✅ Produk #%d [%s] disimpan lengkap\n", idx+1, itemName)
		}
	}

}

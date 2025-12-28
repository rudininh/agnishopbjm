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
func rupiahFromMicros(m int64) int64 {
	if m == 0 {
		return 0
	}
	abs := m
	if abs < 0 {
		abs = -abs
	}
	if abs > 1000000 {
		return m / 100000
	}
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

// sanitizeForSKU makes a short safe SKU fragment from arbitrary string
func sanitizeForSKU(s string) string {
	s = strings.ToUpper(strings.TrimSpace(s))
	res := make([]rune, 0, len(s))
	for _, r := range s {
		if (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') || r == '-' || r == '_' {
			res = append(res, r)
		} else if r == ' ' || r == '/' || r == '\\' || r == ',' {
			res = append(res, '-')
		}
	}
	out := string(res)
	out = strings.Trim(out, "-")
	if out == "" {
		return "X"
	}
	if len(out) > 30 {
		out = out[:30]
	}
	return out
}

// parseModelIDToInt64 tries to parse model_id stored as interface/string/number into int64.
// returns value and ok.
func parseModelIDToInt64(v interface{}) (int64, bool) {
	if v == nil {
		return 0, false
	}
	// if it's a string representation
	switch t := v.(type) {
	case string:
		if t == "" {
			return 0, false
		}
		// try int
		if i, err := strconv.ParseInt(t, 10, 64); err == nil {
			return i, true
		}
		// try float
		if f, err := strconv.ParseFloat(t, 64); err == nil {
			return int64(f), true
		}
		return 0, false
	case float64:
		return int64(t), true
	case float32:
		return int64(t), true
	case int:
		return int64(t), true
	case int64:
		return t, true
	case json.Number:
		if i, err := t.Int64(); err == nil {
			return i, true
		}
		if f, err := t.Float64(); err == nil {
			return int64(f), true
		}
		return 0, false
	default:
		// fallback to fmt.Sprintf then parse
		s := fmt.Sprintf("%v", v)
		return parseNumber(s)
	}
}

// ===== Handler utama =====
func ShopeeGetItemsHandler(w http.ResponseWriter, r *http.Request) {
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
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()

	var listRes map[string]interface{}
	if err := json.Unmarshal(body, &listRes); err != nil {
		http.Error(w, fmt.Sprintf(`{"error":"Gagal parsing item list: %v","raw":%q}`, err, string(body)), http.StatusInternalServerError)
		return
	}

	// ambil item_id list
	itemListRaw, ok := tryGetMap(listRes, "response", "item")
	if !ok {
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
	body2, _ := io.ReadAll(resp2.Body)
	resp2.Body.Close()

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

		// Cetak URL di CMD
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
			// tidak ada model array, lanjut
			modelLookup[id] = []map[string]interface{}{}
			continue
		}

		var models []map[string]interface{}
		for _, m := range modelArr {
			if mMap, ok := m.(map[string]interface{}); ok {
				modelSKU, _ := mMap["model_sku"].(string)
				modelName, _ := mMap["model_name"].(string)

				modelID := ""
				if mid, ok := mMap["model_id"]; ok {
					switch mm := mid.(type) {
					case float64:
						modelID = fmt.Sprintf("%.0f", mm)
					case int:
						modelID = fmt.Sprintf("%d", mm)
					case int64:
						modelID = fmt.Sprintf("%d", mm)
					case string:
						modelID = mm
					default:
						modelID = fmt.Sprintf("%v", mm)
					}
				}

				// Ambil harga dari array price_info
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

				// Ambil stok
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
				if stock == 0 {
					if v, ok := mMap["stock"]; ok {
						if n, ok := parseNumber(v); ok {
							stock = n
						}
					}
				}

				fmt.Printf("   ➜ SKU: %s | Varian: %s | Harga: %s | Stok: %d\n",
					modelSKU, modelName, formatRupiah(price), stock)

				models = append(models, map[string]interface{}{
					"model_sku": modelSKU,
					"model_id":  modelID,
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

	// === Siapkan hasil akhir untuk response ke browser ===
	finalItems := []map[string]interface{}{}

	for idx, bi := range baseItems {
		var itemName string
		var itemID int64
		var baseSku string
		var priceInt int64
		var stockInt int64

		if bimap, ok := bi.(map[string]interface{}); ok {
			if v, ok := bimap["item_name"]; ok {
				if s, ok := v.(string); ok {
					itemName = s
				}
			}
			if v, ok := bimap["item_id"]; ok {
				if id, ok := parseNumber(v); ok {
					itemID = id
				}
			}
			if v, ok := bimap["item_sku"]; ok {
				if s, ok := v.(string); ok {
					baseSku = s
				}
			}
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
			if priceInt == 0 {
				if pv, ok := bimap["price"]; ok {
					if n, ok := parseNumber(pv); ok {
						priceInt = rupiahFromMicros(n)
					}
				}
			}
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

		var variants []map[string]interface{}
		if models, ok := modelLookup[itemID]; ok {
			variants = models
		}

		fmt.Printf("📦 Produk: %s | SKU: %s | Harga: %s | Stok: %d | Jumlah Varian: %d\n",
			itemName, baseSku, formatRupiah(priceInt), stockInt, len(variants))

		hargaStr := ""
		if priceInt != 0 {
			hargaStr = formatRupiah(priceInt)
		}

		item := map[string]interface{}{
			"no":     idx + 1,
			"nama":   itemName,
			"sku":    baseSku,
			"stok":   stockInt,
			"harga":  hargaStr,
			"models": variants,
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

	// ===== Sinkronisasi DB (shopee_product, shopee_product_model, stock_master, sku_mapping, shopee_product_image) =====
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

			// INSERT / UPDATE shopee_product
			fmt.Printf("🟢 [PRODUCT] Inserting item_id=%d, name=%s\n", itemID, itemName)
			_, err := conn.Exec(ctx, `
			INSERT INTO shopee_product (
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
				fmt.Printf("❌ Gagal insert ke shopee_product item_id=%d: %v\n", itemID, err)
			} else {
				fmt.Printf("✅ Sukses insert ke shopee_product item_id=%d\n", itemID)
			}

			// models dari modelLookup (hasil get_model_list)
			models, _ := modelLookup[itemID]
			// fallback: jika tidak ada, periksa bimap["models"] (lama)
			if len(models) == 0 {
				if mlist, ok := bimap["models"].([]interface{}); ok {
					for _, mm := range mlist {
						if mmap, ok := mm.(map[string]interface{}); ok {
							// normalisasi keys supaya structure sama seperti modelLookup entry
							modelSKU, _ := mmap["model_sku"].(string)
							modelName, _ := mmap["model_name"].(string)
							modelID := ""
							if v, ok := mmap["model_id"]; ok {
								switch t := v.(type) {
								case float64:
									modelID = fmt.Sprintf("%.0f", t)
								case int:
									modelID = fmt.Sprintf("%d", t)
								case int64:
									modelID = fmt.Sprintf("%d", t)
								case string:
									modelID = t
								default:
									modelID = fmt.Sprintf("%v", t)
								}
							}
							price, _ := parseNumber(mmap["price"])
							stockVar, _ := parseNumber(mmap["stock"])
							models = append(models, map[string]interface{}{
								"model_sku": modelSKU,
								"model_id":  modelID,
								"name":      modelName,
								"price":     rupiahFromMicros(price),
								"stock":     stockVar,
							})
						}
					}
				}
			}

			for _, m := range models {
				var (
					modelName  string
					modelPrice int64
					modelStock int64
					modelID    string
					modelSKU   string
				)

				// normalize read
				if v, ok := m["name"].(string); ok {
					modelName = v
				}
				if v, ok := m["model_sku"].(string); ok {
					modelSKU = v
				}
				// model_id already stored as string in modelLookup
				if v, ok := m["model_id"].(string); ok {
					modelID = v
				} else if v, ok := m["model_id"]; ok {
					modelID = fmt.Sprintf("%v", v)
				}

				if v, ok := m["price"].(int64); ok {
					modelPrice = v
				} else if v, ok := m["price"].(float64); ok {
					modelPrice = int64(v)
				} else if v, ok := m["price"]; ok {
					if n, ok := parseNumber(v); ok {
						modelPrice = rupiahFromMicros(n)
					}
				}

				if v, ok := m["stock"].(int64); ok {
					modelStock = v
				} else if v, ok := m["stock"].(float64); ok {
					modelStock = int64(v)
				} else if v, ok := m["stock"]; ok {
					if n, ok := parseNumber(v); ok {
						modelStock = n
					}
				}

				fmt.Printf("🟢 [VARIAN] Simpan varian %s (model_id=%s) item_id=%d\n", modelName, modelID, itemID)

				// Insert/update varian ke shopee_product_model
				_, err := conn.Exec(ctx, `
					INSERT INTO shopee_product_model (model_id, item_id, name, price, stock, updated_at)
					VALUES ($1,$2,$3,$4,$5,NOW())
					ON CONFLICT (model_id, item_id)
					DO UPDATE SET
						name = EXCLUDED.name,
						price = EXCLUDED.price,
						stock = EXCLUDED.stock,
						updated_at = NOW();
				`, modelID, itemID, modelName, modelPrice, modelStock)

				if err != nil {
					fmt.Printf("❌ Gagal insert varian: %v\n", err)
				}

				// internal SKU per varian
				skuFragment := modelSKU
				if skuFragment == "" {
					skuFragment = modelName
				}
				internalSKU := fmt.Sprintf("INT-%d-%s", itemID, sanitizeForSKU(skuFragment))

				// UPsert stock_master
				_, err = conn.Exec(ctx, `
					INSERT INTO stock_master (
						internal_sku,
						shopee_product_id,
						shopee_sku,
						product_name,
						variant_name,
						stock_qty,
						tiktok_product_id,
						tiktok_sku,
						updated_at
					)
					VALUES ($1,$2,$3,$4,$5,$6,$7,$8,NOW())
					ON CONFLICT (internal_sku) DO UPDATE SET
						shopee_product_id = EXCLUDED.shopee_product_id,
						shopee_sku        = EXCLUDED.shopee_sku,
						product_name      = EXCLUDED.product_name,
						variant_name      = EXCLUDED.variant_name,
						stock_qty         = EXCLUDED.stock_qty,
						updated_at        = NOW();
				`,
					internalSKU,
					fmt.Sprint(itemID),
					fmt.Sprint(modelID),
					itemName,
					modelName,
					modelStock,
					"", // product_id_tiktok (kosong dulu)
					"", // sku_tiktok (kosong dulu)
				)

				if err != nil {
					fmt.Printf("❌ Gagal upsert stock_master internal_sku=%s : %v\n", internalSKU, err)
				}

			}

			// =====================================
			// ========== PRODUCT IMAGE (SHOPEE) ============
			// =====================================

			fmt.Printf("🟡 [DEBUG] Mengecek gambar & atribut untuk item_id=%d\n", itemID)

			// 1) Simpan gambar utama (image.image_url_list) -> model_id NULL
			if imgInfo, ok := bimap["image"].(map[string]interface{}); ok {
				if imgList, ok := imgInfo["image_url_list"].([]interface{}); ok {
					fmt.Printf("🟢 [IMAGE] Jumlah gambar utama: %d\n", len(imgList))
					for _, img := range imgList {
						if urlStr, ok := img.(string); ok {
							_, err := conn.Exec(ctx, `
                    INSERT INTO shopee_product_image (item_id, model_id, image_url, created_at)
                    VALUES ($1, NULL, $2, NOW())
                    ON CONFLICT (item_id, model_id, image_url) DO NOTHING;
                `, itemID, urlStr)
							if err != nil {
								fmt.Printf("❌ Gagal insert gambar utama: %v\n", err)
							} else {
								fmt.Printf("✅ Gambar utama disimpan: %s\n", urlStr)
							}
						}
					}
				}
			}

			// 2) Build variation image map from available fields in baseInfo (tier_variation / standardise_tier_variation).
			// Note: sometimes these exist in model API, sometimes in base info. We'll try both places gracefully.
			variationImageMap := make(map[string]string)

			// a) from base item (bimap) tier_variation.option_list.image.image_url
			if tiers, ok := bimap["tier_variation"].([]interface{}); ok {
				for _, t := range tiers {
					if tier, _ := t.(map[string]interface{}); tier != nil {
						if optList, ok := tier["option_list"].([]interface{}); ok {
							for _, optRaw := range optList {
								if opt, _ := optRaw.(map[string]interface{}); opt != nil {
									optionName := strings.TrimSpace(fmt.Sprintf("%v", opt["option"]))
									if imgObj, ok := opt["image"].(map[string]interface{}); ok {
										if urlStr, ok := imgObj["image_url"].(string); ok && urlStr != "" {
											variationImageMap[optionName] = urlStr
										}
									}
								}
							}
						}
					}
				}
			}

			// b) from base item standardise_tier_variation.variation_option_list.image_url
			if stdTV, ok := bimap["standardise_tier_variation"].([]interface{}); ok {
				for _, vRaw := range stdTV {
					if v, _ := vRaw.(map[string]interface{}); v != nil {
						if optList, ok := v["variation_option_list"].([]interface{}); ok {
							for _, optRaw := range optList {
								if opt, _ := optRaw.(map[string]interface{}); opt != nil {
									optionName := strings.TrimSpace(fmt.Sprintf("%v", opt["variation_option_name"]))
									if urlStr, ok := opt["image_url"].(string); ok && urlStr != "" {
										variationImageMap[optionName] = urlStr
									}
								}
							}
						}
					}
				}
			}

			// Additionally, try to get variation images from model list response if present there (we used modelLookup earlier)
			// modelLookup may contain only model entries but not option-image mapping, however some responses include image URLs inside model entries -> attempt that
			if modelsFromLookup, ok := modelLookup[itemID]; ok {
				for _, mm := range modelsFromLookup {
					// mm may contain "name" which matches variation option (e.g., "Merah")
					nameKey := fmt.Sprintf("%v", mm["name"])
					// sometimes model entry contains image info like "image" or "image_url" (rare) - try to pick it
					if img, ok := mm["image"]; ok {
						if imgStr, ok := img.(string); ok && imgStr != "" {
							variationImageMap[nameKey] = imgStr
						}
					}
				}
			}

			// 3) Simpan gambar varian berdasarkan modelLookup (jangan pakai bimap["model"] langsung)
			if modelsFromLookup, ok := modelLookup[itemID]; ok {
				for _, mm := range modelsFromLookup {
					// model id biasanya string in modelLookup
					modelIDRaw := mm["model_id"]
					modelNameKey := fmt.Sprintf("%v", mm["name"])
					// parse model id to int64 if possible
					modelIDNum, okID := parseModelIDToInt64(modelIDRaw)
					// find matching image by variation name
					imgURL, okImg := variationImageMap[modelNameKey]
					if !okImg {
						// try alternative keys: sometimes option uses uppercase/lowercase differences
						imgURL, okImg = variationImageMap[strings.TrimSpace(modelNameKey)]
						if !okImg {
							imgURL, okImg = variationImageMap[strings.ToLower(strings.TrimSpace(modelNameKey))]
						}
					}
					if !okImg {
						// no image for this option: skip but log
						fmt.Printf("⚠️ [MODEL-IMAGE] Tidak ada image untuk opsi '%s' (item_id=%d)\n", modelNameKey, itemID)
						continue
					}

					// insert: use model_id when available, otherwise NULL
					if okID {
						_, err := conn.Exec(ctx, `
                    INSERT INTO shopee_product_image (item_id, model_id, image_url, created_at)
                    VALUES ($1, $2, $3, NOW())
                    ON CONFLICT (item_id, model_id, image_url) DO NOTHING;
                `, itemID, modelIDNum, imgURL)
						if err != nil {
							fmt.Printf("❌ Gagal insert gambar varian (model_id=%d): %v\n", modelIDNum, err)
						} else {
							fmt.Printf("✅ [MODEL-IMAGE] model_id=%d → %s\n", modelIDNum, imgURL)
						}
					} else {
						// model_id tidak tersedia / tidak parseable -> simpan dengan NULL model_id but record image and maybe include model name in another column (not present here), so just save with NULL
						_, err := conn.Exec(ctx, `
                    INSERT INTO shopee_product_image (item_id, model_id, image_url, created_at)
                    VALUES ($1, NULL, $2, NOW())
                    ON CONFLICT (item_id, model_id, image_url) DO NOTHING;
                `, itemID, imgURL)
						if err != nil {
							fmt.Printf("❌ Gagal insert gambar varian (no model_id): %v\n", err)
						} else {
							fmt.Printf("✅ [MODEL-IMAGE] (no model_id) %s\n", imgURL)
						}
					}
				}
			}

			fmt.Printf("🟢 Selesai memproses seluruh gambar untuk item_id=%d\n", itemID)

			fmt.Printf("✅ Produk #%d [%s] disimpan lengkap\n", idx+1, itemName)
		}
	}

}

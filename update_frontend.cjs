const fs = require('fs');
let code = fs.readFileSync('frontend/src/pages/ImportMarketplace.vue', 'utf8');

// Replace table section to include advanced template
const tableReplacement = `
            <tr>
              <td>
                <strong>Simplified Mass Update</strong>
                <span>Siap</span>
              </td>
              <td>lazada_mass_update.xlsx</td>
              <td>Nama produk, foto, harga, stok, SKU, varian, dan dimensi paket dari data Shopee / stock master.</td>
              <td>
                <button class="mini lazada-btn" type="button" :disabled="downloadingLazada" @click="downloadLazadaMassUpdate">
                  Download Excel
                </button>
              </td>
            </tr>
            <tr>
              <td>
                <strong>Advanced Publish (Creation)</strong>
                <span>Siap</span>
              </td>
              <td>lazada_advanced_publish.xlsx</td>
              <td>Template export format pembuatan produk lengkap untuk lazada.</td>
              <td>
                <button class="mini lazada-btn" type="button" :disabled="downloadingLazadaAdvanced" @click="downloadLazadaAdvancedUpdate">
                  Download Excel
                </button>
              </td>
            </tr>`;
code = code.replace(/<tr>\s*<td>\s*<strong>Simplified Mass Update.*?<\/tr>/ms, tableReplacement);

// Add variables and functions
const scriptReplacement = `
const downloadingLazada = ref(false)
const downloadingLazadaAdvanced = ref(false)
const syncResults = ref([])
const shopeeGitaMassUpdateUrl = '/api/marketplace/import/shopee-gita/mass-update'
const shopeeGitaMassUpdateFileUrl = (type) => \`\${shopeeGitaMassUpdateUrl}/\${type}\`
const lazadaMassUpdateUrl = '/api/marketplace/import/lazada/mass-update'
const lazadaAdvancedUpdateUrl = '/api/marketplace/import/lazada/advanced-update'

const downloadLazadaMassUpdate = () => {
  downloadingLazada.value = true
  notice.value = {
    type: 'success',
    message: 'Download Mass Update Lazada (Simplified) sedang disiapkan.'
  }
  downloadUrl(lazadaMassUpdateUrl)
  window.setTimeout(() => {
    downloadingLazada.value = false
  }, 1200)
}

const downloadLazadaAdvancedUpdate = () => {
  downloadingLazadaAdvanced.value = true
  notice.value = {
    type: 'success',
    message: 'Download Advanced Publish Lazada sedang disiapkan.'
  }
  downloadUrl(lazadaAdvancedUpdateUrl)
  window.setTimeout(() => {
    downloadingLazadaAdvanced.value = false
  }, 1200)
}
`;

code = code.replace(
  /const downloadingLazada = ref\(false\).*?const lazadaMassUpdateUrl = '[^']+'/ms,
  scriptReplacement
);

fs.writeFileSync('frontend/src/pages/ImportMarketplace.vue', code);

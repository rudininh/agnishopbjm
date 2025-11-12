import { Client } from "pg";

export default async function handler(req, res) {
    try {
        // TikTok kirim data lewat query string, bukan body
        const { code, app_key, shop_region, state } = req.query;

        if (!code) {
            return res.status(400).json({ error: "Missing code" });
        }

        const client = new Client({
            connectionString: process.env.DATABASE_URL,
            ssl: { rejectUnauthorized: false },
        });
        await client.connect();

        await client.query(`
      CREATE TABLE IF NOT EXISTS tiktok_callbacks (
        id SERIAL PRIMARY KEY,
        code TEXT,
        app_key TEXT,
        shop_region TEXT,
        state TEXT,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

        await client.query(
            "INSERT INTO tiktok_callbacks (code, app_key, shop_region, state) VALUES ($1, $2, $3, $4)",
            [code, app_key, shop_region, state]
        );

        await client.end();

        // ✅ Redirect ke dashboard setelah sukses
        return res.redirect(302, "https://agnishopbjm.vercel.app/dashboard.html");
    } catch (err) {
        console.error("❌ Error TikTok Callback:", err.message);
        return res.status(500).json({ error: err.message });
    }
}

https://auth.tiktok-shops.com/api/v2/token/get?app_key=6i1cagd9f0p83&app_secret=3710881f177a1e6b03664cc91d8a3516001a0bc7&auth_code=ROW_jZNP5gAAAAB8l5P-lFKcuj7exgqe6kx3W6DtyyDCPPdYtbreqSCutZ1Ebedl_szc1B6pNZAwNC2XjmEG7ySdg0k000_nMgyonTxzvmTxtlN6qU2cn7VlBjkb60QBpIqnCXb1ssKjvMFCN2U_UMaO1-p1ZSelAMZcb8BVKAT_jLkenWPBTiNlfb9mnlCDskAOIReZbQsS-dV_8cT6hpXoPtGi0odSzh7TyPn0xGT7Vl-nlpv5YZRYovjW9uZT25Y26ngxVVGii2fUY_UV3jagBT_GTY1LcqVAzYlGhU47qtDeDQbwOAssuWv_XBCc4K-NnnuOaa6PuuZzngUQFmvzcjXiFWNbrkrqR882V_DSDOKg92WWA2t7GYF6iZ7etP-0RwdjYykI4eK4MNw1RkEoIOkSe3L658bOuXe4I2fqI_Gdt7W--M_xVsuT_g6ueL5ZMi-GmGfBBskui7SD5qsIeTTH4HO1RVZbAqEEgQ9j1epy3O7jcUySut1iK8wKVrCbbbj0lJRbqtv1MJeD54cRKsWncDRVeBLBJXpX_SMnnR1VHGWXXkcltg&grant_type=authorized_code
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

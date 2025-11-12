import { Client } from "pg";

export default async function handler(req, res) {
    try {
        // TikTok kirim "code" dan "state" lewat query
        const { code, state } = req.query;

        if (!code) {
            return res.status(400).json({ error: "Missing code parameter" });
        }

        // === Koneksi ke database ===
        const client = new Client({
            connectionString: process.env.DATABASE_URL,
            ssl: { rejectUnauthorized: false },
        });
        await client.connect();

        // === Buat tabel jika belum ada ===
        await client.query(`
      CREATE TABLE IF NOT EXISTS tiktok_callbacks (
        id SERIAL PRIMARY KEY,
        code TEXT,
        state TEXT,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

        // === Simpan code ke database ===
        await client.query(
            "INSERT INTO tiktok_callbacks (code, state) VALUES ($1, $2)",
            [code, state]
        );

        await client.end();

        // ✅ Redirect ke halaman dashboard kamu
        return res.redirect(302, "https://agnishopbjm.vercel.app/dashboard.html");
    } catch (err) {
        console.error("❌ Error TikTok Callback:", err.message);
        return res.status(500).json({ error: err.message });
    }
}

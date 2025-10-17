

import { Client } from "pg";

console.log("DATABASE_URL:", process.env.DATABASE_URL);


export default async function handler(req, res) {
  try {
    const { code, shop_id } = req.query;

    if (!code || !shop_id) {
      return res.status(400).json({ error: "Missing code or shop_id" });
    }

    const client = new Client({
      connectionString: process.env.DATABASE_URL,
      ssl: { rejectUnauthorized: false },
    });
    await client.connect();

    await client.query(`
      CREATE TABLE IF NOT EXISTS shopee_callbacks (
        id SERIAL PRIMARY KEY,
        code TEXT,
        shop_id TEXT,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    await client.query(
      "INSERT INTO shopee_callbacks (code, shop_id) VALUES ($1, $2)",
      [code, shop_id]
    );

    await client.end();

    // ✅ Redirect ke homepage (bukan JSON)
    return res.redirect(302, "https://agnishopbjm.vercel.app/");
  } catch (err) {
    console.error("❌ Error:", err.message);
    return res.status(500).json({ error: err.message });
  }
}

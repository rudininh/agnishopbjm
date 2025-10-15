import { Client } from 'pg';

export async function handler(event) {
  try {
    // Ambil query string dari URL callback (?code=123&shop_id=456)
    const params = new URLSearchParams(event.rawQuery);
    const code = params.get("code");
    const shop_id = params.get("shop_id");

    if (!code || !shop_id) {
      return {
        statusCode: 400,
        body: "Missing code or shop_id",
      };
    }

    // Koneksi ke Neon PostgreSQL
    const client = new Client({
      connectionString: process.env.DATABASE_URL,
      ssl: { rejectUnauthorized: false },
    });

    await client.connect();

    // Buat tabel kalau belum ada
    await client.query(`
      CREATE TABLE IF NOT EXISTS shopee_callbacks (
        id SERIAL PRIMARY KEY,
        code TEXT,
        shop_id TEXT,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    // Simpan data callback
    await client.query(
      "INSERT INTO shopee_callbacks (code, shop_id) VALUES ($1, $2)",
      [code, shop_id]
    );

    await client.end();

    // 🔁 Redirect ke halaman utama (tanpa tampil JSON)
    return {
      statusCode: 302, // redirect
      headers: {
        Location: "https://agnishopbjm.vercel.app/",
      },
    };
  } catch (err) {
    return {
      statusCode: 500,
      body: "Server Error: " + err.message,
    };
  }
}

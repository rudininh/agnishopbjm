// // import { Client } from 'pg';

// // export async function handler(event) {
// //   try {
// //     // Ambil query string dari URL callback (?code=123&shop_id=456)
// //     const params = new URLSearchParams(event.rawQuery);
// //     const code = params.get("code");
// //     const shop_id = params.get("shop_id");

// //     if (!code || !shop_id) {
// //       return {
// //         statusCode: 400,
// //         body: "Missing code or shop_id",
// //       };
// //     }

// //     // Koneksi ke Neon PostgreSQL
// //     const client = new Client({
// //       connectionString: process.env.DATABASE_URL,
// //       ssl: { rejectUnauthorized: false },
// //     });

// //     await client.connect();

// //     // Buat tabel kalau belum ada
// //     await client.query(`
// //       CREATE TABLE IF NOT EXISTS shopee_callbacks (
// //         id SERIAL PRIMARY KEY,
// //         code TEXT,
// //         shop_id TEXT,
// //         created_at TIMESTAMP DEFAULT NOW()
// //       )
// //     `);

// //     // Simpan data callback
// //     await client.query(
// //       "INSERT INTO shopee_callbacks (code, shop_id) VALUES ($1, $2)",
// //       [code, shop_id]
// //     );

// //     await client.end();

// //     // 🔁 Redirect ke halaman utama (tanpa tampil JSON)
// //     return {
// //       statusCode: 302, // redirect
// //       headers: {
// //         Location: "https://agnishopbjm.vercel.app/",
// //       },
// //     };
// //   } catch (err) {
// //     return {
// //       statusCode: 500,
// //       body: "Server Error: " + err.message,
// //     };
// //   }
// // }


// port default async function handler(req, res) {
//   try {
//     const { code, shop_id } = req.query;

//     if (!code || !shop_id) {
//       return res.status(400).json({ error: "Missing code or shop_id" });
//     }

//     const client = new Client({
//       connectionString: process.env.DATABASE_URL,
//       ssl: { rejectUnauthorized: false },
//     });
//     await client.connect();

//     await client.query(`
//       CREATE TABLE IF NOT EXISTS shopee_callbacks (
//         id SERIAL PRIMARY KEY,
//         code TEXT,
//         shop_id TEXT,
//         created_at TIMESTAMP DEFAULT NOW()
//       )
//     `);

//     await client.query(
//       "INSERT INTO shopee_callbacks (code, shop_id) VALUES ($1, $2)",
//       [code, shop_id]
//     );

//     await client.end();

//     // redirect ke halaman utama
//     return res.redirect(302, "https://agnishopbjm.vercel.app/");
//   } catch (err) {
//     return res.status(500).json({ error: err.message });
//   }
// }

import { Client } from "pg";

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

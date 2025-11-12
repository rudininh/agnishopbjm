// import { Client } from "pg";

// export default async function handler(req, res) {
//     try {
//         // TikTok kirim data lewat query string, bukan body
//         const { code, app_key, shop_region, state } = req.query;

//         if (!code) {
//             return res.status(400).json({ error: "Missing code" });
//         }

//         const client = new Client({
//             connectionString: process.env.DATABASE_URL,
//             ssl: { rejectUnauthorized: false },
//         });
//         await client.connect();

//         await client.query(`
//       CREATE TABLE IF NOT EXISTS tiktok_callbacks (
//         id SERIAL PRIMARY KEY,
//         code TEXT,
//         app_key TEXT,
//         shop_region TEXT,
//         state TEXT,
//         created_at TIMESTAMP DEFAULT NOW()
//       )
//     `);

//         await client.query(
//             "INSERT INTO tiktok_callbacks (code, app_key, shop_region, state) VALUES ($1, $2, $3, $4)",
//             [code, app_key, shop_region, state]
//         );

//         await client.end();

//         // ✅ Redirect ke dashboard setelah sukses
//         return res.redirect(302, "https://agnishopbjm.vercel.app/dashboard.html");
//     } catch (err) {
//         console.error("❌ Error TikTok Callback:", err.message);
//         return res.status(500).json({ error: err.message });
//     }
// }


import { Client } from "pg";

export default async function handler(req, res) {
    try {
        const { code, app_key, shop_region, state } = req.query || {};

        if (!code) {
            return res.status(400).json({ error: "Missing code" });
        }

        // 1️⃣ Tukar code jadi access_token langsung ke TikTok
        const tokenResponse = await fetch("https://auth.tiktok-shops.com/api/v2/token/get", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                app_key: process.env.TIKTOK_APP_KEY,
                app_secret: process.env.TIKTOK_APP_SECRET,
                auth_code: code,
                grant_type: "authorized_code",
            }),
        });

        const tokenData = await tokenResponse.json();
        console.log("✅ Token TikTok:", tokenData);

        if (!tokenData.data || !tokenData.data.access_token) {
            return res.status(400).json({
                error: "Failed to obtain access token",
                detail: tokenData,
            });
        }

        // 2️⃣ Simpan code + token ke database
        const client = new Client({
            connectionString: process.env.DATABASE_URL,
            ssl: { rejectUnauthorized: false },
        });
        await client.connect();

        await client.query(`
      CREATE TABLE IF NOT EXISTS tiktok_tokens (
        id SERIAL PRIMARY KEY,
        code TEXT,
        app_key TEXT,
        shop_region TEXT,
        state TEXT,
        access_token TEXT,
        refresh_token TEXT,
        access_expire INT,
        refresh_expire INT,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

        const d = tokenData.data;
        await client.query(
            `INSERT INTO tiktok_tokens 
        (code, app_key, shop_region, state, access_token, refresh_token, access_expire, refresh_expire)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8)`,
            [code, app_key, shop_region, state, d.access_token, d.refresh_token, d.access_token_expire_in, d.refresh_token_expire_in]
        );

        await client.end();

        // 3️⃣ Redirect ke dashboard
        return res.redirect(302, "https://agnishopbjm.vercel.app/dashboard.html");
    } catch (err) {
        console.error("❌ Error TikTok Callback:", err.message);
        return res.status(500).json({ error: err.message });
    }
}

// itemsshopee.js
import fs from 'fs';
import pkg from 'pg';
const { Client } = pkg;

const client = new Client({
    connectionString: process.env.DATABASE_URL, // sama seperti di Go
    ssl: { rejectUnauthorized: false },
});

async function insertData() {
    const data = JSON.parse(fs.readFileSync('items.json', 'utf8'));

    await client.connect();

    for (const item of data) {
        try {
            await client.query(
                `INSERT INTO shopee_products (item_id, item_name, item_sku, price, stock, currency, status, create_time, update_time)
         VALUES ($1,$2,$3,$4,$5,'IDR','active',NOW(),NOW())
         ON CONFLICT (item_id) DO UPDATE
         SET item_name = EXCLUDED.item_name,
             item_sku = EXCLUDED.item_sku,
             price = EXCLUDED.price,
             stock = EXCLUDED.stock,
             update_time = NOW()`,
                [
                    item.item_id || null,
                    item.item_name || '',
                    item.item_sku || '',
                    item.price || 0,
                    item.stock || 0,
                ]
            );
            console.log(`✅ Inserted: ${item.item_name}`);
        } catch (err) {
            console.error(`❌ Error item ${item.item_name}:`, err.message);
        }
    }

    await client.end();
    console.log('🎯 Selesai input ke database!');
}

insertData();

import { Client } from 'pg';

export default async function handler(req, res) {
  const { code, shop_id } = req.query;

  const client = new Client({
    connectionString: process.env.DATABASE_URL,
    ssl: { rejectUnauthorized: false },
  });

  try {
    await client.connect();
    await client.query(
      'INSERT INTO shopee_callback (code, shop_id, created_at) VALUES ($1, $2, NOW())',
      [code, shop_id]
    );
    await client.end();

    return res.status(200).json({ message: 'Data saved!' });
  } catch (err) {
    console.error(err);
    return res.status(500).json({ error: 'Database error' });
  }
}

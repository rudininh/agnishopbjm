import { Client } from 'pg';

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  const { username, password } = req.body;

  try {
    const client = new Client({
      connectionString: process.env.DATABASE_URL,
      ssl: { rejectUnauthorized: false },
    });
    await client.connect();

    const result = await client.query(
      'SELECT * FROM admin WHERE username=$1 AND password=$2',
      [username, password]
    );

    await client.end();

    if (result.rows.length > 0) {
      // Simpan sesi login di cookie (sederhana)
      res.setHeader(
        'Set-Cookie',
        `admin_logged_in=true; Path=/; HttpOnly; Max-Age=3600;`
      );
      return res.status(200).json({ success: true, redirect: '/dashboard.html' });
    } else {
      return res.status(401).json({ success: false, message: 'Username atau password salah!' });
    }
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
}

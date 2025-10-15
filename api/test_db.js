import { neon } from '@netlify/neon';

export default async (req, context) => {
  try {
    const sql = neon(); // otomatis pakai NETLIFY_DATABASE_URL dari environment
    const result = await sql`SELECT NOW() AS current_time`;
    return new Response(
      JSON.stringify({ success: true, result }),
      { status: 200, headers: { 'Content-Type': 'application/json' } }
    );
  } catch (err) {
    return new Response(
      JSON.stringify({ success: false, error: err.message }),
      { status: 500, headers: { 'Content-Type': 'application/json' } }
    );
  }
};

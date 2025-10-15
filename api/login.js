import { neon } from "@netlify/neon";
import jwt from "jsonwebtoken";
import bcrypt from "bcryptjs";

export default async (req, context) => {
  try {
    const { username, password } = await req.json();
    const sql = neon(process.env.NETLIFY_DATABASE_URL);
    const JWT_SECRET = process.env.JWT_SECRET || "supersecretkey";

    // Cari user di database
    const rows = await sql`SELECT * FROM admin_users WHERE username = ${username}`;
    if (rows.length === 0) {
      return Response.json({ success: false, message: "Username tidak ditemukan" });
    }

    const user = rows[0];
    const isValid = await bcrypt.compare(password, user.password_hash);

    if (!isValid) {
      return Response.json({ success: false, message: "Password salah!" });
    }

    // Generate JWT token
    const token = jwt.sign({ id: user.id, username: user.username }, JWT_SECRET, { expiresIn: "2h" });

    return Response.json({ success: true, token, username: user.username });
  } catch (err) {
    console.error(err);
    return Response.json({ success: false, message: "Terjadi kesalahan server." }, { status: 500 });
  }
};

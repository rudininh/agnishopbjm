export default async function handler(req, res) {
  try {
    const { code } = req.query;
    const localCallback =
      process.env.LOCAL_TIKTOK_CALLBACK_URL ||
      "http://agnishopbjm-laravel.test/api/tiktok/callback";

    if (!code) {
      return res.status(400).json({ error: "Missing code" });
    }

    const url = new URL(localCallback);

    for (const [key, value] of Object.entries(req.query)) {
      if (Array.isArray(value)) {
        value.forEach((item) => url.searchParams.append(key, item));
      } else if (value !== undefined) {
        url.searchParams.set(key, value);
      }
    }

    return res.redirect(302, url.toString());
  } catch (error) {
    console.error("TikTok callback bridge error:", error.message);
    return res.status(500).json({ error: error.message });
  }
}

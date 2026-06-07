const readHeader = (req, name) => {
  const value = req.headers[name.toLowerCase()];
  return Array.isArray(value) ? value[0] : value;
};

const bearerToken = (req) => {
  const authorization = readHeader(req, "authorization") || "";
  return authorization.toLowerCase().startsWith("bearer ")
    ? authorization.slice(7).trim()
    : "";
};

const ensureBridgeAuthorized = (req) => {
  const expected =
    process.env.AUTO_SYNC_SCHEDULER_BRIDGE_TOKEN ||
    process.env.CRON_SECRET ||
    "";

  if (!expected) {
    return true;
  }

  const given =
    bearerToken(req) ||
    readHeader(req, "x-runner-token") ||
    req.query?.token ||
    "";

  return given === expected;
};

export default async function handler(req, res) {
  if (!["GET", "POST"].includes(req.method)) {
    res.setHeader("Allow", "GET, POST");
    return res.status(405).json({ error: "Method not allowed" });
  }

  if (!ensureBridgeAuthorized(req)) {
    return res.status(401).json({ error: "Invalid scheduler bridge token" });
  }

  const targetUrl = process.env.AUTO_SYNC_SCHEDULER_TARGET_URL || "";
  if (!targetUrl) {
    return res.status(200).json({
      bridge: "skipped",
      reason: "AUTO_SYNC_SCHEDULER_TARGET_URL is not configured",
    });
  }

  const runnerToken = process.env.AUTO_SYNC_BACKUP_RUNNER_TOKEN || "";
  const hours = Number(req.query?.hours || req.body?.hours || 1);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 25000);

  try {
    const response = await fetch(targetUrl, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        ...(runnerToken ? { authorization: `Bearer ${runnerToken}` } : {}),
      },
      body: JSON.stringify({
        hours: Number.isFinite(hours) ? Math.max(1, Math.min(24, hours)) : 1,
        source: "vercel_scheduler_bridge",
      }),
      signal: controller.signal,
    });

    const text = await response.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      data = { raw: text };
    }

    return res.status(response.status).json({
      bridge: "ok",
      target_status: response.status,
      target_url: targetUrl,
      data,
    });
  } catch (error) {
    const message =
      error.name === "AbortError"
        ? "Scheduler bridge timeout"
        : error.message || "Scheduler bridge failed";

    return res.status(502).json({
      bridge: "error",
      target_url: targetUrl,
      error: message,
    });
  } finally {
    clearTimeout(timeout);
  }
}

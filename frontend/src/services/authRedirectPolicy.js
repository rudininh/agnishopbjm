export function shouldRedirectOnUnauthorized(config = {}) {
  return config.skipAuthRedirect !== true
}

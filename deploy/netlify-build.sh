#!/usr/bin/env bash
# Netlify build: publish only the front-end (no PHP, schema or config) to dist/,
# and point the app at the separately hosted API (set API_URL in Netlify, e.g. https://api.example.ae/api).
set -euo pipefail
OUT=dist
mkdir -p "$OUT"
cp index.html sw.js manifest.json "$OUT/"
cp -r icons vendor "$OUT/"
if [ -n "${API_URL:-}" ]; then
  API="${API_URL%/}/"
  ORIGIN=$(printf '%s' "$API" | sed -E 's#^(https?://[^/]+).*#\1#')
  sed -i "s#<meta name=\"digitz-api\" content=\"./api/\">#<meta name=\"digitz-api\" content=\"$API\">#" "$OUT/index.html"
  sed -i "s#connect-src 'self'#connect-src 'self' $ORIGIN#" "$OUT/index.html"
  sed -i "s#'./api/push.php?action=pending'#'${API}push.php?action=pending'#" "$OUT/sw.js"
  echo "API -> $API (CSP allows $ORIGIN)"
else
  echo "API_URL not set: the app runs offline-only (sign-up and sync need the API)."
fi
SHORT=$(git rev-parse --short HEAD 2>/dev/null || date +%s)
sed -i "s/const VERSION = 'digitz-v[^']*'/const VERSION = 'digitz-$SHORT'/" "$OUT/sw.js"
cat > "$OUT/_headers" <<'H'
/*
  X-Content-Type-Options: nosniff
  Referrer-Policy: no-referrer
  Permissions-Policy: camera=(self), geolocation=(), microphone=()
/index.html
  Cache-Control: no-cache
/sw.js
  Cache-Control: no-cache
  Service-Worker-Allowed: /
/manifest.json
  Content-Type: application/manifest+json
  Cache-Control: no-cache
/vendor/*
  Cache-Control: public, max-age=31536000, immutable
H
if [ "${NETLIFY_ENV:-test}" != "production" ]; then printf '/*\n  X-Robots-Tag: noindex, nofollow\n' >> "$OUT/_headers"; fi
echo "/*  /index.html  200" > "$OUT/_redirects"
ls -la "$OUT"

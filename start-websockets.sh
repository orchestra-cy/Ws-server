#!/usr/bin/env bash
set -euo pipefail

KEY_PATH="/app/config/jwt/public.pem"

mkdir -p /app/config/jwt

if [ ! -f "$KEY_PATH" ]; then
  if [ -n "${JWT_PUBLIC_KEY_B64:-}" ]; then
    echo "$JWT_PUBLIC_KEY_B64" | base64 -d > "$KEY_PATH"
  elif [ -n "${JWT_PUBLIC_KEY:-}" ]; then
    printf "%s" "$JWT_PUBLIC_KEY" > "$KEY_PATH"
  else
    echo "ERROR: JWT public key not found. Provide JWT_PUBLIC_KEY or JWT_PUBLIC_KEY_B64." >&2
    exit 1
  fi
fi

php /app/bin/channel-server.php start &
CHANNEL_PID=$!

php /app/bin/bridge.php start &
BRIDGE_PID=$!

php /app/bin/websocket-server.php start &
WS_PID=$!

trap "kill -TERM $CHANNEL_PID $BRIDGE_PID $WS_PID 2>/dev/null" INT TERM

if ! wait -n "$CHANNEL_PID" "$BRIDGE_PID" "$WS_PID"; then
  echo "A websocket process exited with error. Shutting down..." >&2
else
  echo "A websocket process exited. Shutting down..." >&2
fi

kill -TERM "$CHANNEL_PID" "$BRIDGE_PID" "$WS_PID" 2>/dev/null
wait

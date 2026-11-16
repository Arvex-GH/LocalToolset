#!/usr/bin/env sh
# Give the bridge a brand-new token. It writes the token into fetch_bridge/.env
# and shows it; the tunnel URL stays the same, but the running bridge has to be
# restarted to use it.
set -eu
cd "$(dirname "$0")"
if command -v python3 >/dev/null 2>&1; then
  python3 launch.py --new-token
elif command -v python >/dev/null 2>&1; then
  python launch.py --new-token
else
  echo "Python 3.9 or newer is required but was not found on PATH." >&2
  exit 1
fi
printf '\nPress Enter to close this window. '
read -r _ || true

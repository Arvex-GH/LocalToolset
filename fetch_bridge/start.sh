#!/usr/bin/env sh
# Start the fetch bridge and its Cloudflare tunnel. Ctrl+C stops both.
# Extra arguments are passed to server.py, for example:  ./start.sh --auto
set -eu
cd "$(dirname "$0")"
if command -v python3 >/dev/null 2>&1; then
  exec python3 launch.py "$@"
fi
if command -v python >/dev/null 2>&1; then
  exec python launch.py "$@"
fi
echo "Python 3.9 or newer is required but was not found on PATH." >&2
exit 1

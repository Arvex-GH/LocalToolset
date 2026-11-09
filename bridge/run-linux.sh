#!/usr/bin/env bash
# Launch from any directory without changing the worker's default working directory.
set -euo pipefail
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
if ! command -v python3 >/dev/null 2>&1; then
    echo 'Python 3 is required; install it with your distribution package manager.' >&2
    exit 1
fi
exec python3 "$script_dir/worker.py" "$@"

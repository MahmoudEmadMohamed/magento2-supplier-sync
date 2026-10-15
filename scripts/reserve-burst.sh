#!/usr/bin/env bash
# Concurrency proof: 20 processes each reserve 1 unit against a snapshot of 10, then assert on persisted rows.
# Usage: scripts/reserve-burst.sh [magento_root] [processes] [stock]
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
root="${1:-$here/../../../..}"
php "$here/Test/Concurrency/reserve-burst.php" "$root" "${2:-20}" "${3:-10}"

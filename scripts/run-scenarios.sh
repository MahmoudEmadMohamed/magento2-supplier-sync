#!/usr/bin/env bash
# Runs every scenario harness and the concurrency burst, even if one fails, then reports.
# Needs a Magento install in developer mode with runtime DI (no generated/metadata); the harnesses refuse otherwise.
# Usage: scripts/run-scenarios.sh [magento_root]
set -uo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
root="${1:-$here/../../../..}"
failed=()
for suite in queue placement rest; do
    echo "== $suite scenarios"
    php "$here/Test/Scenario/$suite-scenarios.php" "$root" || failed+=("$suite")
done
echo "== reservation burst"
php "$here/Test/Concurrency/reserve-burst.php" "$root" 20 10 || failed+=("burst")
if ((${#failed[@]})); then
    echo "FAILED: ${failed[*]}"
    exit 1
fi
echo "All scenario suites passed."

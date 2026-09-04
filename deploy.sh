#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "${PHP_BIN:-php}" "$root/deploy/preflight.php" "$@"

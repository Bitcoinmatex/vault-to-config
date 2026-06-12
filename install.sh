#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")"

echo "==> VaultToConfig: installation"

# 1) Check prerequisites
command -v php >/dev/null 2>&1 || { echo "ERROR: PHP is not installed (>= 8.1 required)."; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "ERROR: Composer is not installed."; exit 1; }

PHP_VER="$(php -r 'echo PHP_VERSION;')"
echo "    PHP: ${PHP_VER}"
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo "ERROR: PHP >= 8.1 is required."; exit 1; }

# 2) Dependencies
echo "==> composer install"
composer install --no-interaction --prefer-dist --no-progress

# 3) Writable directories for Nette (temp, log)
echo "==> preparing temp/ and log/ directories"
mkdir -p temp log
chmod -R 0770 temp log 2>/dev/null || true

# 4) Executable console
chmod +x bin/console

echo ""
echo "==> Done"
echo "    # Run:"
echo "    export VAULT_ADDR=\"https://vault.internal:8200\""
echo "    export VAULT_TOKEN=\"hvs.****\""
echo "    php bin/console compile:latte prod examples/config.latte config/local.neon --dry-run"
echo ""

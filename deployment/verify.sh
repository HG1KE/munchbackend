#!/usr/bin/env bash
# Read-only health checks. Exit 1 if any required check fails.
# PHASE=A (default): Passport keys warn only.
# PHASE=B: Passport keys are required.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

PHASE="${PHASE:-A}"
FAIL=0

ok() { echo "OK  $*"; }
bad() { echo "FAIL  $*" >&2; FAIL=1; }
warn() { echo "WARN  $*"; }

cd "${APP_ROOT}" 2>/dev/null || { echo "FAIL  APP_ROOT missing: ${APP_ROOT}" >&2; exit 1; }

artisan_cmd=(php artisan --version)
if id -u "${DEPLOY_USER}" >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
  artisan_cmd=(sudo -n -u "${DEPLOY_USER}" -H php artisan --version)
fi
if [[ -f artisan ]]; then
  if "${artisan_cmd[@]}" >/dev/null 2>&1; then
    ok "artisan boots: $("${artisan_cmd[@]}" 2>/dev/null | tail -1)"
  else
    bad "php artisan --version (missing vendor/, APP_KEY, or .env?)"
  fi
else
  bad "artisan missing"
fi

if [[ -f .env ]]; then
  if grep -qE '^APP_KEY=base64:.+' .env; then
    ok "APP_KEY is set"
  else
    bad "APP_KEY missing or empty in .env"
  fi
  if grep -qE '^APP_DEBUG=false' .env; then
    ok "APP_DEBUG=false"
  else
    warn "APP_DEBUG is not false"
  fi
else
  bad ".env missing"
fi

for d in storage storage/logs storage/framework/cache bootstrap/cache; do
  if [[ -d "${d}" && -w "${d}" ]]; then
    ok "writable ${d} (current user)"
  else
    bad "not writable ${d} (current user)"
  fi
  if id -u "${WEB_USER}" >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
    if sudo -n -u "${WEB_USER}" test -w "${d}" 2>/dev/null; then
      ok "writable ${d} (${WEB_USER})"
    else
      bad "not writable ${d} (${WEB_USER}) — PHP-FPM cannot write caches/logs"
    fi
  fi
done

# extension_loaded() — do not pipe php -m to grep -q under pipefail (SIGPIPE false negatives).
for ext in curl openssl mbstring xml gd zip bcmath intl redis pdo_mysql exif tokenizer fileinfo; do
  if php -r "exit(extension_loaded('${ext}') ? 0 : 1);" 2>/dev/null; then
    ok "php ext ${ext}"
  else
    bad "php ext ${ext}"
  fi
done

if command -v composer >/dev/null 2>&1 && [[ -f composer.lock ]]; then
  if composer check-platform-reqs --no-dev >/dev/null 2>&1; then
    ok "composer platform requirements (no-dev)"
  else
    bad "composer check-platform-reqs --no-dev failed"
    composer check-platform-reqs --no-dev || true
  fi
fi

if command -v redis-cli >/dev/null 2>&1 && redis-cli -h 127.0.0.1 ping 2>/dev/null | grep -q PONG; then
  ok "Redis ping 127.0.0.1"
else
  bad "Redis not responding on 127.0.0.1"
fi

if command -v mysqladmin >/dev/null 2>&1 && mysqladmin ping --silent 2>/dev/null; then
  ok "MySQL ping"
else
  bad "MySQL not responding"
fi

if command -v nginx >/dev/null 2>&1; then
  if nginx -t; then
    ok "nginx -t"
  else
    bad "nginx -t"
  fi
else
  bad "nginx not installed"
fi

if systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
  ok "php8.3-fpm active"
else
  bad "php8.3-fpm not active"
fi

if [[ -L public/storage ]]; then
  ok "public/storage symlink"
else
  bad "public/storage symlink missing (php artisan storage:link)"
fi

if [[ -f public/index.php ]]; then
  if grep -q "../vendor/autoload.php" public/index.php && grep -q "../bootstrap/app.php" public/index.php; then
    ok "public/index.php uses ../ vendor and bootstrap paths"
  else
    bad "public/index.php is not the standard Laravel front controller"
  fi
else
  bad "public/index.php missing"
fi

if [[ -d storage/app/public ]]; then
  ok "storage/app/public exists (upload tree)"
else
  bad "storage/app/public missing"
fi

if [[ -f storage/oauth-private.key && -f storage/oauth-public.key ]]; then
  ok "Passport oauth keys"
else
  warn "Passport keys missing — Phase A empty DB: php artisan passport:install"
  warn "Phase B: copy live oauth-*.key only. Never passport:install after importing production."
  if [[ "${PHASE}" == "B" ]]; then
    bad "Passport keys missing (required for PHASE=B)"
  fi
fi

if [[ -d Modules/Gateways ]]; then
  ok "Modules/Gateways present"
else
  warn "Modules/Gateways absent (gitignored; copy from live in Phase B if needed)"
fi

if [[ -f bootstrap/cache/config.php ]]; then
  ok "config cached"
else
  warn "config not cached — deploy.sh runs artisan optimize"
fi

if [[ -f bootstrap/cache/routes-v7.php || -f bootstrap/cache/routes.php ]]; then
  ok "route cache present"
else
  warn "route cache missing — deploy.sh runs artisan optimize"
fi

exit "${FAIL}"

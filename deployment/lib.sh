#!/usr/bin/env bash
# Shared helpers. Sourced by the other scripts — not executed on its own.

APP_ROOT="${APP_ROOT:-/var/www/portal.munch.co.ke}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
WEB_USER="${WEB_USER:-www-data}"
REPO_URL="${REPO_URL:-https://github.com/HG1KE/munchbackend.git}"
REPO_BRANCH="${REPO_BRANCH:-sync/hostafrica-production}"
RELEASE_LOG="${APP_ROOT}/storage/app/releases.log"
ROLLBACK_PIN="${APP_ROOT}/storage/app/ROLLBACK_PIN"

require_root() {
  if [[ "$(id -u)" -ne 0 ]]; then
    echo "Run as root" >&2
    exit 1
  fi
}

# Git / Composer / Artisan as deploy:www-data so PHP-FPM can read the result.
run_as_app() {
  local cmd
  cmd=$(printf '%q ' "$@")
  sudo -u "${DEPLOY_USER}" -g "${WEB_USER}" -H \
    bash -lc "umask 0002; cd $(printf '%q' "${APP_ROOT}") && ${cmd}"
}

install_public_front_controller() {
  local script_dir="$1"
  install -d -o "${DEPLOY_USER}" -g "${WEB_USER}" -m 0775 "${APP_ROOT}/public"
  install -m 0644 -o "${DEPLOY_USER}" -g "${WEB_USER}" \
    "${script_dir}/templates/public-index.php" "${APP_ROOT}/public/index.php"
  install -m 0644 -o "${DEPLOY_USER}" -g "${WEB_USER}" \
    "${script_dir}/templates/public.htaccess" "${APP_ROOT}/public/.htaccess"
  if [[ -f "${APP_ROOT}/firebase-messaging-sw.js" && ! -f "${APP_ROOT}/public/firebase-messaging-sw.js" ]]; then
    install -m 0644 -o "${DEPLOY_USER}" -g "${WEB_USER}" \
      "${APP_ROOT}/firebase-messaging-sw.js" "${APP_ROOT}/public/firebase-messaging-sw.js"
  fi
}

# routes/web.php closures were extracted so config/route/view cache is safe.
laravel_build() {
  run_as_app composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
  run_as_app php artisan optimize:clear
  if [[ ! -L "${APP_ROOT}/public/storage" ]] || [[ ! -e "${APP_ROOT}/public/storage" ]]; then
    rm -f "${APP_ROOT}/public/storage"
    run_as_app php artisan storage:link
  fi
  run_as_app php artisan optimize
  run_as_app php artisan view:cache
}

reload_web() {
  systemctl reload php8.3-fpm
  nginx -t
  systemctl reload nginx
}

install_nginx_site() {
  local script_dir="$1"
  install -m 0644 "${script_dir}/nginx/portal.munch.co.ke.conf" \
    /etc/nginx/sites-available/portal.munch.co.ke.conf
  ln -sfn /etc/nginx/sites-available/portal.munch.co.ke.conf \
    /etc/nginx/sites-enabled/portal.munch.co.ke.conf
  rm -f /etc/nginx/sites-enabled/portal.munch.co.ke
}

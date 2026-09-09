#!/usr/bin/env bash
# Canonical production deploy for the Munch backend.
#
# Run ON the VPS as root (not from a developer laptop):
#   /var/www/portal.munch.co.ke/deployment/deploy-production.sh
# or:
#   ssh root@164.90.229.92 '/var/www/portal.munch.co.ke/deployment/deploy-production.sh'
#
# Pins: branch main, URL https://portal.munch.co.ke, app root below.
# Idempotent: a second run with no new origin/main commits is a no-op pull,
# composer install, migrate (nothing pending), recache, fpm reload, and health checks.
#
# Never: git reset --hard, git clean -fd, composer update, npm, passport:install,
# key:generate, nginx edits, DNS, .env writes, pushes, or queue/cron enablement.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="${SCRIPT_DIR}/$(basename "${BASH_SOURCE[0]}")"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

# Canonical production pins (do not take REPO_BRANCH from the environment).
APP_ROOT="/var/www/portal.munch.co.ke"
PRODUCTION_BRANCH="main"
PRODUCTION_URL="https://portal.munch.co.ke"
EXPECTED_APP_URL="https://portal.munch.co.ke"
PUBLIC_ASSET_PATH="/assets/admin/css/style.css"
CRON_ENABLED="/etc/cron.d/portal-munch-laravel"
CRON_DISABLED="/etc/cron.d/portal-munch-laravel.disabled"
SUPERVISOR_CONF_DIR="/etc/supervisor/conf.d"

START_EPOCH="$(date +%s)"
HEALTH_FAIL=0

# ---------------------------------------------------------------------------
# Logging. Verbose on purpose so an SSH session is a complete audit trail.
# ---------------------------------------------------------------------------
stage() { printf '\n======== %s ========\n' "$*"; }
info()  { printf -- '--> %s\n' "$*"; }
ok()    { printf 'OK  %s\n' "$*"; }
warn()  { printf 'WARN  %s\n' "$*"; }
fail()  { printf 'ERROR  %s\n' "$*" >&2; exit 1; }

health_ok()   { printf 'OK  %s\n' "$*"; }
health_fail() { printf 'FAIL  %s\n' "$*" >&2; HEALTH_FAIL=1; }

on_err() {
  local line="$1"
  local elapsed=$(( $(date +%s) - START_EPOCH ))
  printf '\nDEPLOY FAILED at line %s after %ss\n' "${line}" "${elapsed}" >&2
  if [[ -d "${APP_ROOT}/.git" ]]; then
    printf 'branch=%s sha=%s\n' \
      "$(git -C "${APP_ROOT}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)" \
      "$(git -C "${APP_ROOT}" rev-parse HEAD 2>/dev/null || echo unknown)" >&2
  fi
}
trap 'on_err $LINENO' ERR

require_root

if [[ "${SCRIPT_DIR}" != "${APP_ROOT}/deployment" ]]; then
  fail "This script must live at ${APP_ROOT}/deployment (found ${SCRIPT_DIR})"
fi

cd "${APP_ROOT}"

env_value() {
  # Read a single KEY=value from .env. Do not source .env (it can contain secrets).
  local key="$1"
  local line
  line="$(grep -E "^${key}=" "${APP_ROOT}/.env" | tail -n 1 || true)"
  line="${line#*=}"
  line="${line%\"}"
  line="${line#\"}"
  line="${line%\'}"
  line="${line#\'}"
  printf '%s' "${line}"
}

http_code() {
  local url="$1"
  shift
  curl -sS -o /dev/null -w '%{http_code}' --max-time 30 "$@" "${url}"
}

# ===========================================================================
# Stage 0 — preflight (same safety gates as deploy.sh, plus production pins)
# ===========================================================================
stage "0. Preflight"

[[ -f "${APP_ROOT}/.env" ]] || fail "Missing ${APP_ROOT}/.env"
[[ -d "${APP_ROOT}/.git" ]] || fail "Missing ${APP_ROOT}/.git"
if ! grep -qE '^APP_KEY=base64:.+' "${APP_ROOT}/.env"; then
  fail "APP_KEY is empty. Do not run key:generate on production — restore the live key."
fi
if [[ -f "${ROLLBACK_PIN}" ]]; then
  fail "ROLLBACK_PIN exists at ${ROLLBACK_PIN}. Remove it only when you intend to deploy origin again."
fi
ok "APP_ROOT=${APP_ROOT}"
ok ".env and APP_KEY present (value not printed)"

# ===========================================================================
# Stage 1 — git: must already be on main, clean, and not ahead of origin
# ===========================================================================
stage "1. Verify git"

info "Current branch must be ${PRODUCTION_BRANCH} (this script will not checkout or merge)."
CURRENT_BRANCH="$(run_as_app git rev-parse --abbrev-ref HEAD)"
[[ "${CURRENT_BRANCH}" == "${PRODUCTION_BRANCH}" ]] || \
  fail "Current branch is ${CURRENT_BRANCH}, expected ${PRODUCTION_BRANCH}. Checkout ${PRODUCTION_BRANCH} first."

info "Working tree must be clean (no stash/reset/clean from this script)."
if [[ -n "$(run_as_app git status --porcelain)" ]]; then
  run_as_app git status --short
  fail "Working tree is dirty. Commit, stash, or restore local edits before deploying."
fi
ok "Working tree clean"

info "Fetching origin (includes origin/${PRODUCTION_BRANCH} on single-branch clones)."
run_as_app git fetch origin
run_as_app git fetch origin "${PRODUCTION_BRANCH}"

LOCAL_SHA="$(run_as_app git rev-parse HEAD)"
REMOTE_SHA="$(run_as_app git rev-parse FETCH_HEAD)"
AHEAD="$(run_as_app git rev-list --count "${REMOTE_SHA}..${LOCAL_SHA}")"
BEHIND="$(run_as_app git rev-list --count "${LOCAL_SHA}..${REMOTE_SHA}")"
info "local=${LOCAL_SHA}"
info "origin/${PRODUCTION_BRANCH}=${REMOTE_SHA}"
info "ahead=${AHEAD} behind=${BEHIND}"

if [[ "${AHEAD}" -gt 0 ]]; then
  fail "Local production is ahead of origin/${PRODUCTION_BRANCH} by ${AHEAD} commit(s). Refusing to deploy. Push those commits first or move origin forward."
fi

SCRIPT_HASH_BEFORE="$(sha256sum "${SCRIPT_PATH}" | awk '{print $1}')"
info "Fast-forward pull origin/${PRODUCTION_BRANCH}"
run_as_app git pull --ff-only origin "${PRODUCTION_BRANCH}"
DEPLOYED_SHA="$(run_as_app git rev-parse HEAD)"
ok "HEAD=${DEPLOYED_SHA}"

mkdir -p "$(dirname "${RELEASE_LOG}")"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) production before ${LOCAL_SHA} after ${DEPLOYED_SHA}" >>"${RELEASE_LOG}"

# ===========================================================================
# Stage 2 — self-update: if this file changed in the pull, re-exec once
# ===========================================================================
stage "2. Self-update"

SCRIPT_HASH_AFTER="$(sha256sum "${SCRIPT_PATH}" | awk '{print $1}')"
if [[ "${SCRIPT_HASH_BEFORE}" != "${SCRIPT_HASH_AFTER}" ]]; then
  if [[ "${DEPLOY_PRODUCTION_REEXEC:-0}" == "1" ]]; then
    warn "deploy-production.sh changed again after re-exec. Continuing with the current file (no further re-exec)."
  else
    info "deploy-production.sh changed during pull. Re-executing once."
    exec env DEPLOY_PRODUCTION_REEXEC=1 /usr/bin/env bash "${SCRIPT_PATH}"
  fi
else
  ok "Deploy script unchanged (or already re-executed)"
fi

# ===========================================================================
# Stage 3 — Composer (install only; never update)
# ===========================================================================
stage "3. Composer"

run_as_app composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction
ok "composer install --no-dev finished"

# ===========================================================================
# Stage 4 — Permissions (deploy:www-data, setgid dirs; do not touch .env/keys)
# ===========================================================================
stage "4. Permissions"

info "Applying existing VPS convention: ${DEPLOY_USER}:${WEB_USER}, dirs 2775, files 0664, oauth *.key 0640."
chown -R "${DEPLOY_USER}:${WEB_USER}" "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache"
find "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" -type d -exec chmod 2775 {} \;
find "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" -type f ! -name '*.key' -exec chmod 0664 {} \;
if compgen -G "${APP_ROOT}/storage/"'*.key' >/dev/null; then
  chmod 0640 "${APP_ROOT}/storage/"*.key
  chown "${DEPLOY_USER}:${WEB_USER}" "${APP_ROOT}/storage/"*.key
fi
if command -v setfacl >/dev/null 2>&1; then
  setfacl -R -m "u:${WEB_USER}:rwX" -m "u:${DEPLOY_USER}:rwX" \
    "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache"
  setfacl -dR -m "u:${WEB_USER}:rwX" -m "u:${DEPLOY_USER}:rwX" \
    "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache"
fi

sudo -u "${DEPLOY_USER}" test -w "${APP_ROOT}/storage" \
  || fail "storage/ not writable by ${DEPLOY_USER}"
sudo -u "${WEB_USER}" test -w "${APP_ROOT}/storage" \
  || fail "storage/ not writable by ${WEB_USER}"
sudo -u "${DEPLOY_USER}" test -w "${APP_ROOT}/bootstrap/cache" \
  || fail "bootstrap/cache/ not writable by ${DEPLOY_USER}"
sudo -u "${WEB_USER}" test -w "${APP_ROOT}/bootstrap/cache" \
  || fail "bootstrap/cache/ not writable by ${WEB_USER}"
ok "storage/ and bootstrap/cache/ writable by ${DEPLOY_USER}:${WEB_USER}"

info "Confirming permission fix did not dirty the git tree."
if [[ -n "$(run_as_app git status --porcelain)" ]]; then
  run_as_app git status --short
  fail "Working tree became dirty after permission fix. Refusing to continue."
fi
ok "Working tree still clean"

# ===========================================================================
# Stage 5 — Laravel (clear, migrate, recache). No key/passport generation.
# ===========================================================================
stage "5. Laravel"

run_as_app php artisan optimize:clear

info "Applying pending migrations (--force). Additive only; this script does not roll back."
run_as_app php artisan migrate --force

if [[ ! -L "${APP_ROOT}/public/storage" ]] || [[ ! -e "${APP_ROOT}/public/storage" ]]; then
  if [[ -e "${APP_ROOT}/public/storage" && ! -L "${APP_ROOT}/public/storage" ]]; then
    fail "public/storage exists and is not a symlink. Will not delete it. Fix by hand."
  fi
  info "storage symlink missing or broken — creating with artisan storage:link"
  run_as_app php artisan storage:link
else
  ok "public/storage symlink already present"
fi

run_as_app php artisan optimize
run_as_app php artisan view:cache
ok "Laravel caches rebuilt"

# ===========================================================================
# Stage 6 — Reload php-fpm so opcache picks up new code. Do not touch nginx.
# ===========================================================================
stage "6. Reload php8.3-fpm"

systemctl reload php8.3-fpm
ok "php8.3-fpm reloaded (nginx not reloaded; this script never edits nginx)"

# ===========================================================================
# Stage 7 — Warm application URLs (final HTTP 200)
# ===========================================================================
stage "7. Warm application"

# GET / redirects to admin.dashboard (then login). Follow redirects so the
# warmed response is the login HTML (200), not the 302 hop.
info "GET / (follow redirects — app issues 302 to admin login)"
ROOT_CODE="$(http_code "${PRODUCTION_URL}/" -L --max-redirs 5)"
info "GET / final status=${ROOT_CODE}"
[[ "${ROOT_CODE}" == "200" ]] || fail "GET / expected HTTP 200 after redirects, got ${ROOT_CODE}"

info "GET /api/v1/config"
CONFIG_CODE="$(http_code "${PRODUCTION_URL}/api/v1/config")"
[[ "${CONFIG_CODE}" == "200" ]] || fail "GET /api/v1/config expected HTTP 200, got ${CONFIG_CODE}"

info "GET /api/v1/categories"
CAT_CODE="$(http_code "${PRODUCTION_URL}/api/v1/categories")"
[[ "${CAT_CODE}" == "200" ]] || fail "GET /api/v1/categories expected HTTP 200, got ${CAT_CODE}"

info "GET /admin/auth/login"
LOGIN_CODE="$(http_code "${PRODUCTION_URL}/admin/auth/login")"
[[ "${LOGIN_CODE}" == "200" ]] || fail "GET /admin/auth/login expected HTTP 200, got ${LOGIN_CODE}"
ok "Warm requests returned HTTP 200"

# ===========================================================================
# Stage 8 — Health checks (report every failure, then exit if any failed)
# ===========================================================================
stage "8. Health checks"

APP_DEBUG_VAL="$(env_value APP_DEBUG)"
APP_URL_VAL="$(env_value APP_URL)"
APP_URL_VAL="${APP_URL_VAL%/}"

if [[ "${APP_DEBUG_VAL}" == "false" ]]; then
  health_ok "APP_DEBUG=false"
else
  health_fail "APP_DEBUG is '${APP_DEBUG_VAL}', expected false"
fi

if [[ "${APP_URL_VAL}" == "${EXPECTED_APP_URL}" ]]; then
  health_ok "APP_URL=${APP_URL_VAL}"
else
  health_fail "APP_URL is '${APP_URL_VAL}', expected ${EXPECTED_APP_URL}"
fi

if command -v redis-cli >/dev/null 2>&1 && redis-cli -h 127.0.0.1 ping 2>/dev/null | grep -q PONG; then
  health_ok "Redis reachable on 127.0.0.1"
else
  health_fail "Redis not reachable on 127.0.0.1"
fi

if command -v mysqladmin >/dev/null 2>&1 && mysqladmin ping --silent 2>/dev/null; then
  health_ok "MySQL reachable"
else
  health_fail "MySQL not reachable"
fi

if [[ -f "${APP_ROOT}/storage/oauth-private.key" && -f "${APP_ROOT}/storage/oauth-public.key" ]] \
  && sudo -u "${WEB_USER}" test -r "${APP_ROOT}/storage/oauth-private.key" \
  && sudo -u "${WEB_USER}" test -r "${APP_ROOT}/storage/oauth-public.key"; then
  health_ok "Passport keys readable by ${WEB_USER}"
else
  health_fail "Passport keys missing or not readable by ${WEB_USER}"
fi

if [[ -L "${APP_ROOT}/public/storage" && -e "${APP_ROOT}/public/storage" ]]; then
  health_ok "public/storage symlink exists"
else
  health_fail "public/storage symlink missing"
fi

ASSET_CODE="$(http_code "${PRODUCTION_URL}${PUBLIC_ASSET_PATH}")"
if [[ "${ASSET_CODE}" == "200" ]]; then
  health_ok "public asset ${PUBLIC_ASSET_PATH} HTTP 200"
else
  health_fail "public asset ${PUBLIC_ASSET_PATH} HTTP ${ASSET_CODE}"
fi

if [[ "${LOGIN_CODE}" == "200" ]]; then
  health_ok "login page HTTP 200"
else
  health_fail "login page HTTP ${LOGIN_CODE}"
fi

CONFIG_TMP="$(mktemp)"
CONFIG_HDR="$(mktemp)"
if curl -sS -D "${CONFIG_HDR}" -o "${CONFIG_TMP}" --max-time 30 \
  -H 'Accept: application/json' "${PRODUCTION_URL}/api/v1/config"; then
  CFG_HTTP="$(awk 'BEGIN{c=0} /^HTTP\//{c=$2} END{print c}' "${CONFIG_HDR}")"
  if [[ "${CFG_HTTP}" == "200" ]] && php -r 'json_decode(stream_get_contents(STDIN)); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);' <"${CONFIG_TMP}"; then
    health_ok "/api/v1/config HTTP 200 JSON (body not printed)"
  else
    health_fail "/api/v1/config not HTTP 200 JSON (status=${CFG_HTTP})"
  fi
else
  health_fail "/api/v1/config request failed"
fi
rm -f "${CONFIG_TMP}" "${CONFIG_HDR}"

if [[ "${HEALTH_FAIL}" -ne 0 ]]; then
  fail "One or more health checks failed"
fi
ok "All health checks passed"

# ===========================================================================
# Stage 9 — Supervisor: only if an enabled *.conf exists (not *.disabled)
# ===========================================================================
stage "9. Supervisor"

shopt -s nullglob
SUPERVISOR_ENABLED=("${SUPERVISOR_CONF_DIR}"/*.conf)
shopt -u nullglob

if [[ "${#SUPERVISOR_ENABLED[@]}" -eq 0 ]] || ! command -v supervisorctl >/dev/null 2>&1; then
  echo "Supervisor disabled — skipping worker restart."
else
  info "Enabled supervisor configs: ${SUPERVISOR_ENABLED[*]}"
  supervisorctl reread
  supervisorctl update
  supervisorctl restart all
  info "Verifying every configured worker is RUNNING"
  if supervisorctl status | grep -v RUNNING | grep -q .; then
    supervisorctl status
    fail "One or more Supervisor workers are not RUNNING"
  fi
  supervisorctl status
  ok "All Supervisor workers RUNNING"
fi

# ===========================================================================
# Stage 10 — Cron: report only. Never enable a .disabled scheduler file.
# ===========================================================================
stage "10. Cron"

if [[ -f "${CRON_DISABLED}" ]]; then
  echo "Laravel scheduler disabled."
elif [[ -f "${CRON_ENABLED}" ]]; then
  info "Laravel scheduler file present at ${CRON_ENABLED} (not modified)"
else
  warn "No Laravel cron file found at ${CRON_ENABLED} or ${CRON_DISABLED}"
fi

# ===========================================================================
# Stage 11 — Completion
# ===========================================================================
stage "11. Completion"

END_EPOCH="$(date +%s)"
DURATION="$(( END_EPOCH - START_EPOCH ))"
FINAL_SHA="$(run_as_app git rev-parse HEAD)"
FINAL_BRANCH="$(run_as_app git rev-parse --abbrev-ref HEAD)"
FINAL_APP_URL="$(env_value APP_URL)"

cat <<EOF

-------- production deploy summary --------
status:          SUCCESS
branch:          ${FINAL_BRANCH}
deployed_sha:    ${FINAL_SHA}
APP_URL:         ${FINAL_APP_URL}
duration_sec:    ${DURATION}
environment:     ${PRODUCTION_URL}
-------------------------------------------
EOF

#!/usr/bin/env bash
# Phase A — filesystem, firewall, PHP-FPM, Nginx, cron, logrotate, Redis bind.
# Run as root after bootstrap.sh. Does not import production data.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
require_root

if ! id -u "${DEPLOY_USER}" >/dev/null 2>&1; then
  # Regular login user (not --system): git pull, SSH deploy keys, UID >= 1000.
  useradd --create-home --shell /bin/bash --comment "Munch deploy" "${DEPLOY_USER}"
fi
usermod -aG "${WEB_USER}" "${DEPLOY_USER}"

install -d -m 0700 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "/home/${DEPLOY_USER}/.ssh"

mkdir -p "${APP_ROOT}"
chown "${DEPLOY_USER}:${WEB_USER}" "${APP_ROOT}"

if [[ ! -d "${APP_ROOT}/.git" ]]; then
  if [[ -n "$(ls -A "${APP_ROOT}" 2>/dev/null)" ]]; then
    echo "APP_ROOT ${APP_ROOT} is not empty and is not a git clone. Refusing to clone." >&2
    exit 1
  fi
  echo "Cloning ${REPO_URL} (${REPO_BRANCH}) into ${APP_ROOT}"
  if ! sudo -u "${DEPLOY_USER}" -H env GIT_TERMINAL_PROMPT=0 \
    git clone --branch "${REPO_BRANCH}" --single-branch "${REPO_URL}" "${APP_ROOT}"; then
    echo "git clone failed. If the GitHub repo is private, install a read-only deploy key at /home/${DEPLOY_USER}/.ssh/ and retry." >&2
    exit 1
  fi
fi

install -d -o "${DEPLOY_USER}" -g "${WEB_USER}" -m 2775 \
  "${APP_ROOT}/storage" \
  "${APP_ROOT}/storage/app" \
  "${APP_ROOT}/storage/app/public" \
  "${APP_ROOT}/storage/framework" \
  "${APP_ROOT}/storage/framework/cache" \
  "${APP_ROOT}/storage/framework/cache/data" \
  "${APP_ROOT}/storage/framework/sessions" \
  "${APP_ROOT}/storage/framework/views" \
  "${APP_ROOT}/storage/logs" \
  "${APP_ROOT}/bootstrap/cache"

chown -R "${DEPLOY_USER}:${WEB_USER}" "${APP_ROOT}"

# Writable app dirs: setgid + group write. Do not 0664 Passport keys.
find "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" -type d -exec chmod 2775 {} \;
find "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" -type f ! -name '*.key' -exec chmod 0664 {} \;
if compgen -G "${APP_ROOT}/storage/*.key" >/dev/null; then
  chmod 0640 "${APP_ROOT}/storage/"*.key
  chown "${DEPLOY_USER}:${WEB_USER}" "${APP_ROOT}/storage/"*.key
fi

if command -v setfacl >/dev/null 2>&1; then
  setfacl -R -m "u:${WEB_USER}:rwX" -m "u:${DEPLOY_USER}:rwX" \
    "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache"
  setfacl -dR -m "u:${WEB_USER}:rwX" -m "u:${DEPLOY_USER}:rwX" \
    "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache"
fi

# PHP-FPM and CLI (artisan uses CLI php.ini). Do not use the cPanel php.ini from the repo.
php_ini_blob='memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 120
expose_php = Off
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
'
printf '%s' "${php_ini_blob}" >/etc/php/8.3/fpm/conf.d/99-portal-munch.ini
printf '%s' "${php_ini_blob}" >/etc/php/8.3/cli/conf.d/99-portal-munch.ini

# Nginx — test before removing the default site
install -d /etc/nginx/sites-available /etc/nginx/sites-enabled
cp "${SCRIPT_DIR}/nginx/portal.munch.co.ke.conf" /etc/nginx/sites-available/portal.munch.co.ke.conf
ln -sfn /etc/nginx/sites-available/portal.munch.co.ke.conf /etc/nginx/sites-enabled/portal.munch.co.ke.conf
nginx -t
rm -f /etc/nginx/sites-enabled/default

# Redis 7 on Ubuntu 24.04: keep it loopback-only. Do not open 6379 on the public interface.
redis_conf=/etc/redis/redis.conf
if [[ -f "${redis_conf}" ]]; then
  if grep -qE '^[[:space:]]*#?[[:space:]]*bind ' "${redis_conf}"; then
    sed -i -E 's/^[[:space:]]*#?[[:space:]]*bind .*/bind 127.0.0.1 -::1/' "${redis_conf}"
  else
    printf '\nbind 127.0.0.1 -::1\n' >>"${redis_conf}"
  fi
  if grep -qE '^[[:space:]]*#?[[:space:]]*protected-mode ' "${redis_conf}"; then
    sed -i -E 's/^[[:space:]]*#?[[:space:]]*protected-mode .*/protected-mode yes/' "${redis_conf}"
  else
    printf '\nprotected-mode yes\n' >>"${redis_conf}"
  fi
fi

# MySQL listen locally only (no HostAfrica bind, no public 3306)
cat >/etc/mysql/mysql.conf.d/99-portal-bind.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
mysqlx-bind-address = 127.0.0.1
EOF

# UFW — allow SSH before enabling so a re-run cannot lock you out
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 22/tcp
ufw allow 'Nginx Full'
ufw --force enable

systemctl enable --now fail2ban

install -m 0644 -o root -g root "${SCRIPT_DIR}/cron/laravel" /etc/cron.d/portal-munch-laravel
install -m 0644 -o root -g root "${SCRIPT_DIR}/logrotate/laravel" /etc/logrotate.d/portal-munch-laravel

# Supervisor include is conf.d/*.conf — a .disabled suffix is not loaded.
install -m 0644 -o root -g root "${SCRIPT_DIR}/supervisor/laravel-worker.conf.disabled" \
  /etc/supervisor/conf.d/laravel-worker.conf.disabled

# Ubuntu 24.04 / cloud-init: sshd_config.d drop-ins override sshd_config.
# Only disable passwords when a key is already present.
if [[ -s /root/.ssh/authorized_keys ]] || [[ -s "/home/${DEPLOY_USER}/.ssh/authorized_keys" ]]; then
  cat >/etc/ssh/sshd_config.d/60-portal-munch.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
  sshd -t
  systemctl reload ssh
fi

systemctl restart mysql
systemctl restart redis-server
systemctl reload php8.3-fpm
systemctl reload nginx

echo "server-setup.sh complete."
echo "Next:"
echo "  1. Create empty MySQL database and user (see deployment/README.md)"
echo "  2. Copy ${SCRIPT_DIR}/env/.env.production.template to ${APP_ROOT}/.env and fill values"
echo "  3. sudo -u ${DEPLOY_USER} -H php ${APP_ROOT}/artisan key:generate   # Phase A only"
echo "  4. ${SCRIPT_DIR}/deploy.sh"

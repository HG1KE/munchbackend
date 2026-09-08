#!/usr/bin/env bash
# Phase A — package install only. Run on the Ubuntu 24.04 droplet as root.
# Does not clone the app, write .env, or import any database.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
require_root

apt-get update

# Install only. A full `apt-get upgrade` can restart MySQL/kernel packages mid-bootstrap.
apt-get -y install \
  ca-certificates \
  curl \
  gnupg \
  lsb-release \
  unzip \
  git \
  sudo \
  acl \
  ufw \
  fail2ban \
  logrotate \
  supervisor \
  nginx \
  redis-server \
  mysql-server \
  certbot \
  python3-certbot-nginx \
  composer

# PHP 8.3 is the Ubuntu 24.04 default — no ondrej PPA required.
apt-get -y install \
  php8.3-fpm \
  php8.3-cli \
  php8.3-common \
  php8.3-mysql \
  php8.3-mbstring \
  php8.3-xml \
  php8.3-curl \
  php8.3-gd \
  php8.3-zip \
  php8.3-bcmath \
  php8.3-intl \
  php8.3-redis \
  php8.3-opcache \
  php8.3-readline \
  php8.3-exif \
  php8.3-soap \
  php8.3-gmp

systemctl enable --now php8.3-fpm nginx redis-server mysql fail2ban
systemctl enable supervisor
systemctl start supervisor
# Laravel worker unit is not installed (supervisor/*.conf.disabled).

echo "bootstrap.sh complete. Next: server-setup.sh"

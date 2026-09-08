# portal.munch.co.ke — deployment framework

These files prepare a **standard Laravel** deploy on Ubuntu 24.04 (DigitalOcean, Frankfurt).

They are **not** executed from this phase. Do not run them against HostAfrica or `app.munch.co.ke`.

| Item | Value |
|---|---|
| Application root | `/var/www/portal.munch.co.ke` |
| Nginx document root | `/var/www/portal.munch.co.ke/public` |
| Git remote | `https://github.com/HG1KE/munchbackend.git` |
| Deploy branch | `sync/hostafrica-production` |
| PHP | 8.3-FPM |
| Queue (Phase A) | `sync` (Supervisor unit present, not loaded) |

## Scripts

| Script | Role |
|---|---|
| `lib.sh` | Shared helpers (run as `deploy:www-data`, front controller, build, reload) |
| `bootstrap.sh` | apt packages only (PHP 8.3, Nginx, MySQL, Redis, Composer, Certbot, Fail2ban, Supervisor) |
| `server-setup.sh` | users, permissions, UFW, logrotate, cron, Redis bind, MySQL bind, PHP-FPM, Nginx site |
| `deploy.sh` | `git pull --ff-only` + Composer + config/view cache + reload |
| `rollback.sh` | `git reset --hard` to a previous commit; writes `ROLLBACK_PIN` |
| `verify.sh` | health checks (`PHASE=A` default; `PHASE=B` requires Passport keys) |

Run `bootstrap.sh`, `server-setup.sh`, `deploy.sh`, and `rollback.sh` as **root**. Git, Composer, and Artisan run as `deploy`.

Do **not** run `php artisan route:cache` or `php artisan optimize` on this app — `routes/web.php` uses Closures.

## Phase A vs Phase B

- **Phase A:** empty MySQL, generated `APP_KEY`, prove the app boots. No HostAfrica data.
- **Phase B:** import DB dump, copy `storage/app/public`, copy `oauth-*.key`, reuse live `APP_KEY`. **No DNS cutover.**

## Deploy-time files (not all in Git `public/` today)

`deploy.sh` creates:

- `public/index.php` from `templates/public-index.php` (paths use `../`)
- `public/.htaccess` from `templates/public.htaccess`
- copies `firebase-messaging-sw.js` into `public/` if missing
- `php artisan storage:link` if the symlink is absent

Do **not** copy repository-root `index.php` into `public/` — its require paths assume cPanel document root.

## eFood URL compatibility

This codebase was served from the **project root** on cPanel. Blades and models still emit:

- `asset('public/assets/...')` → `/public/assets/...`
- `asset('storage/app/public/...')` → `/storage/app/public/...`

Nginx keeps `root .../public` and maps those two prefixes. Do not copy repo-root `index.php` into `public/`.

## Empty MySQL (Phase A)

`bootstrap.sh` installs MySQL. It does **not** create the app database or user (no passwords in scripts). Before `deploy.sh`:

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS portal_munch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS 'portal_munch'@'localhost' IDENTIFIED BY 'choose-a-strong-password';"
mysql -e "GRANT ALL ON portal_munch.* TO 'portal_munch'@'localhost'; FLUSH PRIVILEGES;"
```

Put the same password in `.env`. Do not point this droplet at HostAfrica MySQL.

## GitHub access

`server-setup.sh` clones as `deploy` with `GIT_TERMINAL_PROMPT=0`. If the repository is private, install a read-only deploy key at `/home/deploy/.ssh/` **before** `server-setup.sh`, or copy `deployment/` onto the droplet after a manual clone.

`deployment/` is local and uncommitted until you ask to commit. Either push this folder first, or copy it onto the droplet.

## Rollback

`rollback.sh` reverts **code only**. It does not revert MySQL, `.env`, oauth keys, or uploads. It writes `storage/app/ROLLBACK_PIN` so the next `deploy.sh` cannot fast-forward back to origin until you remove the pin.

## Must exist on the VPS only

- `.env` (from `env/.env.production.template`)
- `storage/oauth-private.key` / `storage/oauth-public.key` (generate on Phase A; replace on Phase B)
- `storage/app/public` uploads (Phase B)
- `Modules/Gateways` if present on live (gitignored)
- MySQL credentials
- Let's Encrypt certificates

## Do not commit

`.env`, Passport keys, `vendor/`, `storage/logs`, zip backups, `phpinfo.php`, production dumps.

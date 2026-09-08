# systemd

This deployment uses **cron** (`cron/laravel`) for `php artisan schedule:run`.

Supervisor (not systemd) will own queue workers when `QUEUE_CONNECTION` leaves `sync`. See `supervisor/laravel-worker.conf.disabled`.

No extra systemd units are required for Phase A.

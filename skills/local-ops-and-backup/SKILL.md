---
name: local-ops-and-backup
description: Start, stop and troubleshoot BuildTrack locally or on a small server; read logs; check health; back up, restore and move the SQLite database. Use for "how do I run it", "it won't start", "where are the logs", and any backup/restore/migration-of-data task.
---

# Local ops, logs & backup

## When to use
- Starting or stopping the app, changing port or host, letting LAN devices connect.
- The app shows an error, won't start, or someone is locked out.
- Taking, scheduling, verifying or restoring backups; moving to a new machine.

## Start / stop
| Task | Command |
|---|---|
| Start (foreground, Ctrl+C stops) | `./start.sh` / `make up` |
| Start with demo data (empty DB) | `./start.sh --seed` / `make demo` |
| Background | `./start.sh --daemon` → `./start.sh --status` → `./start.sh --stop` |
| Other port / open browser / LAN | `./start.sh --port 9000 --open --lan` |
| Windows | `start.bat` |

`start.sh` does the following in order:
1. Checks PHP ≥ 8.1 and `pdo_sqlite`.
2. `php bin/console init`: creates `.env` from `.env.example` and the `data/` folders.
3. `php bin/console migrate`: creates or upgrades the schema, applies `ADMIN_EMAIL`, prints config warnings.
4. Picks a free port (`bin/console free-port`).
5. Runs `php -S HOST:PORT -t public bin/router.php` with `PHP_CLI_SERVER_WORKERS=4` and upload limits from `UPLOAD_MAX_MB`.

Daemon mode runs the server in its own process group and `--stop` kills the whole group, so no worker processes are orphaned. If a port stays busy anyway, find the process with `ss -ltnp | grep :8080`.

## Health & logs
```bash
make doctor                     # schema version, integrity, WAL, FKs, latest backup, email, warnings
php bin/console doctor --full   # full PRAGMA integrity_check instead of quick_check
make logs                       # tail app.log, mail.log, server.log
php bin/console audit --limit=50
```
- `data/logs/app.log`: exceptions. Users see "error logged with reference abcd1234"; grep for that ID.
- `data/logs/mail.log`: every outgoing email, including reset links when SMTP is not configured.
- `data/logs/server.log`: request log in `--daemon` mode (foreground mode prints to the terminal).
- In the browser: *Administration → System health / Audit log*.

## Troubleshooting
| Symptom | Fix |
|---|---|
| "PHP SQLite extension (pdo_sqlite) is missing" | `sudo apt install php-sqlite3` (Debian/Ubuntu), `dnf install php-pdo` (Fedora), enable `extension=pdo_sqlite` in `php.ini` (Windows) |
| "Setup problem … not writable" page | `chmod u+w data data/*`; under Apache: `chown -R www-data data` |
| `database is locked` errors | A long write or external tool holds the lock. Close `sqlite3` shells and DB browsers. Keep the DB on local disk, not a network share |
| User locked out | `php bin/console unlock user@x.com` |
| Forgot admin password / no admins | `php bin/console make-admin you@x.com && php bin/console reset-password you@x.com` |
| Reset links point to the wrong host | Set `APP_URL` in `.env` |
| Uploads fail for big files | Raise `UPLOAD_MAX_MB` and restart (`start.sh` passes PHP limits); with Apache/FPM also raise `upload_max_filesize` / `post_max_size` in php.ini |
| Times look 3 hours off | Check `APP_TIMEZONE`. The DB stores UTC; display converts |

## Backups
```bash
make backup                                     # php bin/console backup --keep=14
php bin/console backup --output=/mnt/usb/bt.sqlite
php bin/console backups                         # list data/backups/
```
- Uses `VACUUM INTO`: a consistent, compacted snapshot, safe while the app runs. **Never** back up by copying `buildtrack.sqlite` alone while the app runs; recent writes may still be in the `-wal` file.
- Uploaded files are in `data/uploads/` and are **not** inside the database. For a complete backup, include the folder: `tar czf buildtrack-files-$(date +%F).tgz data/uploads`.
- Nightly cron job (keep 30, copy off-machine):
  ```cron
  0 2 * * * cd /srv/buildtrack && php bin/console backup --keep=30 >/dev/null && rsync -a data/backups/ data/uploads backup-host:/backups/buildtrack/
  ```
- Verify a backup: `sqlite3 data/backups/<file>.sqlite 'PRAGMA integrity_check;'` should print `ok`. The test suite also checks backup creation, pruning and restore.

## Restore
```bash
./start.sh --stop                                # or Ctrl+C the foreground server
php bin/console restore data/backups/<file>.sqlite   # asks you to type "restore"; --yes to skip
./start.sh
```
- `restore` verifies the file (SQLite header, integrity_check, has `users`), saves the current DB as `data/backups/pre-restore-*.sqlite`, removes stale `-wal`/`-shm`, swaps the file in, then runs migrations so an older backup is upgraded.

## Move to another machine
1. Old machine: `make backup` and copy the newest `data/backups/*.sqlite`, plus `data/uploads/` and `.env`.
2. New machine: copy the code, put `.env` in place, copy uploads into `data/uploads/`.
3. Run `php bin/console init && php bin/console restore <file> --yes && ./start.sh`.

## Import from the old MariaDB version
```bash
mysqldump construction_project_system > old.sql   # or phpMyAdmin → Export → SQL
php bin/console import-mysql old.sql [--force] [--source-tz=Africa/Nairobi]
```
The import runs in one transaction and rolls back completely on any error or broken reference.

## Reset a dev environment
```bash
php bin/console seed --fresh      # backs up, deletes and re-seeds the database
```

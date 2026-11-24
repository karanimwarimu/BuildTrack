# BuildTrack — Construction Project Management

Plan and track construction projects: tasks and schedules, site progress, daily site logs, materials and equipment, expenses against budget, files and photos, deadline and low-stock alerts, reports and an admin console.

**Stack:** PHP 8.1+ · SQLite (WAL) · plain HTML/CSS. No database server, no Composer, no Node build. Data lives in one file: `data/buildtrack.sqlite`.

---

## Quick start

```bash
./start.sh            # or: make up
```

Then open the URL it prints, **http://localhost:8080**.

On the first run this creates `.env` with safe local defaults, creates `data/` and the SQLite database, applies migrations, and starts PHP's built-in web server. If the port is busy it picks the next free one and prints the actual URL.

| Want to… | Command |
|---|---|
| Try it with demo data | `./start.sh --seed` (or `make demo`) |
| Open the browser automatically | `./start.sh --open` |
| Use another port | `./start.sh --port 9000` |
| Let phones/tablets on your Wi-Fi connect | `./start.sh --lan` (prints your LAN address; see [run.md](run.md)) |
| Run in the background | `./start.sh --daemon`, then `./start.sh --status` / `./start.sh --stop` |
| Windows | `start.bat` (PHP must be on PATH, e.g. `C:\xampp\php`) |

**Demo accounts** (after `--seed`), all with password `BuildTrack-demo-2026`:
`admin@buildtrack.local`, `pm@buildtrack.local`, `supervisor@buildtrack.local`, `worker@buildtrack.local`.

### Prerequisites

On a new machine, run **`./setup.sh`**. It installs anything missing using the system's package manager, then prepares and tests the project. Full new-computer instructions, including Windows, are in [run.md](run.md#0-set-up-on-a-new-computer).

* PHP **8.1 or newer** with the `pdo_sqlite` extension (`fileinfo` is recommended for upload checks).
  * Ubuntu/Debian: `sudo apt install php-cli php-sqlite3`
  * Fedora: `sudo dnf install php-cli php-pdo`
  * macOS: `brew install php`
  * Windows: XAMPP's PHP works. Enable `extension=pdo_sqlite` in `php.ini`.
* `./start.sh` checks all of this and tells you what is missing.

---

## First-time admin setup

Pick **one** of these:

1. **Register the first account (easiest).** Open the app, click *Create the first account*, and that account becomes the administrator (`FIRST_USER_IS_ADMIN=true`).
2. **From `.env`.** Set `ADMIN_EMAIL=you@company.com` and `ADMIN_PASSWORD=…`, then run `./start.sh`. The admin is created with a forced password change at first login. Remove `ADMIN_PASSWORD` afterwards; startup warns until you do.
3. **From the command line**, for an existing account:
   ```bash
   php bin/console make-admin you@company.com      # or: make admin EMAIL=you@company.com
   php bin/console users                           # list accounts
   php bin/console set-role someone@x.com project_manager
   ```
4. **Development shortcut only.** `ALLOW_DEV_ADMIN_ELEVATION=true` shows a *Make me an administrator* button under **My account**. It works only with `APP_ENV=local` *and* when browsing from the same machine (127.0.0.1). It is ignored in production and every use is audited.

The admin console is at **`/admin`** (sidebar → *Administration*). It has four tabs: users and roles (create, change role, deactivate, set a temporary password, create a reset link), system health, audit log, and backups and CSV exports. The last active administrator can never be demoted or deactivated.

### Roles

| Role | Can do |
|---|---|
| Administrator | Everything, including users, audit log, backups |
| Project Manager | Projects, budgets and expenses, tasks, resources, delete records |
| Site Supervisor | Tasks, resources and allocations, daily site logs; view budgets |
| Worker | Own tasks: progress updates and photos; read site logs of their projects |

New self-registered accounts are **Workers**. Set `ALLOW_REGISTRATION=false` to make admins create every account; the first account can still be registered.

---

## Password reset workflow

* **Self-service:** *Forgot your password?* on the login page emails a single-use link that expires after 60 minutes.
* **No email server configured (default)**
  * The link is written to `data/logs/mail.log` (`make logs` follows it).
  * With `APP_ENV=local` it is also shown on screen, which makes testing easy.
  * In production it is **never** shown on screen.
* **Configure email:** set `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` (`tls` for port 587 STARTTLS, `ssl` for port 465), `SMTP_USER`, `SMTP_PASS` and `MAIL_FROM` in `.env`. Also set `APP_URL` so links point to your public address.
* **Admin-assisted (nobody gets locked out)**
  * *Administration → Users*: **Set temporary** (the user must change it at next sign-in) or **Create reset link** (send it any way you like).
  * On the server:
    ```bash
    php bin/console reset-password user@x.com    # prompts, or generates a temporary password
    php bin/console reset-link user@x.com        # prints a single-use link
    php bin/console unlock user@x.com            # clears a sign-in lockout (5 failures / 15 min)
    ```

Changing or resetting a password signs that user out on every other device.

---

## Configuration (`.env`)

`.env` is created from [`.env.example`](.env.example). Real environment variables override it (for example `APP_PORT=9000 ./start.sh`).

| Variable | Default | Notes |
|---|---|---|
| `APP_ENV` | `local` | `production` hides error details and on-screen reset links, and disables dev elevation |
| `APP_HOST` / `APP_PORT` | `127.0.0.1` / `8080` | `0.0.0.0` (or `--lan`) to listen on the network |
| `APP_URL` | *(empty)* | Public base URL for reset links. **Required in production** |
| `APP_TIMEZONE` | `Africa/Nairobi` | Used for "today", due dates and displayed times |
| `CURRENCY` | `KSh` | Label shown before amounts |
| `DB_PATH` | `data/buildtrack.sqlite` | Keep it on local disk (not a network share) |
| `ALLOW_REGISTRATION` | `true` | Self sign-up for new (Worker) accounts |
| `FIRST_USER_IS_ADMIN` | `true` | First registered account becomes admin |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` / `ADMIN_NAME` | *(empty)* | Bootstrap an admin on `migrate`/`start.sh` |
| `ALLOW_DEV_ADMIN_ELEVATION` | `false` | Dev-only self-promotion (see above) |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_MINUTES` | `5` / `15` | Brute-force protection |
| `RESET_TOKEN_TTL_MINUTES` | `60` | Reset link lifetime |
| `UPLOAD_MAX_MB` | `10` | Per-file upload limit (`start.sh` sets PHP's limits to match) |
| `SMTP_*`, `MAIL_FROM`, `MAIL_FROM_NAME` | *(empty)* | Optional outgoing email |
| `TRUST_PROXY` | `false` | Set `true` behind Render/Cloudflare/Nginx: real visitor IP (`CF-Connecting-IP`) and HTTPS detection |
| `DEMO_MODE` | `false` | Public showcase: demo data loaded on container start, shared demo accounts locked, banner shown |
| `LOG_STDERR` | `false` | Also write app logs to stderr (container log viewers) |

`.env` holds secrets (SMTP password, bootstrap admin password). It is created with `0600` permissions and is git-ignored.

---

## Backups, restore and updates

```bash
make backup                                   # = php bin/console backup --keep=14
php bin/console backups                       # list data/backups/
php bin/console restore data/backups/buildtrack-YYYYMMDD-HHMMSS-xxxx.sqlite
```

* Backups use SQLite's `VACUUM INTO`, a consistent snapshot that is safe while the app is running.
* Admins can also download a fresh backup from *Administration → Backups & exports*.
* **Restore:** stop the app first (`./start.sh --stop` or Ctrl+C). The current database is saved as `pre-restore-*.sqlite` before it is replaced. Newer migrations are applied automatically to older backups.
* **Nightly backups with cron:**
  `0 2 * * * cd /path/to/construction-project-system && php bin/console backup --keep=30 >/dev/null`
* **Uploaded files** live in `data/uploads/`. Back up the whole `data/` folder (for example with `rsync`) to include them.
* **Updating the app:**
  1. Run `make backup`.
  2. Replace the code (for example `git pull`).
  3. Run `./start.sh` or `php bin/console migrate`.

  Migrations run automatically and only once each.

### Coming from the old MariaDB/XAMPP version

1. Export the old database with data: phpMyAdmin → *construction_project_system* → *Export* → SQL, or `mysqldump construction_project_system > old.sql`.
2. Run `php bin/console import-mysql old.sql`.
   * It needs an empty database; add `--force` to wipe the current one (a backup is taken first).
   * Timestamps are converted from `APP_TIMEZONE` (override with `--source-tz=…`).
   * Existing passwords keep working.

The original schema is kept for reference in [`docs/legacy-mariadb-schema.sql`](docs/legacy-mariadb-schema.sql).

---

## Tests and checks

```bash
make test        # php tests/run.php: unit tests + end-to-end HTTP test (~25 s)
make lint        # php -l on every file
make doctor      # schema version, integrity check, WAL, foreign keys, backups, config warnings
php tests/run.php reset     # run only tests whose name contains "reset"
```

The suite creates throwaway databases in `tests/tmp/` and never touches `data/`. The manual acceptance checklist is in [`docs/manual-test-plan.md`](docs/manual-test-plan.md).

## Logs

* `data/logs/app.log`: errors, with a reference ID shown to the user.
* `data/logs/mail.log`: outgoing mail and reset links.
* `data/logs/server.log`: request log when running with `--daemon`.

Run `make logs` to follow all three.

---

## Hosting on Render (free demo)

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/karanimwarimu/BuildTrack)

The repository includes a `Dockerfile` and a `render.yaml` Blueprint for a free public demo on [Render](https://render.com). Push to GitHub, then in Render choose **New → Blueprint**.

On the free plan the data resets on every restart, so it runs in `DEMO_MODE` with locked demo accounts. Step-by-step instructions, and how to move to a paid plan with a persistent disk for real use, are in [run.md](run.md#4-hosting-on-render-free-public-demo).

## Deploying on a small server

`./start.sh --lan` is fine for an office LAN. For internet-facing use:

1. Use Apache or Nginx with PHP-FPM and set the document root to **`public/`**.
   * Apache: `public/.htaccess` provides pretty URLs. If you cannot change the document root (shared hosting or XAMPP `htdocs`), the root `.htaccess` forwards to `public/` and blocks everything else.
   * Nginx: `root /srv/buildtrack/public; location / { try_files $uri /index.php?$query_string; } location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_pass unix:/run/php/php8.1-fpm.sock; }`
2. Enable HTTPS (for example Let's Encrypt).
3. In `.env`, set `APP_ENV=production` and `APP_URL=https://your.domain`, and configure SMTP.
4. Make `data/` writable by the PHP user and nothing else (`chown -R www-data data && chmod 750 data`).
5. Add the nightly backup cron job above, and copy backups off the machine.

See [ARCHITECTURE.md](ARCHITECTURE.md) for components, request lifecycle, schema diagram and security notes.

## Project layout

```
public/        web root (index.php front controller, assets/)
pages/         one controller+template per screen (auth/ for public pages)
src/           config, db + migrator, auth, mailer, attachments, backups, domain queries, layout
migrations/    NNN_name.sql schema changes
seeds/         demo data
bin/           console (CLI), router.php (built-in server router)
tests/         run.php + *_test.php
skills/        playbooks for developing this codebase (see below)
data/          runtime data — database, uploads, backups, logs (git-ignored)
```

## Development skills

[`skills/`](skills/) contains step-by-step playbooks for common changes. Each has triggers, conventions and verification steps.

| Skill | Use it when |
|---|---|
| [`sqlite-schema-and-migrations`](skills/sqlite-schema-and-migrations/SKILL.md) | Adding or changing tables, columns, indexes, seeds, imports |
| [`auth-and-admin-rbac`](skills/auth-and-admin-rbac/SKILL.md) | Touching login, registration, resets, roles, permissions, admin access |
| [`construction-feature-workflow`](skills/construction-feature-workflow/SKILL.md) | Adding a new module or entity (e.g. inspections, purchase orders) end to end |
| [`local-ops-and-backup`](skills/local-ops-and-backup/SKILL.md) | Starting, stopping, logs, troubleshooting, backup and restore |

# BuildTrack — System Architecture

BuildTrack is a construction project management app: projects, scheduled tasks, progress updates, daily site logs, resources/stock, expenses vs. budget, file attachments, deadline alerts, reports and an admin console.

It is deliberately small and dependency-free: **plain PHP 8.1+, SQLite (WAL mode), HTML/CSS, a few lines of optional JavaScript**. There is no Composer, Node build or database server to install. One command (`./start.sh`) creates the configuration and database and starts the web server.

---

## 1. Components

```
┌────────────────────────────── Browser ──────────────────────────────┐
│  Server-rendered HTML pages + app.css (+ tiny app.js for mobile nav)  │
└───────────────────────────────┬──────────────────────────────────────┘
                                │ HTTP (cookie session, CSRF-protected forms)
┌───────────────────────────────▼──────────────────────────────────────┐
│ Web server                                                            │
│   ./start.sh → php -S host:port -t public bin/router.php  (dev/small)  │
│   or Apache/Nginx + PHP-FPM with document root = public/              │
│                                                                       │
│ bin/router.php   static files from public/, /admin → ?page=admin,     │
│                  404 for anything else (data/, src/, .env never served)│
├───────────────────────────────────────────────────────────────────────┤
│ public/index.php  Front controller                                    │
│   1. src/bootstrap.php   config (.env), timezone, includes            │
│   2. bootstrap_web()     session hardening, security headers,         │
│                          DB connect + auto-migrate on first run       │
│   3. route table         PUBLIC_ROUTES (login/register/forgot/reset)  │
│                          PRIVATE_ROUTES (everything else, login gate)  │
│   4. forced password change gate (must_change_password)               │
│   5. require pages/<page>.php                                         │
├───────────────────────────────────────────────────────────────────────┤
│ pages/            One file per screen: handles POST (validate → write │
│                   → audit → flash → redirect), then GET (query → HTML) │
│   auth/  login, register, forgot, reset, logout                       │
│   dashboard, projects, project (detail), tasks, progress, sitelogs,   │
│   resources, expenses, alerts, reports, attachment, account, admin    │
├───────────────────────────────────────────────────────────────────────┤
│ src/   Domain + infrastructure libraries (plain functions, PDO param) │
│   config.php       .env loader, config()/config_bool(), warnings      │
│   db.php           SQLite connection, pragmas, app_today(), migrator  │
│   helpers.php      escaping, CSRF, flash, formatting, PERMISSIONS/can,│
│                    visibility scopes, audit(), CSV                    │
│   auth.php         login/throttle, registration, reset tokens,        │
│                    last-admin guard, dev elevation, env admin         │
│   mailer.php       SMTP client (STARTTLS/TLS/AUTH LOGIN) or log file  │
│   attachments.php  upload validation + storage in data/uploads        │
│   backup.php       VACUUM INTO backups, restore, prune, health        │
│   construction.php low-stock, budget-vs-actual, expense breakdown     │
│   mysql_import.php MariaDB dump → SQLite importer                     │
│   layout/          header/footer (app shell) and auth page layout     │
├───────────────────────────────────────────────────────────────────────┤
│ bin/console       CLI: init, migrate, doctor, seed, make-admin,       │
│                   reset-password, reset-link, backup, restore, import │
├───────────────────────────────────────────────────────────────────────┤
│ data/  (runtime, git-ignored, never web-served)                        │
│   buildtrack.sqlite (+ -wal, -shm)   uploads/   backups/              │
│   logs/app.log  logs/mail.log  logs/server.log   sessions/            │
└───────────────────────────────────────────────────────────────────────┘
```

### Directory map

| Path | Purpose |
|---|---|
| `public/` | Web root. Only `index.php`, `assets/` and `.htaccess` are reachable. |
| `pages/` | Screen controllers + templates (one per route). |
| `src/` | Reusable logic. No HTML except `src/layout/`. |
| `migrations/NNN_name.sql` | Ordered schema changes (applied once each). |
| `seeds/demo.php` | Demo data (`php bin/console seed`). |
| `bin/console`, `bin/router.php` | CLI tool; router for PHP's built-in server. |
| `tests/` | Dependency-free test runner, unit tests and an end-to-end HTTP test. |
| `skills/` | Step-by-step playbooks for working on this codebase. |
| `docs/` | Legacy MariaDB schema, manual acceptance test plan. |

---

## 2. Request and authentication lifecycle

```
Browser                    index.php / bootstrap                       SQLite
   │  GET /admin                │                                         │
   │──────────────────────────▶ │ router: /admin → page=admin             │
   │                            │ session_start (data/sessions, HttpOnly, │
   │                            │   SameSite=Lax, strict mode)            │
   │                            │ db(): PRAGMA foreign_keys/WAL/busy ────▶│
   │                            │ PRAGMA user_version < latest? migrate ─▶│
   │                            │ page is private → user():               │
   │                            │   SELECT user by $_SESSION['uid'] ─────▶│
   │                            │   reject if inactive or                 │
   │                            │   session_version ≠ $_SESSION['sv']     │
   │◀── 302 ?page=login ────────│ not signed in                           │
   │                            │                                         │
   │  POST login (+_csrf)       │ verify_csrf()                           │
   │──────────────────────────▶ │ attempt_login():                        │
   │                            │   lockout check (login_attempts) ──────▶│
   │                            │   password_verify (Argon2id/bcrypt)     │
   │                            │   rehash if needed, record attempt,     │
   │                            │   audit auth.login ────────────────────▶│
   │                            │ session_regenerate_id(true)             │
   │◀── 302 /  (Set-Cookie) ────│ uid + session_version in session        │
   │                            │                                         │
   │  GET /admin                │ must_change_password? → /account        │
   │──────────────────────────▶ │ require_permission('admin.access')      │
   │◀── 200 HTML / 403 ─────────│ (PERMISSIONS map in helpers.php)        │
```

* **Write requests** follow Post/Redirect/Get: `verify_csrf()` → permission check → validate → write (in a transaction where it matters) → `audit()` → `flash()` → `redirect()`.
* **Roles are re-read on every request.** A role change, deactivation or password reset takes effect immediately. A password change increments `session_version`, which signs out all other sessions.

### Password reset flow

```
Forgot page ──email──▶ request_password_reset()
                         ├─ unknown/inactive email → same response, nothing stored
                         ├─ >3 requests / 15 min → silently throttled
                         └─ token = 32 random bytes (hex); store SHA-256(token), expires_at = now + TTL
                              ├─ SMTP configured → email link
                              └─ no SMTP → write to data/logs/mail.log
                                            + show on screen ONLY when APP_ENV=local
Reset page ──token──▶ find_valid_reset(): hash match, unused, unexpired, user active
            ──new pw─▶ complete_password_reset(): set hash, mark token used,
                       session_version++ (sign out everywhere), clear lockout, audit
```

Admin-assisted fallbacks for installs without email:
* Admin page → "Set temporary" (forces a change at next sign-in) or "Create reset link" (to send by WhatsApp or SMS, for example).
* Server shell → `php bin/console reset-password <email>` / `reset-link <email>` / `unlock <email>`.

### Getting admin access

| Path | When to use | Safety |
|---|---|---|
| First registered account becomes admin (`FIRST_USER_IS_ADMIN=true`) | Fresh install | Count and insert run in one `BEGIN IMMEDIATE` transaction, so only one "first" user is possible |
| `ADMIN_EMAIL` + `ADMIN_PASSWORD` in `.env` | Scripted installs | Applied by `migrate` / `start.sh`; forces a password change; startup warns until `ADMIN_PASSWORD` is removed |
| `php bin/console make-admin <email>` | Recovery, promotions | Needs shell access to the server |
| `ALLOW_DEV_ADMIN_ELEVATION=true` | Local development only | Also requires `APP_ENV=local` **and** a loopback client IP; button only, POST + CSRF; audited; ignored in production with a warning |

The **last active admin can never be demoted or deactivated**. This is enforced in `change_user_role()` / `set_user_active()` inside a transaction, for both the UI and the CLI.

---

## 3. Data model (SQLite)

```
                     ┌──────────────┐
                     │    users     │ id, full_name, email (UNIQUE NOCASE), password_hash,
                     │              │ role CHECK(admin|project_manager|site_supervisor|worker),
                     │              │ is_active, must_change_password, session_version, last_login_at
                     └──────┬───────┘
   manager_id (SET NULL)    │ created_by / recorded_by / uploaded_by (RESTRICT)
         ┌──────────────────┼─────────────────────┬────────────────────┐
┌────────▼───────┐          │                     │                    │
│    projects    │ budget, status CHECK, dates CHECK(start ≤ end)      │
└───┬────┬───┬───┘                                                     │
    │    │   └───────────────(CASCADE)──────────────┐                  │
    │    └─────(CASCADE)───────────┐                │                  │
    │(CASCADE)                     │                │                  │
┌───▼──────────┐  ┌────────────────▼──┐  ┌──────────▼──────┐  ┌────────▼──────┐
│    tasks     │  │     expenses      │  │    site_logs    │  │  attachments  │
│ assigned_to ─┼─▶users (SET NULL)    │  │ log_date, crew, │  │ project_id,   │
│ status,      │  │ category CHECK,   │  │ work_done,      │  │ task_id NULL, │
│ priority,    │  │ amount ≥ 0,       │  │ issues, safety  │  │ stored_name → │
│ progress 0-100│ │ spent_on          │  └─────────────────┘  │ data/uploads/ │
└──┬───────┬───┘  └───────────────────┘                       └───────────────┘
   │(CASCADE)  │(CASCADE)
┌──▼─────────────────┐   ┌────────────────────┐      ┌──────────────┐
│  progress_updates  │   │   task_resources   │─────▶│  resources   │ quantity_available,
│ % + status + notes │   │ UNIQUE(task,res),  │(CASC)│              │ low_stock_threshold
└────────────────────┘   │ quantity > 0       │      └──────────────┘
                         └────────────────────┘

Auth/ops tables: password_resets (token_hash UNIQUE, expires_at, used_at)
                 login_attempts (email, ip, success, created_at)
                 audit_log (user_id SET NULL, action, entity_type, entity_id, details JSON, ip)
                 schema_migrations (version, name, applied_at)   + PRAGMA user_version
```

### MariaDB → SQLite conversion

| MariaDB | SQLite |
|---|---|
| `INT UNSIGNED AUTO_INCREMENT` | `INTEGER PRIMARY KEY AUTOINCREMENT` (IDs never reused) |
| `ENUM('a','b')` | `TEXT CHECK (col IN ('a','b'))` |
| `DECIMAL(14,2)` | `NUMERIC` + `CHECK (>= 0)`; rounded to 2 dp in PHP |
| `ON UPDATE CURRENT_TIMESTAMP` | `AFTER UPDATE` triggers (`trg_*_updated_at`) |
| `CURDATE()`, `DATE_ADD(…, INTERVAL 7 DAY)` | `app_today()`, `date(app_today(), '+7 days')` |
| `DATEDIFF(a, b)` | `julianday(a) - julianday(b)` |
| `INSERT … ON DUPLICATE KEY UPDATE` | `INSERT … ON CONFLICT(cols) DO UPDATE SET … = excluded.…` |
| `utf8mb4_unicode_ci` unique email | `email … UNIQUE COLLATE NOCASE` (+ lower-casing in PHP) |
| Local-time `TIMESTAMP` | UTC text via `CURRENT_TIMESTAMP`; shown with `datetime_label()` in `APP_TIMEZONE` |

`app_today()` is a PHP function registered on each connection. SQLite's `date('now')` is UTC, which would put "due today" on the wrong side of midnight in Nairobi (UTC+3).

Migrating existing data: `php bin/console import-mysql dump.sql`. It keeps IDs and converts timestamps from the old server's timezone to UTC. Old bcrypt password hashes keep working and are upgraded to Argon2id at next login.

---

## 4. Authorization (RBAC)

Permissions are named strings mapped to roles in one place, `PERMISSIONS` in `src/helpers.php`. Pages call `can('x')` / `require_permission('x')`.

| Permission | admin | project_manager | site_supervisor | worker |
|---|:-:|:-:|:-:|:-:|
| admin.access, users.manage, audit.view, backup.manage | ✔ | | | |
| projects.manage / projects.delete | ✔ | ✔ | | |
| projects.view_all (otherwise: only projects with an assigned task) | ✔ | ✔ | ✔ | |
| tasks.manage | ✔ | ✔ | ✔ | |
| tasks.delete, resources.delete | ✔ | ✔ | | |
| resources.manage | ✔ | ✔ | ✔ | |
| expenses.view | ✔ | ✔ | ✔ | |
| expenses.manage | ✔ | ✔ | | |
| sitelogs.create | ✔ | ✔ | ✔ | |
| sitelogs.manage_any, attachments.manage_any | ✔ | ✔ | | |
| Update progress on own tasks, upload files to own projects, read site logs of own projects | ✔ | ✔ | ✔ | ✔ |

Row-level visibility for workers comes from `current_task_access_sql()` / `current_project_access_sql()`, which return a SQL fragment plus parameters.

---

## 5. Key trade-offs

**SQLite instead of MariaDB**
* ✔ Nothing to install or administer. The database is one file, backups are one command, and it starts in milliseconds.
* ✔ WAL mode lets many readers run alongside one writer. `busy_timeout=5000` makes a second writer wait up to 5 s instead of failing. Writes that read-then-write (resource allocation, registration, role changes) use `BEGIN IMMEDIATE` to take the write lock up front and avoid lock-upgrade deadlocks.
* ✘ There is one writer at a time. That is fine for a construction company's office and site staff (tens of concurrent users, short writes). It is not suitable for thousands of simultaneous writers or several app servers sharing one database over network storage. **Keep the database on local disk, never on NFS or SMB.**
* ✘ `NUMERIC` money is binary floating point underneath. Amounts are rounded to 2 dp on input and display. Switch to integer cents if you ever need accounting-grade sums.

**PHP's built-in server (`./start.sh`)**
* ✔ Zero setup. `PHP_CLI_SERVER_WORKERS=4` serves requests in parallel.
* ✘ It is not hardened for direct internet exposure and has no TLS. For anything beyond a LAN, run Apache or Nginx with PHP-FPM and `public/` as the document root, behind HTTPS (see README → Deploying).

**Server-rendered pages without a framework**
* ✔ About 4k lines of application code, easy to read, no build step, works without JavaScript.
* ✘ There are no reusable form components. Conventions are documented in `skills/construction-feature-workflow` to keep pages consistent.

---

## 6. Security notes

* **Passwords:** Argon2id (bcrypt fallback). A dummy hash is verified for unknown emails so response timing does not reveal which accounts exist. Minimum 10 characters.
* **Brute force:** 5 failures per email (or 20 per IP) in 15 minutes locks sign-in for 15 minutes. `bin/console unlock <email>` clears it.
* **Sessions:** HttpOnly, SameSite=Lax, Secure under HTTPS, strict mode, ID regenerated at login, server-side invalidation through `session_version`. Logout is POST + CSRF.
* **CSRF:** every POST form carries a per-session token. Requests with a missing or empty token are rejected with 419.
* **Reset tokens:** 256-bit, stored only as SHA-256, single-use, expire after `RESET_TOKEN_TTL_MINUTES`, and are invalidated when a new link is issued or the password changes. Links are shown on screen **only** in `APP_ENV=local`; otherwise anyone could reset anyone's password.
* **Admin elevation:** the dev shortcut needs three independent conditions (flag, local env, loopback IP) and is audited. The CLI promotion path requires shell access, which already implies full control of the server.
* **Output:** everything is escaped with `e()`. CSP blocks framing and third-party scripts. `X-Content-Type-Options`, `X-Frame-Options` and `Referrer-Policy` are set.
* **Uploads:** extension allow-list, content sniffed with `finfo` and checked against the extension, random stored names, stored outside the web root, served through a permission check with `nosniff` and a sandboxing CSP.
* **CSV exports** neutralise formula injection (`=`, `+`, `-`, `@`).
* **Audit log:** sign-ins (including failures), role changes, password events, deletions, backups and exports are recorded with actor, IP and a JSON diff.
* **Files that must never be served:** `.env`, `data/`, `src/`, `pages/`, `migrations/`. The router and `.htaccess` files deny them, and the HTTP test checks this.

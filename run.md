# Running BuildTrack

All commands are run from the project folder:

```bash
cd ~/PROJECTS/alma/construction-project-system
```

## 0. Set up on a new computer

BuildTrack only needs **PHP 8.1 or newer** with the **pdo_sqlite** and **fileinfo** extensions. There is no database server, Composer or Node.js to install. The `sqlite3` command-line tool and `make` are optional extras.

### Step 1: Get the project onto the machine

Use **one** of these:

- **Copy the folder** (USB drive, zip, `scp`). Leave out `data/` and `.env` if you want a fresh, empty install.
- **Clone it with git**, if you have pushed it to a repository:
  ```bash
  git clone <your-repository-url> construction-project-system
  ```

Then go into the folder:

```bash
cd construction-project-system
```

### Step 2: Run the setup script (Linux / macOS)

```bash
chmod +x setup.sh start.sh bin/console    # only needed if the files lost their permissions
./setup.sh                 # installs missing packages (asks first), creates .env + database, runs the tests
./setup.sh --seed          # same, plus demo data
./setup.sh --check         # only report what is missing; installs nothing
```

`setup.sh` detects your system's package manager (apt, dnf, pacman, zypper, apk or Homebrew) and installs the following:

| System | Packages it installs |
|---|---|
| Ubuntu 22.04+ / Debian 12+ / Mint | `php-cli php-sqlite3 php-common sqlite3 make` |
| Fedora / RHEL / Rocky | `php-cli php-pdo php-common sqlite make` |
| Arch / Manjaro | `php php-sqlite sqlite make` (and enables `pdo_sqlite` in `php.ini`) |
| openSUSE | `php8 php8-pdo php8-sqlite php8-fileinfo sqlite3 make` |
| Alpine | `php83 php83-pdo_sqlite php83-fileinfo php83-session php83-ctype sqlite make` |
| macOS (needs [Homebrew](https://brew.sh)) | `php sqlite make` |

Installing packages uses `sudo`, so it will ask for your password.

**Older Ubuntu (20.04 or earlier)** only offers PHP 7.4, which is too old. Add a newer PHP first, then re-run `./setup.sh`:

```bash
sudo add-apt-repository ppa:ondrej/php && sudo apt update
```

**Manual install instead of the script (Ubuntu/Debian):**

```bash
sudo apt update && sudo apt install -y php-cli php-sqlite3 sqlite3 make
php -v                                # must say 8.1 or newer
php -m | grep -E 'pdo_sqlite|fileinfo'  # both must be listed
./start.sh
```

### Step 2 (Windows instead)

1. Install PHP 8.1+. Either:
   - install [XAMPP](https://www.apachefriends.org) (PHP is in `C:\xampp\php`), or
   - download the "Thread Safe" zip from [windows.php.net](https://windows.php.net/download) and unzip it to `C:\php`.
2. Add that folder to your **PATH** (Start → "Edit the system environment variables" → Environment Variables → Path → New).
3. In that folder, open `php.ini` (copy `php.ini-development` to `php.ini` if it doesn't exist) and make sure these lines have **no** `;` in front:
   ```
   extension_dir = "ext"
   extension=pdo_sqlite
   extension=fileinfo
   ```
4. Open a new Command Prompt in the project folder:
   - `php -v` should show 8.1 or newer.
   - `start.bat` starts the app.
   - `start.bat --seed` starts it with demo data.

### Step 3: Start the app

```bash
./start.sh            # then open http://localhost:8080
./start.sh --lan      # or: also reachable from phones on the same Wi-Fi (section 2)
```

On an empty install the **first account you register becomes the administrator**.

### Moving your existing data to the new computer

On the **old** computer:

```bash
make backup                              # creates data/backups/buildtrack-<date>.sqlite
```

Copy these to the new computer's project folder:

- the newest backup file from `data/backups/`
- the whole `data/uploads/` folder (files and photos)
- `.env` (your settings)

On the **new** computer:

```bash
./setup.sh
php bin/console restore data/backups/<file>.sqlite --yes
./start.sh
```

## 1. Start on this computer only

```bash
./start.sh
```

Open **http://localhost:8080** in your browser. Press **Ctrl+C** to stop.

- First time with demo data: `./start.sh --seed` (demo password: `BuildTrack-demo-2026`)
- Open the browser automatically: `./start.sh --open`
- Use another port: `./start.sh --port 9000`

## 2. Open it on your phone (same Wi-Fi)

The normal start only accepts connections from this computer. To use a phone or tablet, start in **network mode**:

```bash
./start.sh --lan
```

The startup message prints your network address, for example:

```
On your network (phones, tablets): http://192.168.0.107:8080
```

Type that address into your phone's browser.

**Checklist if the phone keeps loading:**

1. **Network mode:** you started with `--lan` (plain `./start.sh` is local-only). Check with:
   ```bash
   ss -ltn | grep 8080
   ```
   It must show `0.0.0.0:8080`. If it shows `127.0.0.1:8080`, the app is in local-only mode.
2. **Same Wi-Fi:** the phone is on the same Wi-Fi as the computer, not mobile data. Guest Wi-Fi networks often block devices from reaching each other.
3. **Current address:** the computer's address can change after a reboot or reconnect. Check it with:
   ```bash
   hostname -I      # use the 192.168.x.x address
   ```
4. **Firewall:** if a firewall is enabled, allow the port:
   ```bash
   sudo ufw status
   sudo ufw allow 8080/tcp     # only needed if status says "active"
   ```

**Always use network mode:** set this in `.env` and plain `./start.sh` will listen on the network:

```
APP_HOST=0.0.0.0
```

> Anyone on the same Wi-Fi can reach the login page while network mode is on. Accounts and passwords still protect the data. The connection is plain `http`, which is fine at home or in the office. Do not expose this port to the internet; see "Deploying on a small server" in README.md.

## 3. Keep it running in the background

```bash
./start.sh --lan --daemon    # start in the background (survives closing the terminal)
./start.sh --status          # is it running? shows the URL
./start.sh --stop            # stop it
```

Background logs are in `data/logs/server.log`. Follow them with `make logs`.

## 4. Hosting on Render (free public demo)

The repository includes everything Render needs:

| File | Purpose |
|---|---|
| `Dockerfile` | Container with Apache + PHP 8.3, serving only `public/` |
| `docker/` | Apache site config, PHP settings, start-up script (migrate, load demo data, start Apache) |
| `render.yaml` | Blueprint: one free web service with demo settings |
| `public/healthz.php` | Health check Render polls (returns `ok`) |

> **Free plan = demo only.** Free instances have no persistent disk. The database is **wiped on every deploy, restart and idle spin-down** (after ~15 minutes without visitors), and fresh demo data is loaded each time it starts. The first visit after idling takes about a minute while the server wakes up. Do not use this for real project data; see "Moving to real use" below.

### Step 1: Put the code on GitHub (or GitLab)

Render deploys from a Git repository.

1. Create an **empty** repository on github.com, for example `buildtrack`. Don't add a README.
2. In the project folder, commit and push:

```bash
git add -A
git commit -m "BuildTrack: SQLite rework + Render deployment"
git branch -M main
git remote add origin https://github.com/<your-username>/buildtrack.git
git push -u origin main
```

`.gitignore` already keeps `.env` and `data/` (your local database, uploads and logs) out of the repository.

### Step 2: Create the service on Render

**Quickest:** click this button (also in the README), sign in to Render, then click **Apply**:

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/karanimwarimu/BuildTrack)

**Or by hand:**

1. Sign in at [dashboard.render.com](https://dashboard.render.com) and connect your GitHub account.
2. Click **New → Blueprint**, choose your `buildtrack` repository, and click **Apply**.
3. Render reads `render.yaml`, builds the Docker image (3–5 minutes the first time), and starts the service on the **Free** plan.
4. When the deploy shows **Live**, open the URL Render gives you, for example `https://buildtrack-demo.onrender.com`.

Alternative without the blueprint: **New → Web Service**, then:

1. Pick the repository.
2. Set **Language = Docker** and **Instance type = Free**.
3. Add the environment variables listed in `render.yaml`.

### Step 3: Use the demo

The login page lists the demo accounts. Click a role to sign in instantly; the password for all of them is `BuildTrack-demo-2026`.

| Account | Role |
|---|---|
| `admin@buildtrack.local` | Administrator |
| `pm@buildtrack.local` | Project Manager |
| `supervisor@buildtrack.local` | Site Supervisor |
| `worker@buildtrack.local` | Worker |

**What the demo settings do** (from `render.yaml`):

| Setting | Effect |
|---|---|
| `APP_ENV=production` | No error details or on-screen reset links shown to visitors |
| `DEMO_MODE=true` | Loads demo data at every start, shows a "public demo" banner, and **locks the demo accounts**: visitors can't change their passwords, roles or status, so nobody can lock others out |
| `ALLOW_REGISTRATION=false` | Visitors use the demo accounts, so the demo collects no personal data |
| `TRUST_PROXY=true` | Uses the visitor's real IP for the login lockout, and secure cookies over Render's HTTPS |
| `UPLOAD_MAX_MB=2` | Keeps the demo from being used as free file hosting |

### Updating the demo

Push to GitHub and Render redeploys automatically (`autoDeploy: true`):

```bash
git add -A && git commit -m "Describe the change" && git push
```

Any data visitors entered is reset by the redeploy.

### Logs and troubleshooting

| Problem | What to check |
|---|---|
| Deploy failed | Render dashboard → your service → **Events / Logs** shows the Docker build output |
| App errors | Same **Logs** tab (`LOG_STDERR=true` sends app errors there) |
| "Service unavailable" for ~1 minute | Normal after idling; the free instance is waking up |
| Test the container on your own machine first | See below |

```bash
docker build -t buildtrack .
docker run --rm -p 8080:10000 -e DEMO_MODE=true buildtrack
# open http://localhost:8080
```

### Moving to real use later

To keep data permanently on Render:

1. Switch the service to a **paid instance** (Starter or higher).
2. Add a **Persistent Disk** mounted at `/var/www/html/data` (1 GB is plenty).
3. Set `DEMO_MODE=false`.
4. Set `ALLOW_REGISTRATION` as you like, and `APP_URL` if you use your own domain.

The first account registered then becomes the administrator. Back up with **Administration → Backups → Download a fresh backup**.

## 5. Quick reference

| Task | Command |
|---|---|
| First-time setup (installs dependencies) | `./setup.sh` |
| Check dependencies only | `./setup.sh --check` |
| Start (this computer only) | `./start.sh` |
| Start for phones on the same Wi-Fi | `./start.sh --lan` |
| Background + network | `./start.sh --lan --daemon` |
| Stop background server | `./start.sh --stop` |
| Load demo data | `./start.sh --seed` |
| Make someone admin | `php bin/console make-admin you@example.com` |
| Reset a password | `php bin/console reset-password you@example.com` |
| Back up the database | `make backup` |
| Health check | `make doctor` |
| Test the Render container locally | `docker build -t buildtrack . && docker run --rm -p 8080:10000 -e DEMO_MODE=true buildtrack` |
| Redeploy the Render demo | `git push` (auto-deploys) |

More detail: [README.md](README.md) (setup, admin access, password resets, backups) and [skills/local-ops-and-backup/SKILL.md](skills/local-ops-and-backup/SKILL.md) (troubleshooting).

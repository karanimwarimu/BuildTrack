#!/usr/bin/env bash
# BuildTrack first-time setup for a new machine.
#   ./setup.sh            install missing dependencies, create .env + database, run checks
#   ./setup.sh --seed     ...and load demo data
#   ./setup.sh --check    only report what is missing; install nothing
#   ./setup.sh --yes      don't ask before installing packages
#
# Dependencies: PHP 8.1+ (CLI) with pdo_sqlite + fileinfo. Optional: sqlite3 CLI, make, git.
# No database server, Composer or Node is needed.
set -euo pipefail
cd "$(dirname "$0")"

SEED=0; CHECK_ONLY=0; ASSUME_YES=0
for arg in "$@"; do
  case "$arg" in
    --seed) SEED=1 ;;
    --check) CHECK_ONLY=1 ;;
    --yes|-y) ASSUME_YES=1 ;;
    -h|--help) sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $arg"; exit 1 ;;
  esac
done

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
ok()    { printf '  \033[32m✔\033[0m %s\n' "$*"; }
miss()  { printf '  \033[31m✘\033[0m %s\n' "$*"; }

SUDO=""; [[ "$(id -u)" -ne 0 ]] && command -v sudo >/dev/null 2>&1 && SUDO="sudo"

# --- detect the package manager -------------------------------------------------
PM=""; OS_NAME="$(uname -s)"
if [[ "$OS_NAME" == "Darwin" ]]; then PM="brew"
elif command -v apt-get >/dev/null 2>&1; then PM="apt"
elif command -v dnf >/dev/null 2>&1; then PM="dnf"
elif command -v pacman >/dev/null 2>&1; then PM="pacman"
elif command -v zypper >/dev/null 2>&1; then PM="zypper"
elif command -v apk >/dev/null 2>&1; then PM="apk"
fi

php_ok()        { command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null; }
php_has()       { php -r "exit(extension_loaded('$1') ? 0 : 1);" 2>/dev/null; }

check_all() {
  MISSING=0
  if php_ok; then ok "PHP $(php -r 'echo PHP_VERSION;')"
  elif command -v php >/dev/null 2>&1; then miss "PHP $(php -r 'echo PHP_VERSION;') is too old (need 8.1+)"; MISSING=1
  else miss "PHP 8.1+ not installed"; MISSING=1; fi
  if php_ok; then
    for ext in pdo_sqlite fileinfo; do
      if php_has "$ext"; then ok "PHP extension $ext"; else miss "PHP extension $ext"; MISSING=1; fi
    done
  fi
  command -v sqlite3 >/dev/null 2>&1 && ok "sqlite3 CLI (optional)" || printf '  - sqlite3 CLI not installed (optional, for inspecting the database)\n'
  command -v make >/dev/null 2>&1 && ok "make (optional)" || printf '  - make not installed (optional; ./start.sh works without it)\n'
}

install_packages() {
  local pkgs=()
  case "$PM" in
    apt)
      # Ubuntu 22.04+ / Debian 12+ ship PHP 8.1+. Older releases need a newer PHP repository.
      local candidate
      candidate="$(apt-cache policy php-cli 2>/dev/null | sed -n 's/.*Candidate: [0-9]*:\{0,1\}\([0-9]*\.[0-9]*\).*/\1/p' | head -1)"
      if [[ -n "$candidate" ]] && ! awk -v v="$candidate" 'BEGIN{split(v,a,"."); exit !((a[1]>8)||(a[1]==8&&a[2]>=1))}'; then
        red "This system's packages only offer PHP $candidate. BuildTrack needs 8.1+."
        echo "  Ubuntu: sudo add-apt-repository ppa:ondrej/php && sudo apt update, then re-run ./setup.sh"
        echo "  Debian: see https://packages.sury.org/php/README.txt, then re-run ./setup.sh"
        exit 1
      fi
      pkgs=(php-cli php-sqlite3 php-common sqlite3 make)
      $SUDO apt-get update
      $SUDO apt-get install -y "${pkgs[@]}" ;;
    dnf)
      pkgs=(php-cli php-pdo php-common sqlite make)
      $SUDO dnf install -y "${pkgs[@]}" ;;
    pacman)
      pkgs=(php php-sqlite sqlite make)
      $SUDO pacman -S --needed --noconfirm "${pkgs[@]}"
      # Arch ships the extensions disabled; enable them in php.ini.
      local ini; ini="$(php -r 'echo php_ini_loaded_file();' 2>/dev/null || echo /etc/php/php.ini)"
      for ext in pdo_sqlite sqlite3; do
        $SUDO sed -i "s/^;extension=$ext\$/extension=$ext/" "$ini" || true
      done ;;
    zypper)
      pkgs=(php8 php8-pdo php8-sqlite php8-fileinfo sqlite3 make)
      $SUDO zypper --non-interactive install "${pkgs[@]}" ;;
    apk)
      pkgs=(php83 php83-pdo_sqlite php83-fileinfo php83-session php83-ctype sqlite make)
      $SUDO apk add "${pkgs[@]}"
      command -v php >/dev/null 2>&1 || $SUDO ln -sf "$(command -v php83)" /usr/local/bin/php ;;
    brew)
      pkgs=(php sqlite make)
      brew install "${pkgs[@]}" ;;
    *)
      red "Could not detect a supported package manager."
      echo "Install PHP 8.1+ with the pdo_sqlite and fileinfo extensions manually, then re-run ./setup.sh"
      exit 1 ;;
  esac
}

# --- 1. dependencies --------------------------------------------------------------
bold "BuildTrack setup"
echo "Checking dependencies (package manager: ${PM:-unknown})"
check_all

if [[ "$MISSING" == 1 ]]; then
  if [[ "$CHECK_ONLY" == 1 ]]; then red "Missing dependencies (run ./setup.sh without --check to install)."; exit 1; fi
  if [[ "$ASSUME_YES" != 1 ]]; then
    printf 'Install the missing packages with %s now? [Y/n] ' "${PM:-your package manager}"
    read -r answer || answer="n"
    [[ "$answer" =~ ^[Nn] ]] && { red "Cancelled. Install PHP 8.1+ with pdo_sqlite manually, then re-run."; exit 1; }
  fi
  install_packages
  echo "Re-checking…"
  check_all
  [[ "$MISSING" == 1 ]] && { red "Some dependencies are still missing (see above)."; exit 1; }
fi
[[ "$CHECK_ONLY" == 1 ]] && { green "All required dependencies are installed."; exit 0; }

# --- 2. project setup -------------------------------------------------------------
echo
echo "Preparing the project"
chmod +x start.sh bin/console 2>/dev/null || true
php bin/console init
php bin/console migrate
if [[ "$SEED" == 1 ]]; then
  php bin/console seed || echo "(Demo data not loaded — see message above.)"
fi

# --- 3. verify ----------------------------------------------------------------------
echo
echo "Running the test suite (about 30 seconds)…"
if php tests/run.php >/tmp/buildtrack-setup-tests.log 2>&1; then
  ok "$(tail -1 /tmp/buildtrack-setup-tests.log)"
else
  red "Some tests failed — details: /tmp/buildtrack-setup-tests.log"
fi

echo
green "Setup complete."
echo "  Start the app:            ./start.sh          (this computer only)"
echo "  Start for phones on Wi-Fi: ./start.sh --lan"
echo "  Guide:                    run.md"

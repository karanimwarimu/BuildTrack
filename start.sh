#!/usr/bin/env bash
# BuildTrack one-command launcher.
#   ./start.sh                 start in the foreground (Ctrl+C to stop)
#   ./start.sh --seed          also load demo data on an empty database
#   ./start.sh --open          open the browser automatically
#   ./start.sh --port 9000     use another port (next free port is chosen if busy)
#   ./start.sh --lan           listen on all interfaces so phones/tablets on your network can connect
#   ./start.sh --daemon        run in the background (logs: data/logs/server.log)
#   ./start.sh --stop | --status
set -euo pipefail
cd "$(dirname "$0")"

PHP="${PHP:-php}"
PID_FILE="data/server.pid"
SEED=0; OPEN=0; DAEMON=0; PORT_ARG=""; HOST_ARG=""

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }

# The built-in server forks PHP_CLI_SERVER_WORKERS children; stop the whole process group so none are orphaned.
stop_server() {
  local pid="$1"
  kill -TERM -- "-$pid" 2>/dev/null || { pkill -TERM -P "$pid" 2>/dev/null || true; kill -TERM "$pid" 2>/dev/null || true; }
}

running_pid() {
  [[ -f "$PID_FILE" ]] || return 1
  local pid; pid="$(cat "$PID_FILE")"
  kill -0 "$pid" 2>/dev/null && echo "$pid"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --seed) SEED=1 ;;
    --open) OPEN=1 ;;
    --daemon|-d) DAEMON=1 ;;
    --port) PORT_ARG="${2:?--port needs a number}"; shift ;;
    --host) HOST_ARG="${2:?--host needs an address}"; shift ;;
    --lan) HOST_ARG="0.0.0.0" ;;
    --stop)
      if pid="$(running_pid)"; then stop_server "$pid"; rm -f "$PID_FILE"; green "Stopped BuildTrack (pid $pid)."; else echo "BuildTrack is not running in the background."; rm -f "$PID_FILE"; fi
      exit 0 ;;
    --status)
      if pid="$(running_pid)"; then green "Running (pid $pid). $(cat data/server.url 2>/dev/null || true)"; else echo "Not running in the background."; fi
      exit 0 ;;
    -h|--help) sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) red "Unknown option: $1"; exit 1 ;;
  esac
  shift
done

# --- prerequisites -----------------------------------------------------------
if ! command -v "$PHP" >/dev/null 2>&1; then
  red "PHP 8.1+ is required but was not found."
  echo "  Ubuntu/Debian:  sudo apt install php-cli php-sqlite3"
  echo "  Fedora:         sudo dnf install php-cli php-pdo"
  echo "  macOS:          brew install php"
  echo "  Windows:        install XAMPP or PHP from windows.php.net, then run start.bat"
  exit 1
fi
if ! "$PHP" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then
  red "PHP $("$PHP" -r 'echo PHP_VERSION;') found, but BuildTrack needs PHP 8.1 or newer."; exit 1
fi
if ! "$PHP" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'; then
  red "The PHP SQLite extension (pdo_sqlite) is missing."
  echo "  Ubuntu/Debian: sudo apt install php-sqlite3    Fedora: sudo dnf install php-pdo"; exit 1
fi
if pid="$(running_pid)"; then
  red "BuildTrack is already running in the background (pid $pid): $(cat data/server.url 2>/dev/null || true)"
  echo "Stop it with ./start.sh --stop"; exit 1
fi

# --- config + database ---------------------------------------------------------
"$PHP" bin/console init
[[ -n "$PORT_ARG" ]] && export APP_PORT="$PORT_ARG"
[[ -n "$HOST_ARG" ]] && export APP_HOST="$HOST_ARG"
"$PHP" bin/console migrate
if [[ "$SEED" == 1 ]]; then
  "$PHP" bin/console seed || echo "(Demo data not loaded — see message above.)"
fi

HOST="$("$PHP" -r 'require "src/config.php"; echo config("APP_HOST", "127.0.0.1");')"
WANT_PORT="$("$PHP" -r 'require "src/config.php"; echo config("APP_PORT", "8080");')"
PORT="$("$PHP" bin/console free-port "$WANT_PORT")"
UPLOAD_MB="$("$PHP" -r 'require "src/config.php"; echo max(1, (int)config("UPLOAD_MAX_MB", "10"));')"

URL_HOST="$HOST"; [[ "$HOST" == "0.0.0.0" || "$HOST" == "127.0.0.1" ]] && URL_HOST="localhost"
URL="http://$URL_HOST:$PORT"
echo "$URL" > data/server.url

echo
bold "  ┌──────────────────────────────────────────────────────────┐"
bold "  │  BuildTrack is starting                                  │"
bold "  └──────────────────────────────────────────────────────────┘"
green "    Open:      $URL"
[[ "$PORT" != "$WANT_PORT" ]] && echo "    (port $WANT_PORT was busy, using $PORT)"
if [[ "$HOST" == "0.0.0.0" ]]; then
  # The address of the interface that reaches the internet = your Wi-Fi/LAN address (skips Docker bridges).
  LAN_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p')"
  [[ -z "$LAN_IP" ]] && LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
  [[ -n "$LAN_IP" ]] && echo "    On your network (phones, tablets): http://$LAN_IP:$PORT"
elif [[ "$HOST" == "127.0.0.1" || "$HOST" == "localhost" ]]; then
  echo "    Phones:    NOT reachable (local-only mode). Use ./start.sh --lan or set APP_HOST=0.0.0.0 in .env"
fi
echo "    Admin:     $URL/admin"
echo "    Database:  $("$PHP" -r 'require "src/bootstrap.php"; echo db_path();')"
echo "    Stop:      $([[ "$DAEMON" == 1 ]] && echo './start.sh --stop' || echo 'press Ctrl+C')"
echo

if [[ "$OPEN" == 1 ]]; then
  ( sleep 1; (xdg-open "$URL" || open "$URL") >/dev/null 2>&1 || true ) &
fi

# PHP_CLI_SERVER_WORKERS lets the built-in server handle several requests at once (Linux/macOS).
SERVER=(env PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" "$PHP"
  -d upload_max_filesize="$((UPLOAD_MB + 1))M" -d post_max_size="$((UPLOAD_MB + 2))M"
  -d display_errors=0 -d log_errors=1 -d expose_php=0
  -S "$HOST:$PORT" -t public bin/router.php)

if [[ "$DAEMON" == 1 ]]; then
  mkdir -p data/logs
  if command -v setsid >/dev/null 2>&1; then
    setsid nohup "${SERVER[@]}" >> data/logs/server.log 2>&1 < /dev/null &   # own process group
  else
    nohup "${SERVER[@]}" >> data/logs/server.log 2>&1 < /dev/null &
  fi
  echo $! > "$PID_FILE"
  sleep 0.5
  if running_pid >/dev/null; then green "Running in the background (pid $(cat "$PID_FILE")). Logs: data/logs/server.log"; else red "Server failed to start; see data/logs/server.log"; exit 1; fi
else
  exec "${SERVER[@]}"
fi

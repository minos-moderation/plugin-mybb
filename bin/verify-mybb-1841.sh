#!/usr/bin/env bash
# The plugin on a REAL MyBB 1.8.41 forum (minos-moderation/minos#45, #47).
#
# Installs MyBB from a checkout of its `mybb_1841` tag with MyBB's own installer, on MySQL
# (`mysqli`) or PostgreSQL (`pgsql`), copies the built plugin into it (bin/build-zip.sh
# --stage-only, the distributable's tree), serves the forum and the mock gateway of
# minos-moderation/client-php with PHP's built-in server, and runs tests/Live through the
# forum's own pages. The answers land in $MINOS_LIVE_OUT (live-<engine>.json,
# reader-view.json): the fixtures in tests/fixtures/mybb-1.8.41/ are such files.
#
# Usage: bin/verify-mybb-1841.sh <mysqli|pgsql>
# Environment:
#   MYBB_DIR            a checkout of github.com/mybb/mybb at the tag mybb_1841 (required)
#   MINOS_LIVE_DB_HOST, MINOS_LIVE_DB_NAME, MINOS_LIVE_DB_USER, MINOS_LIVE_DB_PASS
#                       an EMPTY database the installer may fill (required)
#   MINOS_LIVE_OUT      where the answers go (default: build/live/<engine>/answers)
# Test data only: the board, its users, posts, key and secrets are made up for the run;
# the gateway is the mock, never a real one. Needs `composer install` (the mock and PHPUnit).
set -euo pipefail

engine="${1:-}"
case "$engine" in
  mysqli|pgsql) ;;
  *) echo "usage: $0 <mysqli|pgsql>" >&2; exit 2 ;;
esac
root="$(cd "$(dirname "$0")/.." && pwd)"
mybb="${MYBB_DIR:?MYBB_DIR: a checkout of mybb/mybb at mybb_1841}"
for name in MINOS_LIVE_DB_HOST MINOS_LIVE_DB_NAME MINOS_LIVE_DB_USER MINOS_LIVE_DB_PASS; do
  if [ -z "${!name:-}" ]; then echo "$name is not set" >&2; exit 2; fi
done
# shellcheck disable=SC2016 # a literal PHP line
if ! grep -q 'public $version = "1.8.41";' "$mybb/inc/class_core.php"; then
  echo "$mybb is not MyBB 1.8.41" >&2
  exit 1
fi
commit="${MYBB_COMMIT:-$(git -C "$mybb" rev-parse HEAD 2>/dev/null || true)}"

work="$root/build/live/$engine"
rm -rf "$work"
mkdir -p "$work/mock"
out="${MINOS_LIVE_OUT:-$work/answers}"

# The forum: MyBB's tree as its release ships it, with the plugin's distributable in it,
# and the two test-only files (the helper and the hook watcher) — never part of the zip.
mkdir -p "$work/forum"
(cd "$mybb" && tar --exclude=.git -cf - .) | tar -xf - -C "$work/forum"
mv "$work/forum/inc/config.default.php" "$work/forum/inc/config.php"
stage="$(MINOS_VENDOR_DIR="$root/vendor" MINOS_BUILD_DIR="$work/build" bash "$root/bin/build-zip.sh" --stage-only | tail -n 1)"
cp -R "$stage/Upload/." "$work/forum/"
cp "$root/tests/Live/fixtures/minos-live-cli.php" "$work/forum/minos-live-cli.php"
cp "$root/tests/Live/fixtures/minoslive.php" "$work/forum/inc/plugins/minoslive.php"

free_port() {
  # shellcheck disable=SC2016 # PHP code
  php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'
}
forum_port="$(free_port)"
mock_port="$(free_port)"
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || true; done
  wait 2>/dev/null || true
}
trap cleanup EXIT

export MINOS_LIVE_URL="http://127.0.0.1:$forum_port"
export MINOS_LIVE_ROOT="$work/forum"
export MINOS_LIVE_ENGINE="$engine"
export MINOS_LIVE_MOCK_URL="http://127.0.0.1:$mock_port"
MINOS_LIVE_ADMIN_PASS="atrapa-$(php -r 'echo bin2hex(random_bytes(8));')"
export MINOS_LIVE_ADMIN_PASS
export MINOS_LIVE_MYBB_COMMIT="$commit"
export MINOS_LIVE_OUT="$out"
export MINOS_MOCK_WEBHOOK_URL="$MINOS_LIVE_URL/minos-webhook.php"
export MINOS_MOCK_DATA_DIR="$work/mock"
export MINOS_MOCK_DELAY_S=0

# MyBB rewrites inc/settings.php on every settings change; OPcache's revalidation delay
# (2 s by default) would serve the old copy to the next request.
php -d opcache.enable=0 -S "127.0.0.1:$forum_port" -t "$work/forum" >"$work/forum-server.log" 2>&1 &
pids+=($!)
php -S "127.0.0.1:$mock_port" -t "$root/vendor/minos-moderation/client-php/mock-gateway/public" >"$work/mock-server.log" 2>&1 &
pids+=($!)
for port in "$forum_port" "$mock_port"; do
  for _ in $(seq 1 50); do
    # shellcheck disable=SC2016 # PHP code
    if php -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1]) ? 0 : 1);' "$port"; then break; fi
    sleep 0.1
  done
done

php "$root/tests/Live/install-mybb.php"
"$root/vendor/bin/phpunit" -c "$root/tests/Live/phpunit.xml" "${@:2}"
echo "answers: $out"

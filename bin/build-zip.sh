#!/usr/bin/env bash
# Builds the distributable: build/minos-mybb-<version>.zip holding
#   Upload/   — the files to copy into the forum's root, the client library bundled under
#               inc/plugins/minos/vendor/ (MyBB has no Composer);
#   README.md, LICENSE.
#
# Usage: bin/build-zip.sh [--stage-only]
#   --stage-only       stop after build/stage/ (no zip; the tests use it)
# Environment:
#   MINOS_VENDOR_DIR   a vendor/ that already holds minos-moderation/client-php (the tests
#                      pass the repository's); without it, `composer install --no-dev` runs
#                      from composer.lock in a temporary directory.
#   MINOS_BUILD_DIR    where to build (default: build/).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
build="${MINOS_BUILD_DIR:-$root/build}"
stage="$build/stage"
upload="$stage/Upload"
version="$(php -r 'require $argv[1]; echo Minos\MyBB\Installer::VERSION;' "$root/inc/plugins/minos/src/Installer.php")"

vendor="${MINOS_VENDOR_DIR:-}"
if [ -z "$vendor" ]; then
  work="$(mktemp -d)"
  trap 'rm -rf "$work"' EXIT
  cp "$root/composer.json" "$root/composer.lock" "$work/"
  composer install --working-dir="$work" --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader >&2
  vendor="$work/vendor"
fi
client="$vendor/minos-moderation/client-php"
if [ ! -f "$client/src/Signature.php" ]; then
  echo "build-zip: $client does not hold the client library" >&2
  exit 1
fi

rm -rf "$stage"
mkdir -p "$upload/inc/plugins/minos/vendor/minos-moderation/client-php" "$upload/inc/tasks" \
  "$upload/inc/languages/polish" "$upload/inc/languages/english"

cp "$root/minos-webhook.php" "$upload/"
cp "$root/inc/plugins/minos.php" "$upload/inc/plugins/"
cp "$root/inc/plugins/minos/autoload.php" "$upload/inc/plugins/minos/"
cp -R "$root/inc/plugins/minos/src" "$upload/inc/plugins/minos/"
# Only the library, its licence and its manifest: never the mock gateway or the tests,
# which must not become reachable under a forum's web root.
cp -R "$client/src" "$client/LICENSE" "$client/composer.json" "$upload/inc/plugins/minos/vendor/minos-moderation/client-php/"
cp "$root/inc/tasks/minos.php" "$upload/inc/tasks/"
cp "$root/inc/languages/polish/minos.lang.php" "$upload/inc/languages/polish/"
cp "$root/inc/languages/english/minos.lang.php" "$upload/inc/languages/english/"
cp "$root/README.md" "$root/LICENSE" "$stage/"

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "$upload" -name '*.php' -print0)

if [ "${1:-}" = "--stage-only" ]; then
  echo "$stage"
  exit 0
fi

zip="$build/minos-mybb-$version.zip"
rm -f "$zip"
(cd "$stage" && zip -qr "$zip" .)
echo "$zip"

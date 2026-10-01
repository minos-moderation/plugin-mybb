#!/usr/bin/env bash
# Proves a release archive's properties before .github/workflows/release.yml publishes it:
# no development file inside, the licence inside, and the version the plugin declares equal
# to the tag. Every failed property is reported before the script exits 1.
#
#   .github/scripts/check-release-archive.sh <archive.zip> <tag>
#
# Needs unzip and php. By hand: bin/build-zip.sh, then this script on
# build/minos-mybb-<version>.zip.
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "usage: $0 <archive.zip> <tag>" >&2
  exit 2
fi
archive="$1"
tag="$2"

# The licence's entry, and the class whose VERSION minos_info() reports to MyBB's plugin
# list (Installer::info()).
licence='LICENSE'
version_file='Upload/inc/plugins/minos/src/Installer.php'

failed=0
fail() {
  echo "check-release-archive: $*" >&2
  failed=1
}

if ! [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.+-]+)?$ ]]; then
  echo "check-release-archive: the tag '$tag' is not v<major>.<minor>.<patch>[-<suffix>]" >&2
  exit 1
fi
expected="${tag#v}"

listing="$(unzip -Z1 "$archive")"
if [ -z "$listing" ]; then
  echo "check-release-archive: $archive lists no entry" >&2
  exit 1
fi
echo "Entries of $archive:"
printf '%s\n' "$listing"

# Development files, matched on whole path segments of every entry, case-insensitively.
absent() {
  local hits
  hits="$(grep -E -i -- "$2" <<<"$listing" || true)"
  if [ -n "$hits" ]; then
    fail "$1 in the archive:"$'\n'"$hits"
  fi
}
absent 'composer.lock' '(^|/)composer\.lock$'
absent 'a tests/ directory' '(^|/)tests(/|$)'
absent 'a phpunit file' '(^|/)\.?phpunit[^/]*(/|$)'
absent 'the mock gateway' '(^|/)mock[-_]?gateway(/|$)'
absent 'a .git* entry' '(^|/)\.git[^/]*(/|$)'
absent 'a CLAUDE.md' '(^|/)CLAUDE\.md$'
absent 'a .claude/ directory' '(^|/)\.claude(/|$)'
absent 'a .github/ directory' '(^|/)\.github(/|$)'

if ! grep -F -x -q -- "$licence" <<<"$listing"; then
  fail "the licence $licence is not in the archive"
fi

# The version as MyBB shows it: minos_info()'s "version" is Installer::VERSION, read the
# way bin/build-zip.sh reads it, from the archive's copy of the class.
version=''
if grep -F -x -q -- "$version_file" <<<"$listing"; then
  class_file="$(mktemp)"
  unzip -p "$archive" "$version_file" > "$class_file"
  version="$(php -r 'require $argv[1]; echo Minos\MyBB\Installer::VERSION;' "$class_file" || true)"
  rm -f "$class_file"
fi
if [ -z "$version" ]; then
  fail "no Installer::VERSION in $version_file of the archive (the tag is $tag)"
elif [ "$version" != "$expected" ]; then
  fail "the plugin declares version $version (Installer::VERSION, $version_file) but the tag is $tag (expects $expected)"
fi

if [ "$failed" -ne 0 ]; then
  exit 1
fi
echo "check-release-archive: $archive holds version $version, the licence and no development file"

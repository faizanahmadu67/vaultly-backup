#!/usr/bin/env bash
#
# Builds the two release zips from the vaultly-backup/ plugin folder:
#
#   dist/vaultly-backup-wporg-<version>.zip   WordPress.org upload ("Add your plugin"): NO GitHub updater
#   dist/vaultly-backup.zip                   GitHub release asset (name must stay exactly this: the updater looks for it)
#
# Both zips contain a single top-level folder "vaultly-backup/" and only the files the plugin needs:
# no tests, no dev folders, no dot-files, no zips, no GitHub-only README.md, no CLAUDE.md.
#
# Usage:  ./build.sh           run the checks, then build both zips
#         ./build.sh --check   run the checks only
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$ROOT/vaultly-backup"
DIST="$ROOT/dist"
MAIN="$SRC/vaultly-backup.php"
UPDATER="includes/class-vaultly-updater.php"

fail() { echo "ERROR: $*" >&2; exit 1; }
ok()   { echo "  ok  $*"; }

command -v php   >/dev/null || fail "php is required (for php -l)"
command -v zip   >/dev/null || fail "zip is required"
command -v rsync >/dev/null || fail "rsync is required"
[ -f "$MAIN" ] || fail "plugin file not found: $MAIN"

echo "== Checks"

# 1. The version must be the same everywhere (a mismatch is an easy review/update mistake)
V_HEADER="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$MAIN" | head -1 | tr -d '[:space:]')"
V_CONST="$(sed -n "s/^define( 'VAULTLY_VERSION', '\([^']*\)' );.*/\1/p" "$MAIN" | head -1)"
V_STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$SRC/readme.txt" | head -1 | tr -d '[:space:]')"
[ -n "$V_HEADER" ] || fail "no 'Version:' header in vaultly-backup.php"
[ "$V_HEADER" = "$V_CONST" ]  || fail "version mismatch: header=$V_HEADER, VAULTLY_VERSION=$V_CONST"
[ "$V_HEADER" = "$V_STABLE" ] || fail "version mismatch: header=$V_HEADER, readme.txt Stable tag=$V_STABLE"
grep -q "^## $V_HEADER - " "$SRC/CHANGELOG.md" || fail "CHANGELOG.md has no '## $V_HEADER - date' entry"
grep -q "^= $V_HEADER =" "$SRC/readme.txt"     || fail "readme.txt changelog has no '= $V_HEADER =' entry"
VERSION="$V_HEADER"
ok "version $VERSION (header, constant, Stable tag, both changelogs)"

# 2. Syntax
while IFS= read -r f; do
	php -l "$f" >/dev/null || fail "PHP syntax error in $f"
done < <(find "$SRC" -name '*.php' -not -path '*/.*')
ok "php -l on every PHP file"

# 3. Things the WordPress.org review has rejected before
if grep -rnE '\b(set_time_limit|ignore_user_abort|ini_set|ini_alter)[[:space:]]*\(' "$SRC" --include='*.php'; then
	fail "PHP limits must not be changed (see above)"
fi
ok "no set_time_limit / ignore_user_abort / ini_set"

MISSING="$(grep -L "defined( 'ABSPATH' )" "$SRC"/vaultly-backup.php "$SRC"/includes/*.php || true)"
[ -z "$MISSING" ] || fail "missing the ABSPATH guard: $MISSING"
ok "ABSPATH guard in every PHP file"

if grep -rnE '\b(var_dump|print_r|dd|dump)[[:space:]]*\(' "$SRC" --include='*.php'; then
	fail "debug output left in the code (see above)"
fi
ok "no var_dump / print_r"

# 4. The backup folder's index.php must stay empty (no PHP code written to wp-content)
grep -n "'index.php'  => ''" "$SRC/includes/class-vaultly-job.php" >/dev/null || fail "index.php written to the backup folder is no longer empty"
ok "backup-folder index.php is empty"

[ "${1:-}" = "--check" ] && { echo "Checks passed."; exit 0; }

echo "== Build"
rm -rf "$DIST"
mkdir -p "$DIST"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

EXCLUDES=(
	--exclude='.*'            # dot-files and dot-folders (.git, .idea, .DS_Store, ...)
	--exclude='*.zip' --exclude='*.log' --exclude='*.bak' --exclude='*.orig' --exclude='*.swp' --exclude='*~'
	--exclude='tests/' --exclude='test/' --exclude='dev-only/' --exclude='node_modules/' --exclude='dist/'
	--exclude='/README.md'    # GitHub readme; WordPress uses readme.txt
	--exclude='CLAUDE.md' --exclude='Thumbs.db'
)

build() { # build <zip path> <extra rsync args...>
	local zipfile="$1"; shift
	local stage="$TMP/$(basename "$zipfile" .zip)"
	mkdir -p "$stage"
	rsync -a "${EXCLUDES[@]}" "$@" "$SRC/" "$stage/vaultly-backup/"
	( cd "$stage" && zip -qrX "$zipfile" vaultly-backup )
}

WPORG="$DIST/vaultly-backup-wporg-$VERSION.zip"
GITHUB="$DIST/vaultly-backup.zip"
build "$WPORG"  --exclude="/$UPDATER"
build "$GITHUB"

echo "== Verify the zips"
for z in "$WPORG" "$GITHUB"; do
	LIST="$(unzip -Z1 "$z")"
	echo "$LIST" | grep -q '^vaultly-backup/vaultly-backup.php$' || fail "$(basename "$z"): plugin file not at vaultly-backup/vaultly-backup.php"
	echo "$LIST" | grep -vq '^vaultly-backup/' && fail "$(basename "$z"): has files outside the vaultly-backup/ folder"
	if echo "$LIST" | grep -E '(^|/)\.|(^|/)(tests?|dev-only|node_modules)/|\.zip$|README\.md$|CLAUDE\.md$'; then
		fail "$(basename "$z"): contains development files (listed above)"
	fi
done
unzip -Z1 "$WPORG"  | grep -q "$UPDATER" && fail "the WordPress.org zip contains the GitHub updater"
unzip -Z1 "$GITHUB" | grep -q "$UPDATER" || fail "the GitHub zip is missing the GitHub updater"
ok "WordPress.org zip: no updater, no dev files"
ok "GitHub zip: has the updater, no dev files"

echo
echo "Built (version $VERSION):"
for z in "$WPORG" "$GITHUB"; do
	printf '  %s  (%s, %s files)\n' "${z#$ROOT/}" "$(du -h "$z" | cut -f1)" "$(unzip -Z1 "$z" | grep -vc '/$')"
done
echo
echo "WordPress.org: upload the -wporg zip on 'Add your plugin'.  GitHub: attach vaultly-backup.zip to the release tagged v$VERSION."

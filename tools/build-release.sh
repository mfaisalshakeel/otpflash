#!/usr/bin/env bash
#
# Build the zip you upload to cPanel.
#
#   bash tools/build-release.sh
#
# Produces build/smm-panel-YYYY-MM-DD.zip containing only what the live site
# needs. The mockups and these tools stay in the repository but are left out of
# the zip - they are development material, and tools/mock-provider.php must
# never reach a real server.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/build"
NAME="smm-panel-$(date +%Y-%m-%d)"
STAGE="$OUT/$NAME"

rm -rf "$STAGE" "$OUT/$NAME.zip"
mkdir -p "$STAGE"

echo "Staging..."
# Ship these, and nothing else.
for item in index.php cron.php .htaccess README.md \
            app assets config install themes; do
  cp -R "$ROOT/$item" "$STAGE/"
done

# Empty writable folders, with their deny rules and nothing else.
mkdir -p "$STAGE/storage/logs" "$STAGE/storage/cache" "$STAGE/uploads/branding"
cp "$ROOT/storage/.htaccess" "$STAGE/storage/.htaccess"
cp "$ROOT/uploads/.htaccess" "$STAGE/uploads/.htaccess"

echo "Removing anything that must not ship..."
rm -f  "$STAGE/config/config.php"        # the live site writes its own
rm -f  "$STAGE/install/install.lock"     # the installer must be able to run
find "$STAGE" -name '.DS_Store' -delete
find "$STAGE" -name '*.log'     -delete

# A last look, in case the list above ever drifts.
for forbidden in mockups tools build .git config/config.php install/install.lock; do
  if [ -e "$STAGE/$forbidden" ]; then
    echo "ERROR: $forbidden ended up in the release" >&2
    exit 1
  fi
done

echo "Zipping..."
( cd "$OUT" && zip -rq "$NAME.zip" "$NAME" )
rm -rf "$STAGE"

echo
echo "Built $OUT/$NAME.zip  ($(du -h "$OUT/$NAME.zip" | cut -f1))"
echo
echo "Upload it to public_html, unzip, make config/, storage/logs/ and"
echo "uploads/branding/ writable, then open /install"

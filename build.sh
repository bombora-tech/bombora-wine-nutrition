#!/usr/bin/env bash
# Sestavení distribučního ZIPu + SHA-256 pro GitHub Release (Bombora Manager).
# Použití: ./build.sh  → dist/wine-nutrition-manager.zip + .sha256
set -euo pipefail
cd "$(dirname "$0")"
SLUG="wine-nutrition-manager"
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' ${SLUG}.php | sed -E 's/.*Version:\s*//; s/\s+$//')"
CONST="$(grep -m1 "define( 'WNM_VERSION'" ${SLUG}.php | sed -E "s/.*'WNM_VERSION', '([^']+)'.*/\1/")"
if [[ "$VERSION" != "$CONST" ]]; then echo "Chyba: Version ($VERSION) ≠ WNM_VERSION ($CONST)" >&2; exit 1; fi
if ! grep -q "^## \[$VERSION\]" CHANGELOG.md; then echo "Chyba: CHANGELOG.md neobsahuje [$VERSION]" >&2; exit 1; fi
find . -name '*.php' -not -path './dist/*' -print0 | xargs -0 -n1 php -l >/dev/null
rm -rf dist build
mkdir -p "build/${SLUG}" dist
tar --exclude-from=.distignore --exclude=./build --exclude=./dist -cf - . | tar -xf - -C "build/${SLUG}"
( cd build && zip -qr9X "../dist/${SLUG}.zip" "${SLUG}" )
( cd dist && sha256sum "${SLUG}.zip" > "${SLUG}.zip.sha256" )
rm -rf build
echo "Hotovo: dist/${SLUG}.zip (v${VERSION})"; cat "dist/${SLUG}.zip.sha256"

#!/bin/sh
# Copies the PHP Supertext SDK from its GitHub repository into supertext/lib/supertext-sdk, with its
# namespace changed from Supertext\Sdk to Supertext\PrestaShop\Sdk. The module ships
# this copy, so sites install nothing besides the module, and another package that requires
# supertext/supertext-sdk itself can never clash with it.
#
#   scripts/vendor-sdk.sh [commit-or-branch]   (default: main)
#   SDK_DIR=/path/to/clone scripts/vendor-sdk.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${SDK_DIR:-}" ]; then
    SRC="$SDK_DIR"
else
    git clone --quiet https://github.com/Supertext/Supertext-API-SDK-Guide "$TMP/sdk"
    git -C "$TMP/sdk" checkout --quiet "$REF"
    SRC="$TMP/sdk"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"

DEST="$ROOT/supertext/lib/supertext-sdk"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/sdk/php/src" "$DEST/src"
cp "$SRC/LICENSE" "$DEST/LICENSE"
find "$DEST/src" -name '*.php' -exec sed -i 's/Supertext\\Sdk\\/Supertext\\PrestaShop\\Sdk\\/g; s/Supertext\\Sdk;/Supertext\\PrestaShop\\Sdk;/g' {} +
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
cat > "$DEST/README.md" <<EOF
# Supertext SDK (bundled copy)

PHP part of the [Supertext SDK](https://github.com/Supertext/Supertext-API-SDK-Guide),
commit \`$COMMIT\`, namespace \`Supertext\\PrestaShop\\Sdk\` instead of \`Supertext\\Sdk\`.

Don't edit these files: fix the SDK in its own repository, then run \`scripts/vendor-sdk.sh\`.
EOF
if grep -rq 'Supertext\\Sdk' "$DEST/src"; then
    echo "vendor-sdk: unprefixed Supertext\\Sdk reference left in $DEST/src" >&2
    exit 1
fi
echo "supertext/lib/supertext-sdk: Supertext SDK (PHP) from commit $COMMIT"

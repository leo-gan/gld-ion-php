#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
out="$(mktemp -d)"
trap 'rm -rf "$out"' EXIT
php bin/gld-iongen-php --schema testdata/schema/message.json --out "$out" >/dev/null
php bin/gld-iongen-php --schema testdata/schema/point.ion --out "$out" >/dev/null
diff -u tests/generated/Message.php "$out/Message.php"
diff -u tests/generated/Point.php "$out/Point.php"

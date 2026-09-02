#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
android_dir="$(cd "$script_dir/.." && pwd)"
config_root="$(mktemp -d "${TMPDIR:-/tmp}/jifeng-release-tools.XXXXXX")"
local_properties="$android_dir/local.properties"

cleanup() {
  rm -rf "$config_root"
  rm -f "$local_properties"
}
trap cleanup EXIT

export JIFENG_CONFIG_ROOT="$config_root"
export JIFENG_KEYSTORE_PROPERTIES="$config_root/keystore.properties"

cd "$android_dir"
bash scripts/create-release-keystore.sh
bash scripts/write-local-config.sh \
  "/opt/homebrew/share/android-commandlinetools" \
  "https://link.example.test/?code=abc12345"

test "$(stat -f '%Lp' "$config_root/release.jks")" = "600"
test "$(stat -f '%Lp' "$config_root/keystore.properties")" = "600"
test "$(stat -f '%Lp' "$local_properties")" = "600"

keytool -list \
  -keystore "$config_root/release.jks" \
  -storepass "$(sed -n 's/^storePassword=//p' "$config_root/keystore.properties")" \
  -storetype PKCS12 >/dev/null

grep -Fx 'DOUYIN_CLIENT_KEY=awm0sswt6y17zkhu' "$local_properties"
grep -Fx 'DOUYIN_APPROVED_SHARE_URL=https://link.example.test/?code=abc12345' "$local_properties"
if grep -qi '^client_secret=' "$local_properties"; then
  echo "client_secret must not be written to local.properties" >&2
  exit 1
fi

./gradlew --no-daemon assembleRelease
bash scripts/verify-release-apk.sh app/build/outputs/apk/release/app-release.apk

echo "release tooling test passed"

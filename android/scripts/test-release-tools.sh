#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
android_dir="$(cd "$script_dir/.." && pwd)"
config_root="$(mktemp -d "${TMPDIR:-/tmp}/jifeng-release-tools.XXXXXX")"
local_properties="$android_dir/local.properties"
had_local_properties=false
local_properties_mode=""
local_properties_backup="$config_root/original-local.properties"
local_properties_sha256=""

if [[ -e "$local_properties" ]]; then
  had_local_properties=true
  local_properties_mode="$(stat -f '%Lp' "$local_properties")"
  local_properties_sha256="$(shasum -a 256 "$local_properties" | awk '{print $1}')"
  cp -p "$local_properties" "$local_properties_backup"
fi

restore_local_properties() {
  if [[ "$had_local_properties" == true && -f "$local_properties_backup" ]]; then
    cp -p "$local_properties_backup" "$local_properties"
    chmod "$local_properties_mode" "$local_properties"
  elif [[ "$had_local_properties" == false && -e "$local_properties" ]]; then
    rm -f "$local_properties"
  fi
}

assert_original_local_properties_restored() {
  if [[ "$had_local_properties" == true ]]; then
    test -f "$local_properties"
    test "$(stat -f '%Lp' "$local_properties")" = "$local_properties_mode"
    test "$(shasum -a 256 "$local_properties" | awk '{print $1}')" = "$local_properties_sha256"
    cmp -s "$local_properties_backup" "$local_properties"
  else
    test ! -e "$local_properties"
  fi
}

cleanup() {
  restore_local_properties
  rm -rf "$config_root"
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
grep -Fx 'DOUYIN_CARD_TITLE=极风小助手' "$local_properties"
grep -Fx 'DOUYIN_CARD_DESCRIPTION=已审核链接的管理与分享工具' "$local_properties"
if grep -qi '^client_secret=' "$local_properties"; then
  echo "client_secret must not be written to local.properties" >&2
  exit 1
fi

./gradlew --no-daemon assembleRelease
release_apk="app/build/outputs/apk/release/app-release.apk"
bash scripts/verify-release-apk.sh "$release_apk"

apksigner_bin="/opt/homebrew/share/android-commandlinetools/build-tools/36.0.0/apksigner"
store_password="$(sed -n 's/^storePassword=//p' "$config_root/keystore.properties")"
sign_fixture() {
  local fixture="$1"
  zip -q -d "$fixture" 'META-INF/*.RSA' 'META-INF/*.SF' 'META-INF/MANIFEST.MF' || true
  "$apksigner_bin" sign \
    --ks "$config_root/release.jks" \
    --ks-pass "pass:$store_password" \
    --key-pass "pass:$store_password" \
    --ks-key-alias jifengassistant \
    "$fixture" >/dev/null
}
assert_verifier_rejects() {
  local fixture="$1"
  if bash scripts/verify-release-apk.sh "$fixture" >"$fixture.log" 2>&1; then
    echo "verifier unexpectedly accepted $fixture" >&2
    exit 1
  fi
  grep -F 'APK contains a signing/configuration file' "$fixture.log"
}

printf 'uppercase keystore fixture\n' >"$config_root/evil.JKS"
uppercase_keystore_apk="$config_root/uppercase-keystore.apk"
cp "$release_apk" "$uppercase_keystore_apk"
(cd "$config_root" && zip -q -j "$uppercase_keystore_apk" evil.JKS)
sign_fixture "$uppercase_keystore_apk"
assert_verifier_rejects "$uppercase_keystore_apk"

mkdir -p "$config_root/meta/META-INF"
printf 'embedded local config fixture\n' >"$config_root/meta/META-INF/local.properties"
meta_local_properties_apk="$config_root/meta-local-properties.apk"
cp "$release_apk" "$meta_local_properties_apk"
(cd "$config_root/meta" && zip -q "$meta_local_properties_apk" META-INF/local.properties)
sign_fixture "$meta_local_properties_apk"
assert_verifier_rejects "$meta_local_properties_apk"

mkdir -p "$config_root/meta-upper/META-INF"
printf 'embedded uppercase local config fixture\n' >"$config_root/meta-upper/META-INF/LOCAL.PROPERTIES"
uppercase_local_properties_apk="$config_root/meta-uppercase-local-properties.apk"
cp "$release_apk" "$uppercase_local_properties_apk"
(cd "$config_root/meta-upper" && zip -q "$uppercase_local_properties_apk" META-INF/LOCAL.PROPERTIES)
sign_fixture "$uppercase_local_properties_apk"
assert_verifier_rejects "$uppercase_local_properties_apk"

restore_local_properties
assert_original_local_properties_restored
echo "release tooling test passed"

#!/usr/bin/env bash
set -euo pipefail
umask 077

config_root="${JIFENG_CONFIG_ROOT:-${HOME}/.config/jifeng-assistant}"
keystore_file="$config_root/release.jks"
properties_file="$config_root/keystore.properties"

mkdir -p "$config_root"
chmod 700 "$config_root"

if [[ -e "$keystore_file" || -e "$properties_file" ]]; then
  echo "Refusing to overwrite existing release signing files in $config_root" >&2
  exit 1
fi

store_password=""
while ((${#store_password} < 48)); do
  store_password+="$(openssl rand -base64 96 | tr -dc 'A-Za-z0-9')"
done
store_password="${store_password:0:48}"

keytool -genkeypair \
  -keystore "$keystore_file" \
  -storetype PKCS12 \
  -storepass "$store_password" \
  -keypass "$store_password" \
  -alias jifengassistant \
  -keyalg RSA -keysize 3072 -validity 10000 \
  -dname 'CN=Jifeng Assistant, OU=Mobile, O=Hefei Jixing Network Technology Co Ltd, L=Hefei, ST=Anhui, C=CN' \
  >/dev/null

absolute_keystore_file="$(cd "$(dirname "$keystore_file")" && printf '%s/%s' "$(pwd -P)" "$(basename "$keystore_file")")"
temporary_properties="$(mktemp "$config_root/.keystore.properties.XXXXXX")"
cleanup() {
  rm -f "$temporary_properties"
}
trap cleanup EXIT

{
  printf 'storeFile=%s\n' "$absolute_keystore_file"
  printf 'storePassword=%s\n' "$store_password"
  printf 'keyAlias=jifengassistant\n'
  printf 'keyPassword=%s\n' "$store_password"
} >"$temporary_properties"
chmod 600 "$temporary_properties"

mv -n "$temporary_properties" "$properties_file"
if [[ -e "$temporary_properties" ]]; then
  echo "Refusing to overwrite existing $properties_file" >&2
  exit 1
fi

echo "Created release keystore and external signing properties in $config_root"

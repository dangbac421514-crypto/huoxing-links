#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ $# -lt 2 || $# -gt 3 ]]; then
  echo "Usage: $0 SDK_PATH APPROVED_SHARE_URL [HTTPS_THUMB_URL]" >&2
  exit 2
fi

sdk_path="$1"
approved_share_url="$2"
thumb_url="${3:-}"

if [[ "$sdk_path" != /* || "$sdk_path" == *$'\n'* || "$sdk_path" == *$'\r'* ]]; then
  echo "SDK_PATH must be an absolute path without newlines" >&2
  exit 2
fi

is_https_url() {
  [[ "$1" =~ ^https://[^[:space:]]+$ ]]
}

if ! is_https_url "$approved_share_url"; then
  echo "APPROVED_SHARE_URL must be an HTTPS URL" >&2
  exit 2
fi
if [[ -n "$thumb_url" ]] && ! is_https_url "$thumb_url"; then
  echo "HTTPS_THUMB_URL must be empty or an HTTPS URL" >&2
  exit 2
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
android_dir="$(cd "$script_dir/.." && pwd)"
local_properties="$android_dir/local.properties"
temporary_properties="$(mktemp "$android_dir/.local.properties.XXXXXX")"
cleanup() {
  rm -f "$temporary_properties"
}
trap cleanup EXIT

{
  printf 'sdk.dir=%s\n' "$sdk_path"
  printf 'DOUYIN_CLIENT_KEY=awm0sswt6y17zkhu\n'
  printf 'DOUYIN_APPROVED_SHARE_URL=%s\n' "$approved_share_url"
  printf 'DOUYIN_CARD_TITLE=添加微信\n'
  printf 'DOUYIN_CARD_DESCRIPTION=长按识别二维码，添加我为好友\n'
  printf 'DOUYIN_CARD_THUMB_URL=%s\n' "$thumb_url"
} >"$temporary_properties"
chmod 600 "$temporary_properties"
mv -f "$temporary_properties" "$local_properties"

echo "Wrote mode-600 local.properties with approved public card configuration"

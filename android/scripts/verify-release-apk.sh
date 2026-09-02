#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 APK" >&2
  exit 2
fi

apk="$1"
if [[ ! -f "$apk" ]]; then
  echo "APK does not exist: $apk" >&2
  exit 1
fi

if command -v apkanalyzer >/dev/null 2>&1; then
  apkanalyzer_bin="$(command -v apkanalyzer)"
else
  apkanalyzer_bin="/opt/homebrew/share/android-commandlinetools/cmdline-tools/latest/bin/apkanalyzer"
fi
if [[ ! -x "$apkanalyzer_bin" ]]; then
  echo "apkanalyzer is not available" >&2
  exit 1
fi

apksigner_bin="/opt/homebrew/share/android-commandlinetools/build-tools/36.0.0/apksigner"
if [[ ! -x "$apksigner_bin" ]]; then
  apksigner_bin="$(command -v apksigner || true)"
fi
if [[ -z "$apksigner_bin" || ! -x "$apksigner_bin" ]]; then
  echo "apksigner 36.0.0 is not available" >&2
  exit 1
fi

"$apkanalyzer_bin" manifest application-id "$apk" | grep -Fx 'com.jixingwangluo.jifengassistant'
"$apksigner_bin" verify --verbose --print-certs "$apk"

if unzip -Z1 "$apk" \
  | grep -E '\.(jks|keystore|properties)$' \
  | grep -v '^META-INF/'; then
  echo "APK contains a signing/configuration file" >&2
  exit 1
fi

if zipgrep -i 'client_secret' "$apk"; then
  echo "APK contains a client_secret value" >&2
  exit 1
else
  zipgrep_status=$?
  if [[ "$zipgrep_status" -ne 1 ]]; then
    echo "Unable to inspect APK for client_secret" >&2
    exit "$zipgrep_status"
  fi
fi

echo "Verified release APK: $apk"

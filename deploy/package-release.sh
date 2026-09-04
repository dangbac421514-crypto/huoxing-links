#!/usr/bin/env bash
set -Eeuo pipefail
src="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
[[ $# -eq 1 && "$1" == /* ]] || { echo 'Usage: package-release.sh NEW_ABSOLUTE_OUTPUT_DIRECTORY' >&2; exit 64; }
out="$1"
[[ ! -e "$out" && ! -L "$out" ]] || { echo 'Refusing to overwrite existing output' >&2; exit 1; }
parent="$(cd "$(dirname "$out")" && pwd -P)"
out="$parent/$(basename "$out")"
[[ "$out" != "$src" && "$out" != "$src/"* ]] || { echo 'Output must be outside the source checkout' >&2; exit 1; }
[[ -f "$src/admin/dist/index.html" && -f "$src/admin/dist/config.js" ]] || { echo 'Build admin/dist before packaging' >&2; exit 1; }
sha="$(git -C "$src" rev-parse HEAD)"
git -C "$src" diff --quiet HEAD -- . || { echo 'Commit source and built admin/dist before packaging' >&2; exit 1; }
[[ -z "$(git -C "$src" ls-files --others --exclude-standard)" ]] || { echo 'Commit untracked source and build files before packaging' >&2; exit 1; }
mkdir -m 700 "$out"
mkdir -p "$out/admin" "$out/serve"
exclude=(--exclude='.env*' --exclude='.git' --exclude='node_modules' --exclude='*.pem' --exclude='*.key' --exclude='*.p12' --exclude='*.pfx' --exclude='*.p8' --exclude='*.jks' --exclude='*.keystore' --exclude='*.log' --exclude='.DS_Store')
rsync -a "${exclude[@]}" "$src/admin/dist" "$out/admin/"
rsync -a "${exclude[@]}" --exclude='/vendor/' --exclude='/storage/' --exclude='/tests/' --exclude='/bin/test-env' --exclude='/bootstrap/cache/*' --exclude='/public/storage' --exclude='/public/web' --exclude='.phpunit*' "$src/serve/" "$out/serve/"
for item in README.md LICENSE NOTICE deploy.sh; do
  [[ ! -f "$src/$item" ]] || cp "$src/$item" "$out/$item"
done
for directory in docs deploy; do
  [[ ! -d "$src/$directory" ]] || rsync -a "${exclude[@]}" --exclude=tests "$src/$directory" "$out/"
done
mkdir -p "$out/serve/public" "$out/serve/storage/app/public" "$out/serve/storage/app/private/feedback" "$out/serve/storage/framework/cache/data" "$out/serve/storage/framework/sessions" "$out/serve/storage/framework/views" "$out/serve/storage/logs" "$out/serve/bootstrap/cache"
ln -s ../../admin/dist "$out/serve/public/web"
printf 'source_sha=%s\nvendor_included=false\n' "$sha" > "$out/RELEASE"
(cd "$out" && find . -type f ! -name MANIFEST -print0 | LC_ALL=C sort -z | xargs -0 shasum -a 256 > MANIFEST)
echo "PACKAGE=$out SOURCE_SHA=$sha"
echo 'Install locked Composer production dependencies on the target; follow docs/ready-to-use-deployment.md.'

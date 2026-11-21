#!/bin/bash
# Copies Windrose Common (the shared library, source in windrose-helm/common/) into another Windrose plugin.
# Its text domain becomes the target plugin's; bootstrap.php is copied unchanged.
# Usage: bin/sync-common.sh ../windrose-anchors
set -euo pipefail
source_dir="$(cd "$(dirname "$0")/.." && pwd)/common"
target="$(cd "$1" && pwd)"
slug=$(basename "$target")
case "$slug" in windrose-helm) echo "windrose-helm holds the source." >&2; exit 1 ;; windrose-*) ;; *) echo "Not a Windrose plugin: $slug" >&2; exit 1 ;; esac
rm -rf "$target/common"
cp -r "$source_dir" "$target/common"
grep -rl "'windrose-helm'" "$target/common" | xargs -r sed -i "s/'windrose-helm'/'$slug'/g"
echo "Windrose Common $(grep -oE "\] = '[0-9.]+'" "$source_dir/bootstrap.php" | grep -oE '[0-9.]+') copied to $slug/common (text domain $slug)."

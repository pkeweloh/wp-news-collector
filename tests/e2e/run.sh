#!/usr/bin/env sh
# Same as run.ps1 for a POSIX shell. Usage: tests/e2e/run.sh [name-fragment]
set -u
container=wp-news-collector-dev-wpcli-1
plugin_dir=/var/www/html/wp-content/plugins/wp-news-collector
dir=$(cd "$(dirname "$0")" && pwd)
only=${1:-}
failed=""
count=0

for t in "$dir"/test-*.php; do
	name=$(basename "$t")
	case "$name" in *"$only"*) ;; *) continue ;; esac
	count=$((count + 1))
	echo "== $name"
	# MSYS_NO_PATHCONV keeps Git Bash from rewriting the container path.
	MSYS_NO_PATHCONV=1 docker exec "$container" wp eval-file "$plugin_dir/tests/e2e/$name" || failed="$failed $name"
	echo
done

if [ "$count" -eq 0 ]; then
	echo "No e2e tests matched." >&2
	exit 2
fi
if [ -n "$failed" ]; then
	echo "FAILED:$failed"
	exit 1
fi
echo "All e2e suites passed ($count)."

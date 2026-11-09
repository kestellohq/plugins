#!/bin/sh
# Every check, for every package or the ones named. CI runs exactly this.
#
#   sh tools/check.sh                    all packages
#   sh tools/check.sh meilisearch        one
#   sh tools/check.sh --fix              format the code instead of failing on style
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"

if [ ! -f kestello/bin/cms ]; then
    echo "error: there is no Kestello checkout in kestello/. Run: sh tools/setup.sh" >&2
    exit 1
fi

fix=no
packages=""
for arg in "$@"; do
    case "$arg" in
        --fix) fix=yes ;;
        *) packages="$packages ${arg%/}" ;;
    esac
done
if [ -z "$packages" ]; then
    for manifest in */plugin.json */theme.json; do
        [ -f "$manifest" ] && packages="$packages $(dirname "$manifest")"
    done
fi
[ -n "$packages" ] || { echo "error: no plugin.json or theme.json found." >&2; exit 1; }

php="php -d memory_limit=1G"

echo "== PHP syntax"
find $packages -name '*.php' -not -path '*/node_modules/*' -print0 | xargs -0 -n1 php -l > /dev/null

echo "== Code style (PER-CS 2.0)"
if [ "$fix" = yes ]; then
    $php kestello/vendor/bin/php-cs-fixer fix --using-cache=no --show-progress=none
else
    $php kestello/vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --show-progress=none
fi

echo "== PHPStan"
$php -d memory_limit=2G kestello/vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress

for package in $packages; do
    flag=""
    [ -f "$package/theme.json" ] && flag="--theme"
    echo "== $package: tests"
    if [ -d "$package/tests" ]; then $php kestello/bin/cms test "$root/$package/tests"; else echo "   (no tests)"; fi
    echo "== $package: plugin:check"
    $php kestello/bin/cms plugin:check "$root/$package" $flag
    slug=$(basename "$package")
    if [ -f "$package/resources/lang/$slug.pot" ]; then
        echo "== $package: the translation template is current"
        $php kestello/bin/cms i18n:extract --path="$root/$package" --domain="$slug" --check
    fi
done

echo "All checks passed."

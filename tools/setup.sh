#!/bin/sh
# Get the Kestello checkout that tests, checks and builds run against, into ./kestello,
# and the PHP tools pinned in its composer.lock (PHP CS Fixer, PHPStan): its only Composer
# packages, for development; Kestello itself installs nothing with Composer.
#
#   sh tools/setup.sh                 a sibling ../cms folder if there is one, else a clone
#   KESTELLO_REF=main sh tools/setup.sh   clone that branch, tag or commit instead of develop
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"

if [ -e kestello ]; then
    echo "kestello/ is there already ($(readlink kestello 2>/dev/null || echo 'a checkout'))."
elif [ -z "${KESTELLO_REF:-}" ] && [ -f ../cms/bin/cms ]; then
    ln -s ../cms kestello
    echo "kestello/ links to ../cms."
else
    git clone --quiet --depth 1 --branch "${KESTELLO_REF:-develop}" https://github.com/kestellohq/cms.git kestello
    echo "kestello/ is kestellohq/cms at ${KESTELLO_REF:-develop}."
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "error: the PHP tools are installed with Composer, from Kestello's composer.lock. Install Composer (https://getcomposer.org) and run this again." >&2
    exit 1
fi
(cd kestello && composer install --no-interaction --no-progress --quiet)
echo "The PHP tools are in kestello/vendor/bin."

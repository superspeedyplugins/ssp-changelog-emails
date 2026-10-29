#!/usr/bin/env bash
set -euo pipefail
export CHANGELOG_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
source "${SUPERSPEEDY_WORKSPACE:-$HOME/dev/super-speedy}/tools/parallel-dev/bin/lib.sh"
expected=$(pd_site_dir ssp-changelog-emails tests-feature-regressions)
# pd_site_dir returns the resolved sites root (/mnt/wsl/sites on WSL since 28 October 2026); keep the
# /var/www/sites spelling so wp --path, the shim and the fixtures all see one path.
expected="${expected/#\/mnt\/wsl\/sites\//\/var\/www\/sites\/}"
[[ -z ${CHANGELOG_SITE:-} || "$CHANGELOG_SITE" == "$expected" ]] || pd_die 'Wrong Changelog fixture path'
expected_url=$(pd_site_url ssp-changelog-emails tests-feature-regressions)
[[ -z ${CHANGELOG_URL:-} || "$CHANGELOG_URL" == "$expected_url" ]] || pd_die 'Wrong Changelog fixture URL'
export CHANGELOG_SITE="$expected" CHANGELOG_URL="$expected_url"
[[ "$CHANGELOG_SITE" == /var/www/sites/ssp-changelog-emails/tests-feature-regressions && "$CHANGELOG_URL" == http://tests-feature-regressions.ssp-changelog-emails.localhost:8081 ]] || pd_die 'Wrong native fixture'
export PATH="$CHANGELOG_ROOT/.tests/fleet/bin:$PATH" WP_ADMIN_USER="$ADMIN_USER" WP_ADMIN_PASS="$ADMIN_PASS"
mkdir -p "$CHANGELOG_ROOT/.data/evidence"
printf 'Retained site: %s\nSource: %s\n' "$CHANGELOG_URL" "$CHANGELOG_ROOT"

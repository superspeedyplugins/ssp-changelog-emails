#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source .tests/fleet/site.sh
bad=0
check(){ local name=$1 expected=$2;shift 2;local actual=0;"$@" > ".data/evidence/control-$name.log" 2>&1 || actual=$?;printf '%s expected=%s actual=%s\n' "$name" "$expected" "$actual";[[ "$actual" == "$expected" ]] || bad=$((bad+1)); }
wp eval-file "$CHANGELOG_ROOT/.tests/fleet/seed.php" > .data/evidence/control-seed.log
wp eval-file "$CHANGELOG_ROOT/.tests/fleet/negative-audience.php" > .data/evidence/control-mutation.log
check audience-negative 1 wp eval-file "$CHANGELOG_ROOT/.tests/fleet/02-audience.php"
wp eval-file "$CHANGELOG_ROOT/.tests/fleet/seed.php" > .data/evidence/control-restored-seed.log
check audience-restored 0 wp eval-file "$CHANGELOG_ROOT/.tests/fleet/02-audience.php"
wp eval-file "$CHANGELOG_ROOT/.tests/fleet/04-content.php" > .data/evidence/control-content.log
check visual-negative 1 env CHANGELOG_NEGATIVE_VISUAL=1 node .tests/fleet/browser/content.cjs
check visual-restored 0 node .tests/fleet/browser/content.cjs
exit "$bad"

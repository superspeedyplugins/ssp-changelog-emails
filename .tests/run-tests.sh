#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source .tests/fleet/site.sh
bash .tests/fleet/setup.sh
status=0
for file in test-expired-over-six-months test-per-email-baselines;do wp eval-file "$CHANGELOG_ROOT/.tests/$file.php" > ".data/evidence/$file.log" 2>&1 || status=1;cat ".data/evidence/$file.log";done
for group in 01-map 02-audience 03-lifecycle 05-scheduled 04-content;do
 wp eval-file "$CHANGELOG_ROOT/.tests/fleet/seed.php" > ".data/evidence/seed-$group.log" 2>&1
 wp eval-file "$CHANGELOG_ROOT/.tests/fleet/$group.php" > ".data/evidence/$group.log" 2>&1 || status=1
 cat ".data/evidence/$group.log"
done
node .tests/fleet/browser/content.cjs > .data/evidence/browser.log 2>&1 || status=1
cat .data/evidence/browser.log
exit "$status"

#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/site.sh"
if [[ ! -f "$CHANGELOG_SITE/wp-config.php" ]]; then PATH=${PATH#*:} bash "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" ssp-changelog-emails tests-feature-regressions; fi
[[ $(realpath "$CHANGELOG_SITE/wp-content/plugins/ssp-changelog-emails") == "$CHANGELOG_ROOT" ]] || pd_die 'Wrong checkout'
mkdir -p "$CHANGELOG_SITE/wp-content/mu-plugins" "$CHANGELOG_ROOT/.data/dependencies"
cp "$CHANGELOG_ROOT/.tests/fleet/fixture.php" "$CHANGELOG_SITE/wp-content/mu-plugins/changelog-fixture.php"
freeze(){ local slug=$1 repo=$2 commit=$3; local target="$CHANGELOG_SITE/wp-content/plugins/$slug"; if [[ ! -f "$target/.fleet-commit" ]]; then [[ ! -e "$target" ]] || pd_die "Unmanaged dependency $target";mkdir -p "$target";git -C "$repo" archive "$commit" | tar -x -C "$target";git -C "$repo" rev-parse "$commit" > "$target/.fleet-commit";fi; [[ $(cat "$target/.fleet-commit") == $(git -C "$repo" rev-parse "$commit") ]] || pd_die "Dependency mismatch $slug"; }
freeze super-speedy-emails "$WORKSPACE/super-speedy-emails" 0766cf7
freeze ssp-product-renewals "$WORKSPACE/superspeedyplugins.com/ssp-product-renewals" ce485a0e
freeze ssp-licensing "$WORKSPACE/superspeedyplugins.com/ssp-licensing" 833addab
settings="$CHANGELOG_SITE/wp-content/plugins/super-speedy-emails/super-speedy-settings"
if [[ ! -f "$settings/super-speedy-settings.php" ]]; then mkdir -p "$settings";git -C "$WORKSPACE/super-speedy-emails/super-speedy-settings" archive f81447db4a82f812821ff761e06b733ae344e0ad | tar -x -C "$settings";fi
for slug in woocommerce woocommerce-product-bundles;do [[ -d "$CHANGELOG_SITE/wp-content/plugins/$slug" ]] || cp -a "$MAIN_SITE/wp-content/plugins/$slug" "$CHANGELOG_SITE/wp-content/plugins/$slug";done
[[ -f "$CHANGELOG_SITE/fleet-license-config.json" ]] || printf '{"rules":[],"plugin_aliases":{}}\n' > "$CHANGELOG_SITE/fleet-license-config.json"
wp plugin activate woocommerce
wp option update woocommerce_custom_orders_table_enabled yes
wp option update woocommerce_coming_soon no
wp option update woocommerce_store_pages_only no
wp option update sse_test_mode log_only
wp plugin activate woocommerce-product-bundles super-speedy-emails ssp-product-renewals ssp-licensing ssp-changelog-emails
wp eval 'if(!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()||!class_exists("SSP_License_Audience_Filters")||!class_exists("WC_Product_Bundle")||!SSE_Support_Types::exists("changelog"))throw new Exception("Missing real dependencies or HPOS");echo "WC ".WC_VERSION."; SSE ".SSE_VERSION."; HPOS yes\n";'

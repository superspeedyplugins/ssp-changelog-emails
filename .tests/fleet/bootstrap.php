<?php
if (!defined("WP_CLI") || !WP_CLI) {
    throw new Exception("Changelog regression entry requires WP-CLI");
}
if (
    realpath(ABSPATH) !==
        realpath("/var/www/sites/ssp-changelog-emails/tests-feature-regressions/") ||
    realpath(WP_PLUGIN_DIR . "/ssp-changelog-emails") !==
        realpath(__DIR__ . "/../..")
) {
    throw new Exception("Refusing wrong Changelog site/checkout");
}
if (
    !class_exists("SSE_Support_Emails") ||
    !class_exists("SSP_License_Audience_Filters") ||
    !class_exists("WC_Product_Bundle") ||
    !\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ||
    SSE_Sender::current_mode() !== "log_only"
) {
    throw new Exception("Missing real dependencies, HPOS or safe transport");
}
$GLOBALS["fleet_pass"] = 0;
$GLOBALS["fleet_fail"] = 0;
function ck($ok, $label, $actual = null)
{
    $GLOBALS[$ok ? "fleet_pass" : "fleet_fail"]++;
    echo ($ok ? "PASS " : "FAIL ") .
        $label .
        ($ok ? "" : " actual=" . wp_json_encode($actual)) .
        "\n";
}
function eq($expected, $actual, $label)
{
    ck($expected === $actual, $label, $actual);
}
function done()
{
    echo "TOTAL " .
        $GLOBALS["fleet_pass"] .
        " passed, " .
        $GLOBALS["fleet_fail"] .
        " failed\n";
    exit($GLOBALS["fleet_fail"] ? 1 : 0);
}
function fx()
{
    return get_option("fleet_changelog_ids");
}
function rule(
    $name,
    $scope = "all",
    $settings = [],
    $product = null,
    $body = null,
) {
    $f = fx();
    return SSE_Support_Rules::create([
        "name" => "fleet-" . $name,
        "status" => "draft",
        "type" => "changelog",
        "product_ids" => [$product ?? $f["products"]["main"]],
        "subject" => "{product_name}: {version}, {changes_summary}",
        "body" =>
            $body ??
            '<p>Hello {first_name}</p>{changelog}<p><a href="{changelog_url}">Read update details</a></p>',
        "settings" => array_merge(
            ["audience_scope" => $scope, "send_current_on_first_run" => true],
            $settings,
        ),
    ]);
}
function rec($id)
{
    return SSE_Support_Rules::get($id);
}
function basekey($id, $slug = "fleet-release")
{
    return SSP_Changelog_Emails::VERSION_OPTION . $slug . "_" . $id;
}
function plan_ids($id)
{
    $p = SSE_Support_Emails::plan_support_email(rec($id), true);
    $ids = array_column($p["products"][0]["recipients"], "subscriber_id");
    sort($ids);
    return $ids;
}

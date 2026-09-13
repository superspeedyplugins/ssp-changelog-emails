<?php
if (
    ABSPATH !== "/var/www/sites/ssp-changelog-emails/tests-feature-regressions/"
) {
    return;
}
if (!defined("DISABLE_WP_CRON")) {
    define("DISABLE_WP_CRON", true);
}
define("SSP_LICENSE_CONFIG_PATH", ABSPATH . "fleet-license-config.json");
add_filter("sse_support_assets_dir", fn() => ABSPATH . "fleet-assets", 999);
add_filter("pre_option_sse_test_mode", fn() => "log_only");
add_filter(
    "pre_wp_mail",
    function ($result, $args) {
        file_put_contents(
            ABSPATH . "fleet-mail.jsonl",
            wp_json_encode($args) . "\n",
            FILE_APPEND,
        );
        return true;
    },
    PHP_INT_MAX,
    2,
);
add_filter(
    "pre_http_request",
    function ($result, $args, $url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (
            $host === "127.0.0.1" ||
            str_ends_with((string) $host, ".localhost")
        ) {
            return $result;
        }
        file_put_contents(
            ABSPATH . "fleet-http.jsonl",
            wp_json_encode(["url" => $url, "method" => $args["method"]]) . "\n",
            FILE_APPEND,
        );
        return new WP_Error(
            "fleet_external_blocked",
            "No external fixture transport",
        );
    },
    0,
    3,
);

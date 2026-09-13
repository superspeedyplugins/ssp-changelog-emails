<?php
require __DIR__ . "/bootstrap.php";
global $wpdb;
// Only predictable synthetic fixtures are removed, on entry.
foreach (SSE_Support_Rules::get_all() as $r) {
    if (str_starts_with($r->name, "fleet-")) {
        SSE_Support_Rules::delete($r->id);
    }
}
$old = fx() ?: [];
foreach ($old["orders"] ?? [] as $id) {
    $o = wc_get_order($id);
    if ($o) {
        $o->delete(true);
    }
}
foreach ($old["products"] ?? [] as $id) {
    $p = wc_get_product($id);
    if ($p) {
        $p->delete(true);
    }
}
foreach ($old["variations"] ?? [] as $id) {
    $p = wc_get_product($id);
    if ($p) {
        $p->delete(true);
    }
}
foreach ($old["users"] ?? [] as $id) {
    require_once ABSPATH . "wp-admin/includes/user.php";
    wp_delete_user($id);
}
foreach ($old["subs"] ?? [] as $id) {
    $logs = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sse_email_log WHERE subscriber_id=%d",
            $id,
        ),
    );
    foreach ($logs as $lid) {
        $wpdb->delete($wpdb->prefix . "sse_email_queue", [
            "email_log_id" => $lid,
        ]);
        $wpdb->delete($wpdb->prefix . "sse_email_log", ["id" => $lid]);
    }
}
foreach ($old["subs"] ?? [] as $id) {
    SSE_Subscribers::delete($id);
}
foreach (
    $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sse_support_last_sent_version_fleet-%'",
    )
    as $key
) {
    delete_option($key);
}
delete_transient(SSP_Changelog_Emails::MAP_TRANSIENT);
$f = [
    "products" => [],
    "variations" => [],
    "users" => [],
    "orders" => [],
    "subs" => [],
];
foreach (
    [
        "main" => "fleet-release",
        "renamed" => "fleet-store-renamed",
        "hidden" => "fleet-retired",
        "draft" => "fleet-draft",
        "unowned" => "fleet-unowned",
    ]
    as $key => $slug
) {
    $p = in_array($key, ["main", "renamed"])
        ? new WC_Product_Variable()
        : new WC_Product_Simple();
    $p->set_name("Fleet " . ucfirst($key));
    $p->set_slug($slug);
    $p->set_status($key === "draft" ? "draft" : "publish");
    $p->set_regular_price("10");
    $f["products"][$key] = $p->save();
}
foreach (["yearly" => "yes", "lifetime" => "no"] as $key => $renew) {
    $p = new WC_Product_Variation();
    $p->set_parent_id($f["products"]["main"]);
    $p->set_regular_price("10");
    $p->set_status("publish");
    $p->update_meta_data("renewal_required", $renew);
    $f["variations"][$key] = $p->save();
}
$p = new WC_Product_Variation();
$p->set_parent_id($f["products"]["renamed"]);
$p->set_regular_price("10");
$p->set_status("publish");
$f["variations"]["renamed"] = $p->save();
$p = new WC_Product_Bundle();
$p->set_name("Fleet bundle");
$p->set_slug("fleet-bundle");
$p->set_status("publish");
$p->set_regular_price("20");
$p->set_bundled_data_items([
    [
        "product_id" => $f["products"]["main"],
        "meta_data" => ["quantity_min" => 1, "quantity_max" => 1],
    ],
]);
$f["products"]["bundle"] = $p->save();
$assets = ABSPATH . "fleet-assets";
wp_mkdir_p($assets);
foreach (
    ["fleet-release", "fleet-canonical", "fleet-draft", "fleet-unowned"]
    as $slug
) {
    wp_mkdir_p($assets . "/" . $slug);
    file_put_contents(
        $assets . "/" . $slug . ".json",
        wp_json_encode(["version" => "3.0.0"]),
    );
    copy(
        __DIR__ . "/release-readme.txt",
        $assets . "/" . $slug . "/readme.txt",
    );
}
file_put_contents(
    SSP_LICENSE_CONFIG_PATH,
    wp_json_encode([
        "plugin_aliases" => ["fleet-old-name" => "fleet-canonical"],
        "rules" => [
            [
                "type" => "plugin",
                "plugin" => "fleet-old-name",
                "products" => [(string) $f["variations"]["renamed"] => 1],
            ],
            [
                "type" => "plugin",
                "plugin" => "fleet-retired",
                "products" => [(string) $f["products"]["hidden"] => 1],
            ],
            [
                "type" => "group",
                "products" => [(string) $f["products"]["bundle"] => 1],
            ],
        ],
    ]),
);
touch(SSP_LICENSE_CONFIG_PATH, time());
$cases = [
    "active" => "-2 months",
    "five" => "-17 months",
    "six" => "-18 months",
    "twelve" => "-24 months",
    "thirteen" => "-25 months",
    "missing" => "-3 years",
    "lifetime" => "-3 years",
    "bundle" => "-3 years",
    "mixed_first" => "-2 months",
    "mixed_last" => "-2 months",
];
foreach ($cases as $key => $age) {
    $u = wp_create_user(
        "fleet-changelog-" . $key,
        "FleetSynthetic-2026!",
        "fleet-changelog-" . $key . "@example.invalid",
    );
    if (is_wp_error($u)) {
        throw new Exception($u->get_error_message());
    }
    $f["users"][$key] = $u;
    $make = function ($product, $date, $stamp = true) use (&$f, $u, $key) {
        $o = wc_create_order(["customer_id" => $u]);
        $o->set_billing_email("fleet-changelog-" . $key . "@example.invalid");
        $o->set_date_created(strtotime($date));
        $o->set_date_completed(strtotime($date));
        $o->add_product(wc_get_product($product), 1);
        $o->calculate_totals();
        $o->save();
        if ($stamp) {
            do_action(
                "woocommerce_checkout_order_processed",
                $o->get_id(),
                [],
                $o,
            );
        }
        $o = wc_get_order($o->get_id());
        $o->set_status("completed");
        $o->set_date_completed(strtotime($date));
        $o->save();
        SSE_Product_Owners::rebuild_for_order($o->get_id());
        $f["orders"][] = $o->get_id();
    };
    if ($key === "mixed_first") {
        $make($f["products"]["bundle"], "-3 years", false);
    }
    $make(
        $key === "bundle"
            ? $f["products"]["bundle"]
            : $f["variations"][$key === "lifetime" ? "lifetime" : "yearly"],
        $age,
        !in_array($key, ["missing", "bundle"]),
    );
    if ($key === "mixed_last") {
        $make($f["products"]["bundle"], "-3 years", false);
    }
    $sub = SSE_Subscribers::get_by_email(
        "fleet-changelog-" . $key . "@example.invalid",
    );
    $f["subs"][$key] = (int) $sub->id;
}
update_option("fleet_changelog_ids", $f);
SSE_Support_Emails::set_paused(true);
echo wp_json_encode($f) . "\n";

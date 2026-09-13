<?php
require __DIR__ . "/bootstrap.php";
$f = fx();
global $wpdb;
$rows = SSE_Product_Owners::rows_for_subscribers(array_values($f["subs"]), [
    $f["products"]["main"],
]);
$meta = SSE_Product_Owners::owner_meta_for(wp_list_pluck($rows, "owner_id"));
file_put_contents(
    ABSPATH . "fleet-owner-evidence.json",
    wp_json_encode(["rows" => $rows, "meta" => $meta], JSON_PRETTY_PRINT),
);
$by_sub = [];
foreach ($rows as $row) {
    if ((int) $row->direct === 1) {
        $by_sub[(int) $row->subscriber_id] = $meta[(int) $row->owner_id] ?? [];
    }
}
foreach (
    [
        "five" => "-5 months",
        "six" => "-6 months",
        "twelve" => "-12 months",
        "thirteen" => "-13 months",
    ]
    as $key => $date
) {
    eq(
        gmdate("Y-m-d", strtotime($date)),
        $by_sub[$f["subs"][$key]]["renewal_date"] ?? null,
        $key . " exact expiry stamped by real Renewals checkout",
    );
}
eq(
    "lifetime",
    $by_sub[$f["subs"]["lifetime"]]["license_tier"] ?? null,
    "lifetime tier ingested from genuine variation",
);
ck(
    empty($by_sub[$f["subs"]["missing"]]["renewal_date"]),
    "legacy undated order remains unknown expiry",
);
ck(SSE_Bundles::available(), "genuine Product Bundles schema available");
eq(
    [$f["products"]["main"]],
    SSE_Bundles::components_of($f["products"]["bundle"]),
    "genuine saved bundle expands component",
);
$expected = [
    "all" => array_keys($f["subs"]),
    "active_licence" => ["active", "lifetime", "mixed_first", "mixed_last"],
    "expired_within_6_months" => ["five"],
    "expired_over_6_months" => ["six", "twelve", "thirteen"],
    "expired_6_to_12_months" => ["six", "twelve"],
    "expired_over_12_months" => ["thirteen"],
];
foreach ($expected as $scope => $keys) {
    $id = rule("audience-" . $scope, $scope);
    $wanted = array_map(fn($k) => $f["subs"][$k], $keys);
    sort($wanted);
    eq($wanted, plan_ids($id), $scope . " exact planned subscriber IDs");
    $p = SSE_Support_Emails::run_support_email(rec($id));
    eq(count($wanted), $p["total_queued"], $scope . " actual fan-out count");
    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT subscriber_id,status FROM {$wpdb->prefix}sse_email_log WHERE source_reference=%s",
            "support:" . $id . ":" . $f["products"]["main"],
        ),
    );
    $actual = array_map("intval", wp_list_pluck($logs, "subscriber_id"));
    sort($actual);
    eq($wanted, $actual, $scope . " exact queued recipient IDs");
    SSE_Email_Queue::drain();
}
// Fresh rebuild in reverse order must preserve eligibility, including duplicate direct/bundle rows.
foreach (array_reverse($f["orders"]) as $oid) {
    SSE_Product_Owners::rebuild_for_order($oid);
}
$id = rule("audience-reversed", "active_licence");
$want = array_map(fn($k) => $f["subs"][$k], $expected["active_licence"]);
sort($want);
eq(
    $want,
    plan_ids($id),
    "reverse real order ingestion preserves active precedence",
);
done();

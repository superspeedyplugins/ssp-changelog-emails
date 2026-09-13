<?php
require __DIR__ . "/bootstrap.php";
$f = fx();
global $wpdb;
$id = rule("scheduled", "active_licence");
SSE_Support_Rules::update($id, ["status" => "active"]);
SSE_Support_Emails::set_paused(true);
$fresh = function () {
    exec(
        "wp eval " . escapeshellarg("SSE_Support_Emails::daily_check();"),
        $o,
        $code,
    );
    eq(0, $code, "fresh-process actual scheduled check");
};
$fresh();
eq(
    0,
    SSE_Email_Queue::pending_count(),
    "global pause prevents scheduled fan-out",
);
eq("", get_option(basekey($id), ""), "pause leaves baseline");
SSE_Support_Emails::set_paused(false);
$fresh();
eq(4, SSE_Email_Queue::pending_count(), "resume queues active record");
exec(
    "wp option get " . escapeshellarg(basekey($id)),
    $baseline,
    $baseline_exit,
);
eq(0, $baseline_exit, "fresh-process baseline lookup");
eq(
    "3.0.0",
    implode("", $baseline),
    "scheduled completion advances own baseline",
);
$fresh();
eq(
    4,
    SSE_Email_Queue::pending_count(),
    "second scheduled tick does not duplicate",
);
$paused = rule("record-paused");
SSE_Support_Rules::update($paused, ["status" => "paused"]);
$fresh();
eq(
    "",
    get_option(basekey($paused), ""),
    "paused record excluded from scheduler",
);
SSE_Support_Emails::set_paused(true);
$manual = rule("manual-while-paused", "expired_over_12_months");
eq(
    1,
    SSE_Support_Emails::run_support_email(rec($manual))["total_queued"],
    "manual run remains available during scheduled pause",
);
SSE_Email_Queue::drain();
eq(
    0,
    SSE_Email_Queue::pending_count(),
    "scheduled and manual local queue drained",
);
done();

<?php
require __DIR__ . "/bootstrap.php";
global $wpdb;
$f = fx();
$a = rule("first-current", "active_licence");
$b = rule("behind", "expired_over_6_months", [
    "send_current_on_first_run" => false,
]);
$legacy = SSP_Changelog_Emails::VERSION_OPTION . "fleet-release";
update_option($legacy, "1.0.0");
update_option(basekey($b), "2.0.0");
$p = SSE_Support_Emails::preview_content(rec($a), $f["products"]["main"]);
eq("will_send", $p["status"], "real preview resolves eligible owner");
eq(
    "",
    get_option(basekey($a), ""),
    "preview does not write first-run baseline",
);
eq("2.0.0", get_option(basekey($b)), "preview leaves second baseline");
$r = SSE_Support_Emails::run_support_email(rec($a));
eq(4, $r["total_queued"], "first record queues active owners");
eq("3.0.0", get_option(basekey($a)), "actual completion advances first record");
eq("2.0.0", get_option(basekey($b)), "completion leaves second record behind");
eq("1.0.0", get_option($legacy), "legacy baseline waits for second record");
eq(
    3,
    count(plan_ids($b)),
    "second audience still eligible after first completion",
);
SSE_Email_Queue::drain(1);
ck(
    SSE_Email_Queue::pending_count() > 0,
    "bounded actual drainer leaves remaining mail",
);
SSE_Email_Queue::drain();
eq(
    0,
    SSE_Email_Queue::pending_count(),
    "log_only drainer completes first batch",
);
$r = SSE_Support_Emails::run_support_email(rec($b));
eq(3, $r["total_queued"], "second record independently queues expired owners");
eq("3.0.0", get_option(basekey($b)), "second completion advances own baseline");
eq(
    "3.0.0",
    get_option($legacy),
    "both complete advances legacy migration baseline",
);
SSE_Email_Queue::drain();
eq(
    0,
    SSE_Support_Emails::run_support_email(rec($a))["total_queued"],
    "same record does not replay current release",
);
$wait = rule("safe-first", "all", ["send_current_on_first_run" => false]);
delete_option($legacy);
$preview = SSE_Support_Emails::plan_support_email(rec($wait), true);
eq(
    "",
    get_option(basekey($wait), ""),
    "safe first-run preview remains side-effect-free",
);
eq(
    0,
    SSE_Support_Emails::run_support_email(rec($wait))["total_queued"],
    "safe first real run queues nobody",
);
eq(
    "3.0.0",
    get_option(basekey($wait)),
    "safe first run establishes own baseline",
);
$empty = rule("empty-owner", "all", [], $f["products"]["unowned"]);
$r = SSE_Support_Emails::run_support_email(rec($empty));
eq("no_owners", $r["products"][0]["status"], "empty owner population reported");
eq(
    "",
    get_option(basekey($empty, "fleet-unowned"), ""),
    "no owners leaves baseline pending",
);
$skip = rule("legitimate-skip", "expired_over_12_months");
$rr = rec($skip);
SSE_Support_Rules::update($skip, [
    "conditions" => [["type" => "bought_within", "days" => 1]],
    "type" => "changelog",
]);
$r = SSE_Support_Emails::run_support_email(rec($skip));
eq(
    "all_skipped",
    $r["products"][0]["status"],
    "legitimate audience exclusion is complete",
);
eq(
    "3.0.0",
    get_option(basekey($skip)),
    "all-skipped fan-out advances baseline",
);
$bad = rule("content-fault");
SSE_Support_Rules::update($bad, ["subject" => "{changelog}"]);
$r = SSE_Support_Emails::run_support_email(rec($bad));
eq(
    "content_fault",
    $r["products"][0]["status"],
    "declared unresolved subject token surfaces content fault",
);
eq(
    "",
    get_option(basekey($bad), ""),
    "content fault does not advance baseline",
);
$transport = rule("transport-failure", "active_licence");
SSE_Support_Emails::run_support_email(rec($transport));
$mode = fn() => "redirect";
$redirect = fn() => "local-capture@example.invalid";
$fail = fn() => false;
add_filter("pre_option_sse_test_mode", $mode, PHP_INT_MAX);
add_filter("pre_option_sse_test_redirect_email", $redirect, PHP_INT_MAX);
add_filter("pre_wp_mail", $fail, PHP_INT_MAX);
SSE_Email_Queue::drain(1);
remove_filter("pre_wp_mail", $fail, PHP_INT_MAX);
remove_filter("pre_option_sse_test_mode", $mode, PHP_INT_MAX);
remove_filter("pre_option_sse_test_redirect_email", $redirect, PHP_INT_MAX);
ck(
    (int) $wpdb->get_var(
        "SELECT MAX(attempts) FROM {$wpdb->prefix}sse_email_queue",
    ) === 1,
    "local captured mail failure creates real retry",
);
eq(
    "3.0.0",
    get_option(basekey($transport)),
    "baseline completion means queued fan-out, not delivery acknowledgement",
);
$wpdb->query(
    "UPDATE {$wpdb->prefix}sse_email_queue SET next_attempt_at=UTC_TIMESTAMP()",
);
SSE_Email_Queue::drain();
eq(0, SSE_Email_Queue::pending_count(), "restored log_only drains retry");
done();

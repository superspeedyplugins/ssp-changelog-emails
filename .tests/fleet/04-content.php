<?php
require __DIR__ . "/bootstrap.php";
$f = fx();
global $wpdb;
$id = rule("content-html", "active_licence");
update_option(basekey($id), "1.0.0");
$p = SSE_Support_Emails::preview_content(rec($id), $f["products"]["main"]);
eq(
    "3.0.0",
    SSP_Changelog_Emails::current_version("fleet-release"),
    "JSON version wins over readme Stable tag",
);
$releases = SSP_Changelog_Emails::releases_since("fleet-release", "1.0.0");
eq(
    ["3.0.0", "2.0.0"],
    array_column($releases, "version"),
    "only versions strictly after baseline parsed",
);
eq(
    "First bold change with code. A continued explanation.",
    $releases[0]["items"][0],
    "multiline and markdown formatting parsed",
);
eq(
    6,
    count(SSP_Changelog_Emails::changelog_since("fleet-release", "1.0.0")),
    "flat changelog contains exact six changes",
);
eq(
    "Fleet Main: 3.0.0, 6 changes since we last sent this email",
    $p["subject"],
    "exact subject and change count through eligible preview",
);
ck(
    str_contains($p["html"], "Version 3.0.0") &&
        !str_contains($p["html"], "Version 2.0.0"),
    "HTML shows only current release heading",
);
ck(
    !str_contains($p["html"], "Fourth hidden item") &&
        !str_contains($p["html"], "Older one"),
    "HTML limits to first three current bullets",
);
ck(
    str_contains($p["html"], "&lt;script&gt;") &&
        !str_contains($p["html"], "<script>"),
    "readme literal script escaped in rendered HTML",
);
ck(str_contains($p["html"], "#v3-0-0"), "release link uses real deep anchor");
ck(
    str_contains($p["html"], "1 more item in this release") &&
        str_contains($p["html"], "2 more items you do not already have"),
    "tail counts remaining and earlier changes",
);
file_put_contents(ABSPATH . "fleet-preview.html", $p["html"]);
file_put_contents(
    ABSPATH . "fleet-preview.json",
    wp_json_encode($p, JSON_PRETTY_PRINT),
);
$r = SSE_Support_Emails::run_support_email(rec($id));
eq(4, $r["total_queued"], "HTML support record actually queued");
$ctx = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT context FROM {$wpdb->prefix}sse_email_log WHERE source_reference=%s AND email=%s ORDER BY id LIMIT 1",
        "support:" . $id . ":" . $f["products"]["main"],
        $p["recipient"],
    ),
);
$ctx = json_decode($ctx, true);
eq(
    $p["html"],
    $ctx["body_html"],
    "actual queued HTML equals eligible owner preview",
);
eq(
    $p["text"],
    $ctx["body_text"],
    "actual queued text alternative equals preview",
);
SSE_Email_Queue::drain();
$textid = rule(
    "content-text",
    "active_licence",
    [],
    null,
    "Hello {first_name}\n{changelog}\n{changelog_url}",
);
update_option(basekey($textid), "1.0.0");
$t = SSE_Support_Emails::preview_content(rec($textid), $f["products"]["main"]);
ck(
    str_contains(
        $t["text"],
        "- First bold change with code. A continued explanation.",
    ),
    "actual plain-text authored body contains expected bullet",
);
ck(
    str_contains(
        $t["text"],
        "https://www.superspeedyplugins.com/support/changelogs/fleet-release/#v3-0-0",
    ),
    "plain-text version deep link",
);
ck(
    !str_contains($t["text"], "Fourth hidden item") &&
        !str_contains($t["text"], "Baseline excluded"),
    "plain-text excludes hidden and baseline entries",
);
file_put_contents(
    ABSPATH . "fleet-preview-text.json",
    wp_json_encode($t, JSON_PRETTY_PRINT),
);
SSE_Support_Emails::run_support_email(rec($textid));
SSE_Email_Queue::drain();
done();

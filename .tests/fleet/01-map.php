<?php
require __DIR__ . "/bootstrap.php";
$f = fx();
$map = SSP_Changelog_Emails::product_map();
eq(
    "fleet-release",
    $map[$f["products"]["main"]] ?? null,
    "shipping URL slug resolves",
);
eq(
    "fleet-canonical",
    $map[$f["products"]["renamed"]] ?? null,
    "licence alias resolves renamed product",
);
foreach (["hidden", "draft", "bundle"] as $key) {
    ck(
        !isset($map[$f["products"][$key]]),
        $key . " excluded from shipping product map",
    );
}
eq(
    $map,
    apply_filters("sse_changelog_product_map", []),
    "actual SSE admin discovery receives canonical map",
);
$def = SSE_Support_Types::get("changelog");
eq("per_product", $def["mode"], "actual support registry mode");
$id = rule("map-alias", "all", [], $f["products"]["renamed"]);
$p = SSE_Support_Emails::plan_support_email(rec($id), true);
eq(
    "no_owners",
    $p["products"][0]["status"],
    "mapped unowned alias reaches actual owner resolver",
);
file_put_contents(ABSPATH . "fleet-map-before.json", wp_json_encode($map));
$config = json_decode(file_get_contents(SSP_LICENSE_CONFIG_PATH), true);
$config["rules"][0]["products"] = [(string) $f["products"]["hidden"] => 1];
file_put_contents(SSP_LICENSE_CONFIG_PATH, wp_json_encode($config));
touch(SSP_LICENSE_CONFIG_PATH, time() + 2);
exec(
    "wp eval " .
        escapeshellarg(
            "echo wp_json_encode(SSP_Changelog_Emails::product_map());",
        ),
    $out,
    $exit,
);
eq(0, $exit, "fresh request reads changed licence config");
$next = json_decode(implode("\n", $out), true);
eq(
    "fleet-canonical",
    $next[$f["products"]["hidden"]] ?? null,
    "mtime config refresh remaps canonical product",
);
ck(
    !isset($next[$f["products"]["renamed"]]),
    "old renamed mapping removed after config refresh",
);
done();

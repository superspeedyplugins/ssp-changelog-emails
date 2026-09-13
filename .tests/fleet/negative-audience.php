<?php
require __DIR__ . "/bootstrap.php";
$f = fx();
foreach ($f["orders"] as $id) {
    $o = wc_get_order($id);
    if ($o->get_customer_id() === $f["users"]["five"]) {
        foreach ($o->get_items() as $item) {
            $item->update_meta_data(
                "renewal_date",
                gmdate("Y-m-d", strtotime("-7 months")),
            );
            $item->save();
        }
        SSE_Product_Owners::rebuild_for_order($id);
    }
}
echo "Synthetic five-month owner's stored renewal moved to seven months and re-ingested\n";

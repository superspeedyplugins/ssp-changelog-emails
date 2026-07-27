=== Super Speedy Plugins — Changelog Emails ===
Contributors: superspeedy
Requires Plugins: super-speedy-emails
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later

A worked reference implementation of a Super Speedy Emails support-email type: read each plugin's readme.txt changelog and email the plugin's owners when a new version ships.

== Description ==

Super Speedy Emails ships a generic support-email engine — it resolves the audience, applies the suppression gate, deduplicates, queues, sends and logs, but knows nothing about *what* to send or *when*. A plugin supplies that policy by registering a support-email TYPE with `sse_register_support_email_type()`, giving it callbacks:

* `should_send` — (per product) is there news to send?
* `content` — (per product/recipient) fills the type's placeholders in the admin's body
* `should_send_to_user` — (per owner) should THIS person get it?
* `on_sent` — advance whatever baseline the type keeps

Admins then create Support Emails *of* that type in wp-admin, and the editor lists the type's own placeholders.

This plugin registers the `changelog` type (mode `per_product`) for the "read a readme.txt changelog" case, and is the same implementation Super Speedy Plugins uses to email its own customers when a plugin updates. It requires Super Speedy Emails 1.11.0 or later; on an older engine it registers nothing and sends nothing.

**To adapt it to your own products:**

1. Nothing, if your product URL slug matches your plugin slug (`/product/my-plugin/` for `my-plugin`) — the map derives itself from what ships. Otherwise either name the slug in an auth-server `license-config.json` `plugin` rule, or hook the `ssp_changelog_product_map` filter with your own `product_id => plugin-slug` pairs.
2. Edit `LIVE_BASE` (or define `SSE_SUPPORT_ASSETS_DIR`, or hook `sse_support_assets_dir`) to point at where your plugins' `{slug}/readme.txt` and `{slug}.json` files live on disk.
3. Edit `CHANGELOG_URL` to your public changelog base URL.

Then, in Super Speedy Emails → Support Emails, click Add Support Email, tick the products to monitor, set the subject to "Updates available for {product_name}", and write the body however you like — dropping the `{changelog}` placeholder wherever you want the update notes to appear. Save it Active. On the first run for each plugin the current version is recorded as a baseline and nobody is emailed; after that, each version bump emails its owners once per day at most. Use Preview to see the per-product reach before sending.

**Placeholders this plugin fills in the body:**

* `{changelog}` — the bulleted list of changelog entries since the version you last emailed about (capped, with a "see the full changelog" line if it overflows). Always replaced, even when there are no new entries, so a literal `{changelog}` never ships.
* `{changelog_url}` — the public changelog URL for this product.
* `{version}` — the current version string.

These sit alongside the core engine's own tokens (`{first_name}`, `{product_name}`, `{unsubscribe_url}`, `{manage_url}`, `{site_name}`, `{site_url}`), which the engine fills per-recipient *after* this plugin runs. Both single-brace (`{changelog}`) and double-brace (`{{changelog}}`) forms are accepted. Any token neither this plugin nor the core recognises is left untouched.

== Changelog ==

= 1.3.0 (27th July 2026) =
* The product -> plugin-slug map is now DERIVED, not hand-maintained. Launching a plugin no longer means editing this file. A product matches a plugin when its URL slug IS the plugin slug (`/product/delayed-image-import/`) and a `{slug}.json` ships for it, which covers everything we sell including the free plugins, since those never appear in `license-config.json` at all. Fixes Fullscreen Typeform and Delayed Image Import being blocked as unmapped.
* A product whose URL has been renamed away from its plugin slug is matched instead from auth-server's `license-config.json`: the per-plugin rules name the slug and list its variation IDs, and the variation's parent is the product. Read from `SSP_LICENSE_CONFIG_PATH` (set in wp-config.php - the file lives outside the docroot), falling back to an `auth-server` folder beside the WordPress root for dev, and filterable with `ssp_license_config_paths` - the same candidate list ssp-licensing uses, so the path is defined in one place.
* Bundle rules (`group` / `universal` / `global_exclude`) are deliberately not mapped: a bundle is not a plugin, and its owners already receive each component plugin's email through the owner-cache bundle expansion. Retired plugins that still carry a licence rule but no longer ship a readme are skipped too, so an empty changelog can never satisfy the engine's unfilled-placeholder guard.
* The assets dir is never scanned. It sits inside the uploads tree on live and holds a lot of files, and a `glob()` there costs about 0.4ms per 1,000 entries (86ms at 200,000) - every call, cache hit included. Instead the lookup asks "does a plugin of this product's name ship?" with a single `is_readable()`, which does not get slower as the directory grows: 0.04ms per lookup measured. The full map (one indexed query over published products) is only built for the rename case, and is cached for a day against the licence config's modification time. New `ssp_changelog_product_map` filter for a product neither rule catches.

= 1.2.1 (27th July 2026) =
* The "last emailed version" baseline now advances in the new `on_complete` callback, once a product's whole fan-out has finished, instead of per recipient in `on_sent`. Previously the baseline moved on the first recipient of a run, so a run that died partway through a large audience (a PHP timeout while queueing, say) left everyone it had not reached without that version's changelog until the next release. On a Super Speedy Emails older than 1.11.2, which does not dispatch `on_complete`, the baseline falls back to advancing in `on_sent` as before, so the two plugins can be deployed in either order.
* Fixed: a product ticked in the Support Email but missing from `product_map()` no longer sends. There is no readme.txt to read for an unmapped product, so `{changelog}` could never be filled and the raw placeholder shipped in the email. `should_send` now returns false for an unmapped product.
* Description updated to describe the type registry rather than the retired loose filters, and the Stable tag corrected (it was left at 1.1.0 when 1.2.0 shipped).

= 1.2.0 =
* Refactored onto the Super Speedy Emails Support Email type registry: registers a `changelog` type via `sse_register_support_email_type()` instead of the three loose support-email filters. Behaviour is unchanged - `{changelog}` / `{changelog_url}` / `{version}` substitution and per-owner sending work exactly as before - but the type is now selectable in the Support Email editor, which lists its placeholders. Requires Super Speedy Emails 1.11.0+ (the type registry).

= 1.1.0 =
* The content filter now fills a `{changelog}` placeholder inside the admin's body instead of replacing the whole body. The wording of the email stays under the admin's control in the Support Email editor; only `{changelog}` / `{changelog_url}` / `{version}` are substituted. Existing setups that relied on the body being fully generated should add `{changelog}` to their Support Email body.

= 1.0.0 =
* Initial release. Implements the three support-email filters for readme.txt changelogs.

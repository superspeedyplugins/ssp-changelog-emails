=== Super Speedy Plugins — Changelog Emails ===
Contributors: superspeedy
Requires Plugins: super-speedy-emails
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later

A worked reference implementation of the Super Speedy Emails support-email filters: read each plugin's readme.txt changelog and email the plugin's owners when a new version ships.

== Description ==

Super Speedy Emails ships a generic support-email engine — it resolves the audience, applies the suppression gate, deduplicates, queues, sends and logs, but knows nothing about *what* to send or *when*. It asks three filters for that policy:

* `sse_should_send_support_email` — (per product) is there news to send?
* `sse_support_email_content` — (per product) the subject + body
* `sse_support_email_should_send_to_user` — (per owner) should THIS person get it?

This plugin implements those three filters for the "read a readme.txt changelog" case, and is the same implementation Super Speedy Plugins uses to email its own customers when a plugin updates.

**To adapt it to your own products:**

1. Edit the `product_map()` method — your WooCommerce `product_id => plugin-slug` pairs.
2. Edit `LIVE_BASE` (or define `SSE_SUPPORT_ASSETS_DIR`, or hook `sse_support_assets_dir`) to point at where your plugins' `{slug}/readme.txt` and `{slug}.json` files live on disk.
3. Edit `CHANGELOG_URL` to your public changelog base URL.

Then, in Super Speedy Emails → Support Emails, click Add Support Email, tick the products to monitor, set the subject to "Updates available for {product_name}", and write the body however you like — dropping the `{changelog}` placeholder wherever you want the update notes to appear. Save it Active. On the first run for each plugin the current version is recorded as a baseline and nobody is emailed; after that, each version bump emails its owners once per day at most. Use Preview to see the per-product reach before sending.

**Placeholders this plugin fills in the body:**

* `{changelog}` — the bulleted list of changelog entries since the version you last emailed about (capped, with a "see the full changelog" line if it overflows). Always replaced, even when there are no new entries, so a literal `{changelog}` never ships.
* `{changelog_url}` — the public changelog URL for this product.
* `{version}` — the current version string.

These sit alongside the core engine's own tokens (`{first_name}`, `{product_name}`, `{unsubscribe_url}`, `{manage_url}`, `{site_name}`, `{site_url}`), which the engine fills per-recipient *after* this plugin runs. Both single-brace (`{changelog}`) and double-brace (`{{changelog}}`) forms are accepted. Any token neither this plugin nor the core recognises is left untouched.

== Changelog ==

= 1.2.0 =
* Refactored onto the Super Speedy Emails Support Email type registry: registers a `changelog` type via `sse_register_support_email_type()` instead of the three loose support-email filters. Behaviour is unchanged - `{changelog}` / `{changelog_url}` / `{version}` substitution and per-owner sending work exactly as before - but the type is now selectable in the Support Email editor, which lists its placeholders. Requires Super Speedy Emails 1.11.0+ (the type registry).

= 1.1.0 =
* The content filter now fills a `{changelog}` placeholder inside the admin's body instead of replacing the whole body. The wording of the email stays under the admin's control in the Support Email editor; only `{changelog}` / `{changelog_url}` / `{version}` are substituted. Existing setups that relied on the body being fully generated should add `{changelog}` to their Support Email body.

= 1.0.0 =
* Initial release. Implements the three support-email filters for readme.txt changelogs.

=== Super Speedy Plugins — Changelog Emails ===
Contributors: superspeedy
Requires Plugins: super-speedy-emails
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.6.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

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

Then, in Super Speedy Emails → Support Emails, click Add Support Email, tick the products to monitor, choose all owners, active licences, one of the three expired-licence age bands, or everyone expired more than six months ago, set the subject to "Updates available for {product_name}", and write the body however you like — dropping the `{changelog}` placeholder wherever you want the update notes to appear. Save it Active. On the first run for each plugin the current version is recorded as a baseline and nobody is emailed; after that, each version bump emails its owners once per day at most. Use Preview to see the per-product reach before sending.

When a customer owns the same product directly and through a bundle, any active ownership keeps them in the active audience. If every ownership has expired, the most recent expiry determines their age band.

**Placeholders this plugin fills in the body:**

* `{changelog}` — the latest releases since the version you last emailed about, each as a dated heading that deep-links to its own entry on the changelog page, with the rest behind an "and N more changes" link. HTML when your body is HTML, plain text otherwise. Always replaced, even when there are no new entries, so a literal `{changelog}` never ships.
* `{changes_count}` — how many changes in total since the version you last emailed about, e.g. `25`. For an opening line such as "{changes_count} updates to {product_name} are ready for you".
* `{changelog_url}` — the public changelog URL for this product.
* `{version}` — the current version string.

These sit alongside the core engine's own tokens (`{first_name}`, `{product_name}`, `{unsubscribe_url}`, `{manage_url}`, `{site_name}`, `{site_url}`), which the engine fills per-recipient *after* this plugin runs. Both single-brace (`{changelog}`) and double-brace (`{{changelog}}`) forms are accepted. Any token neither this plugin nor the core recognises is left untouched.

== Changelog ==

= 1.6.2 (18th August 2026) =
* Three new "Send to" audiences on each changelog Support Email: licences expired within the last 6 months, expired 6 to 12 months ago, and expired more than 12 months ago. The bands never overlap, so separate records can cover the whole expired base without anyone receiving two emails.
* A licence with no stored renewal date is treated as expiring 365 days after its last purchase, matching how active licences are already judged.
* Each changelog Support Email now keeps its own "last emailed version" baseline, so two records for the same product advance independently and one record's send no longer uses up the release for another. Existing baselines carry over automatically.
* Corrected the licence declaration in readme.txt to GPL 3.

= 1.6.1 (6th August 2026) =
* Each plugin's version file and changelog are read once per send rather than once per recipient. The email code asks for them for every person on the list, and on a plugin with a few thousand owners that was several thousand file reads for a file that cannot change mid-send.
* Requires Super Speedy Emails 1.17.0 for the "changes you do not have yet" count to be each reader's own. On an earlier version that count is the same number for everyone, whatever this plugin works out.

= 1.6.0 (4th August 2026) =
* New "Send to" dropdown on each changelog Support Email: all owners, or active licences only. It is an option on the record, so two Support Emails for the same products can target different audiences without any code.
* Active licence means any one of: bought or renewed within the last year, a renewal date still in the future, or a lifetime licence. Lifetime customers therefore keep receiving update emails for as long as they want them, and only ever stop by unsubscribing.
* A customer who got the plugin in a bundle usually has no licence record of their own, so they count as active while their bundle purchase is under a year old.
* Preview honours the dropdown before you save, so you can see how many people each choice reaches before committing to it.
* Requires Super Speedy Emails 1.16.0+ for the dropdown. On an older version the option is not shown and every changelog email goes to all owners, exactly as before.

= 1.5.0 (27th July 2026) =
* `{changelog}` now shows TODAY'S release only, and only its first 3 items. The rest is a number and a link: "And 2 more items in this release and 19 more items you do not already have. See the full changelog." Both halves are counted honestly, either half is dropped when its count is zero, and the whole line disappears when the email already shows everything.
* Each release now reads as bold date, then "Version 3.2.0" as the link to that release's own anchor on the changelog page, with the items as bullets underneath. No heading tag - it sits inside the admin's own body copy, so it should read as part of it rather than as a document section inheriting a template's heading styles.
* `{changes_count}` is now counted from the version the READER actually has installed, via the new `ssp_licensing_installed_version()` in ssp-licensing (requires ssp-licensing 1.22+). A customer with several sites on several versions is counted from their LATEST, so a "changes you do not have" count can never claim they are missing something they already run.
* We will often not know their version - it relies on them having entered their licence key and their sites having checked in since auth-server started recording versions. Every one of those readers still needs a true sentence, so the count falls back to our own "last emailed about" baseline whenever the version is unknown, the subscriber has no linked WordPress user, or they are already on the current version.
* New `{changes_summary}` placeholder carrying the count AS A PHRASE, which is what makes the fallback invisible to the reader: "25 changes you do not have yet" when we know their version, "25 changes since we last sent this email" when we do not. Intended for the subject line.
* Placeholders now work in the SUBJECT as well as the body (requires Super Speedy Emails 1.11.3+, which passes the subject through the type). `{changelog}` is deliberately not substituted there - a list of releases has no business in a subject line, so an admin who puts it there gets it blocked rather than sent mangled.
* Never advertises zero. If the baseline has already caught up with the current version - a preview, or a second Support Email for the same product having advanced it - the count falls back to "5 changes in this release" rather than rendering "0 changes since we last sent this email" into a subject line.

= 1.4.0 (27th July 2026) =
* Fixed: the version headers and their dates were being thrown away when `{changelog}` was filled, leaving a flat undated list of bullets. `{changelog}` now keeps each release as its own dated heading. This is not cosmetic - a run of dated releases is the evidence that the plugin is actively developed, and flattening it hid the one thing the email was there to demonstrate.
* Each release heading now deep-links to its own entry on the changelog page (ssp-changelogs already emits matching anchors such as `#v2-79-0`), so every release in the email is its own click target instead of there being a single link at the end.
* `{changelog}` now shows the latest 3 releases and puts the rest behind an honest "And 19 more changes across 5 earlier releases - see the full changelog" link. A second cap keeps the total to 12 bullets so one enormous release cannot fill the inbox. Nothing is silently truncated: whatever the caps hold back is counted in that line, and the line is omitted entirely when the email already shows everything.
* New `{changes_count}` placeholder - the total number of changes since the version we last emailed about, for a subject or opening line such as "25 updates to {product_name}". Counted across every release, including the ones behind the link.
* `{changelog}` is rendered as HTML when the Support Email body is HTML (headings, lists and real links) and as plain text otherwise, so it suits both body styles.

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

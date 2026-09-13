# Changelog Emails regression tests

Run from this repository:

```bash
bash .tests/run-tests.sh
```

First install the owned browser dependency with `npm ci --prefix .tests/fleet/browser`. The native runner provisions and retains `http://tests-feature-regressions.ssp-changelog-emails.localhost:8081`, using this exact checkout. Shared tools resolve from `SUPERSPEEDY_WORKSPACE` or `$HOME/dev/super-speedy`, not the grouped repository's parent. The real WP shim pins its dedicated path last and refuses absent/mismatched environment; browser entry checks path, URL and real plugin checkout before navigation.

Dependencies are frozen copies of SSE 0766cf7 (settings f81447db), Renewals ce485a0e and Licensing 833addab, plus real installed WooCommerce 10.5.3 and Product Bundles 8.5.8. HPOS is enabled and asserted; coming-soon modes are off. Missing dependencies fail setup. The MU fixture blocks external HTTP and locally captures all mail; SSE is log_only. The one transport-failure case temporarily uses redirect to an example.invalid address and the local mail capture returns failure, then restores log_only for actual retry draining.

Both original callback contract checks run first. Their assertions remain lower-level evidence, not genuine ownership E2E; the baseline fixture's own assets override the site assets only in that process. Five new PHP groups reset named synthetic fixtures on entry and drive real discovery, WooCommerce/Renewals ownership, exact audience recipient IDs, support preview/run/queue/drain, independent baselines, fresh-process scheduled pause/resume and readme rendering. Runtime output stays in `.data/evidence/`. The final content preview is served by the retained native site and inspected by real Chromium at 1440/390 widths with visible-list/CTA, escaping, geometry, overflow and keyboard-focus assertions.

Run `bash .tests/negative-controls.sh` after the main suite. It moves one synthetic renewal date into a different audience band through the real ingestion path, requires the exact-recipient assertions to fail, resets on entry and requires restored pass. It then hides the rendered email list in the real browser, requires visibility failure, and requires restored pass. No product file changes or mail leaves the fixture. Tests never tear down their sites. Concurrent runs on this one fixture are forbidden.

For a selected PHP group: source `.tests/fleet/site.sh`, run `wp eval-file "$CHANGELOG_ROOT/.tests/fleet/seed.php"`, then `wp eval-file "$CHANGELOG_ROOT/.tests/fleet/03-lifecycle.php"` (substitute the desired group). Do not skip the entry reset: legacy baseline advancement examines all actual support records for that product.

Current SSE support sending uses synchronous fan-out and scheduled pause/resume, with no separate cancellable support-job API. Tests exercise those actual controls and do not invent cancellation. Fan-out completion advances the Changelog baseline when recipients are queued; mail delivery failure and retries happen later. No-owners and content-fault states retain the pending baseline; legitimate all-skipped recipients complete it.

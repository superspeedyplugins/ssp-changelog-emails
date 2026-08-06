<?php
/**
 * Plugin Name: Super Speedy Plugins — Changelog Emails
 * Description: Sends product-update (changelog) emails to plugin owners via Super Speedy Emails. A worked reference implementation of a support-email TYPE — read each plugin's readme.txt and email owners when a new version ships. The product→slug map derives itself from what ships plus auth-server's license-config.json; adapt the readme location to your own setup. Requires Super Speedy Emails 1.11.0+.
 * Version:     1.6.1
 * Author:      Super Speedy Plugins
 * Author URI:  https://www.superspeedyplugins.com
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: super-speedy-emails
 *
 * HOW THIS WORKS
 * --------------
 * Super Speedy Emails ships a GENERIC support-email engine. It resolves the
 * audience, applies the suppression gate, deduplicates, queues and logs — but it
 * knows nothing about changelogs. A plugin registers a support-email TYPE with
 * sse_register_support_email_type(), supplying the policy as callbacks; admins
 * then create Support Emails of that type in wp-admin and pick its placeholders.
 *
 * This plugin registers the 'changelog' type (mode per_product) with:
 *   should_send          (per product) — is there news? (the version advanced)
 *   content              (per product/recipient) — fills {changelog} etc. in the body
 *   should_send_to_user  (per owner)   — should THIS person get it? Driven by the
 *                                        record's "Send to" option: all owners, or
 *                                        active licences only
 *   on_complete          (per product) — advance the "last emailed version" baseline,
 *                                        once the product's whole fan-out is done
 *
 * It also declares an ADMIN FIELD (`audience_scope`), so the audience choice lives in
 * the Support Email editor rather than in this file: the engine renders the dropdown,
 * stores it on the record, honours it in Preview, and hands it back to the gate via
 * $context->setting(). That is the pattern to copy for any per-record option.
 *
 * Each callback receives an SSE_Support_Context. In product mode it carries
 * ->product_id, ->subscriber, ->matching_product, ->all_products (+ ->owns(),
 * ->bought_within(), ->is_product_mode()).
 *
 * Swap the readme reader for any source (security advisories, an API, a CPT) and
 * register a different type. For a per-USER type — one email per person, with an
 * escalating reminder sequence — see ssp-licensing's licence-digest.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SSP_Changelog_Emails {

    /** Where each plugin's {slug}.json + {slug}/readme.txt live on disk. */
    const LIVE_BASE      = '/var/www/superspeedyplugins.com/htdocs/assets/plugins';

    /**
     * Per-plugin "last version we emailed about" is stored in this option + slug.
     * NOTE: this is the same key the previously-bundled core class used, so any
     * baselines already recorded on a live site carry over with no re-baseline.
     */
    const VERSION_OPTION = 'sse_support_last_sent_version_';

    /** Public "read the full changelog" base URL. */
    const CHANGELOG_URL  = 'https://www.superspeedyplugins.com/support/changelogs/';

    /**
     * How many items of today's release the email shows. The rest sit behind the
     * "and N more" link, which is the whole point: the reader clicks through to the
     * changelog page rather than reading the lot in their inbox.
     */
    const MAX_ITEMS      = 3;

    /** Derived product→slug map, cached against a signature of its sources. */
    const MAP_TRANSIENT  = 'ssp_changelog_product_map';

    /**
     * How recent a purchase counts as an active licence, when we have no renewal
     * date to go on. A year, because that is the term we sell.
     */
    const ACTIVE_LICENCE_DAYS = 365;

    public static function init() {
        // Only wire up if the core engine (with the type registry) is present.
        if ( ! function_exists( 'sse_register_support_email_type' ) ) {
            return;
        }
        add_action( 'sse_register_support_email_types', array( __CLASS__, 'register_type' ) );

        // Expose our product→slug map to the core admin "Changelog automation"
        // screen so it can show which products are mapped.
        add_filter( 'sse_changelog_product_map', array( __CLASS__, 'filter_product_map' ) );
    }

    /**
     * Register the 'changelog' Support Email type. Its three callbacks are the same
     * methods that used to hook the loose support-email filters; only the wiring
     * changed (per-type dispatch instead of global filters). Admins pick this type
     * when creating a Support Email, and the editor then shows our placeholders.
     */
    public static function register_type() {
        // Which callback advances the baseline depends on the engine. on_complete
        // (fired once the product's fan-out finishes) is the correct home for it,
        // but only engines from 1.11.2 dispatch it — on an older one it would never
        // fire, the baseline would never move, and every daily run would email the
        // same changelog again. So fall back to the per-recipient on_sent there.
        // This keeps BOTH deploy orders safe: neither plugin has to land first.
        $baseline_hook = self::engine_has_on_complete() ? 'on_complete' : 'on_sent';

        sse_register_support_email_type( 'changelog', array(
            'label'        => __( 'Changelog (product update)', 'ssp-changelog-emails' ),
            'mode'         => 'per_product',
            'stages'       => 1,
            'placeholders' => array(
                '{changelog}'       => __( "Today's release: the date, a link to that version on the changelog page, the first few items, and a count of what is left", 'ssp-changelog-emails' ),
                '{changes_count}'   => __( 'How many changes the reader has not got - counted from their own installed version when we know it, e.g. 25', 'ssp-changelog-emails' ),
                '{changes_summary}' => __( 'The same count as a phrase that reads correctly whether or not we know their version, e.g. "25 changes you do not have yet". Use this in the subject', 'ssp-changelog-emails' ),
                '{changelog_url}' => __( 'Link to the full changelog page for the product', 'ssp-changelog-emails' ),
                '{version}'       => __( 'The current version', 'ssp-changelog-emails' ),
            ),
            'footer'       => __( "You're receiving this because you own {product_name}.", 'ssp-changelog-emails' ),
            // Per-record options the admin picks in the Support Email editor. The
            // engine renders these, stores them on the record and hands them back
            // through $context->setting() — no per-record code, and Preview honours a
            // change before it is saved. Needs Super Speedy Emails 1.16.0+; on an
            // older engine the field simply is not rendered and the gate below falls
            // back to 'all', so BOTH deploy orders stay safe.
            'admin_fields' => array(
                'audience_scope' => array(
                    'type'        => 'select',
                    'label'       => __( 'Send to', 'ssp-changelog-emails' ),
                    'description' => __( 'All owners, or only those on an active licence (bought or renewed within the last year, or a renewal date still in the future, or a lifetime licence).', 'ssp-changelog-emails' ),
                    'default'     => 'all',
                    'options'     => array(
                        array( 'value' => 'all',            'label' => __( 'All owners', 'ssp-changelog-emails' ) ),
                        array( 'value' => 'active_licence', 'label' => __( 'Active licence only', 'ssp-changelog-emails' ) ),
                    ),
                ),
            ),
            'callbacks'    => array(
                'should_send'         => array( __CLASS__, 'should_send' ),
                'should_send_to_user' => array( __CLASS__, 'should_send_to_user' ),
                'content'             => array( __CLASS__, 'content' ),
                $baseline_hook        => array( __CLASS__, 'on_complete' ),
            ),
        ) );
    }

    /**
     * Does the installed Super Speedy Emails dispatch the on_complete callback?
     * Added in 1.11.2. Absent SSE_VERSION we assume the older contract, which is the
     * safe guess: on_sent always fires, on_complete might not.
     */
    private static function engine_has_on_complete() {
        return defined( 'SSE_VERSION' ) && version_compare( SSE_VERSION, '1.11.2', '>=' );
    }

    /* --------------------------------------------------------------------- */
    /* Product → plugin-slug map                                              */
    /* --------------------------------------------------------------------- */

    /**
     * WooCommerce product_id => plugin slug, for the individual plugins (NOT
     * bundles or services — bundle owners receive each component plugin's email
     * via the owner-cache bundle expansion).
     *
     * DERIVED, never hand-maintained: launching a plugin must not require editing
     * this file. A product is matched to a plugin two ways:
     *
     *   1. Its URL slug IS the plugin slug (/product/delayed-image-import/), and a
     *      {slug}.json ships for it. Covers everything we sell, free ones included —
     *      the free plugins never appear in license-config.json at all.
     *   2. license-config.json's `plugin` rules, which name the slug and list its
     *      purchasable variation IDs; the variation's PARENT is the product we want
     *      (a simple product lists itself, so parent 0 → itself). This is what
     *      survives a product page being renamed away from its plugin slug.
     *
     * Overridable via the ssp_changelog_product_map filter for a product that neither
     * rule catches.
     */
    public static function product_map() {
        $sig    = self::map_signature();
        $cached = get_transient( self::MAP_TRANSIENT );

        if ( is_array( $cached ) && isset( $cached['sig'], $cached['map'] ) && $cached['sig'] === $sig ) {
            $map = (array) $cached['map'];
        } else {
            $map = self::derive_product_map();
            // Signature is the licence config's mtime, so a retier remaps at once.
            // A newly deployed plugin does NOT need this cache to expire: slug_for()
            // stats its {slug}.json directly, so it resolves on the next run either way.
            set_transient( self::MAP_TRANSIENT, array( 'sig' => $sig, 'map' => $map ), DAY_IN_SECONDS );
        }

        return (array) apply_filters( 'ssp_changelog_product_map', $map );
    }

    /**
     * Build the full map WITHOUT ever scanning the assets dir. On live that dir sits
     * inside the uploads tree and holds a lot of files, and glob() there is linear in
     * the file count (measured: ~0.4ms per 1,000 entries, ~86ms at 200,000) — a cost
     * paid on every call, cache hit included, if the scan were part of the lookup.
     *
     * So the universe is the PRODUCTS, not the files: one indexed query for published
     * products, then a single is_readable() stat per product to ask "does a plugin of
     * that name ship?". Stat cost does not grow with the directory.
     *
     * Only slugs that actually ship are mapped: a retired plugin still carrying a
     * licence rule (price-comparison-pro) has no readme to quote, and mapping it would
     * let content() replace {changelog} with nothing — passing the engine's
     * unfilled-placeholder guard with an empty email instead of being blocked by it.
     *
     * @return array product_id => slug
     */
    private static function derive_product_map() {
        global $wpdb;

        if ( ! is_dir( self::assets_dir() ) ) {
            // Loud, not silent: an unreadable assets dir means EVERY product looks
            // unmapped, and the whole changelog automation quietly stops.
            error_log( 'ssp-changelog-emails: assets dir ' . self::assets_dir() . ' is not readable - nothing can be mapped.' );
            return array();
        }

        $map  = array();
        $rows = $wpdb->get_results(
            "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
        );

        foreach ( (array) $rows as $row ) {
            if ( self::ships( $row->post_name ) ) {
                $map[ (int) $row->ID ] = sanitize_title( $row->post_name );
            }
        }

        // Renamed products, whose URL no longer matches the plugin slug: the licence
        // rule names the slug explicitly, and its variations point at the product.
        $aliases = self::license_config_aliases();
        foreach ( self::license_config_products() as $slug => $ids ) {
            // Activations (and therefore rules) are keyed by the canonical slug for a
            // renamed plugin, e.g. super-speedy-filters -> faster-woo-widgets.
            $slug = isset( $aliases[ $slug ] ) ? $aliases[ $slug ] : $slug;
            if ( in_array( $slug, $map, true ) || ! self::ships( $slug ) ) {
                continue;
            }
            $product_id = self::product_id_from_variations( $ids );
            if ( $product_id ) {
                $map[ $product_id ] = $slug;
            }
        }

        return $map;
    }

    /** Does a plugin of this slug ship? One stat — never a directory scan. */
    private static function ships( $slug ) {
        $slug = sanitize_title( (string) $slug );
        return '' !== $slug && is_readable( self::assets_dir() . '/' . $slug . '.json' );
    }

    /**
     * First published `product` behind a rule's product IDs. Those IDs are
     * variations (the licence tiers), so take the parent; a simple product has no
     * parent and stands for itself.
     */
    private static function product_id_from_variations( $ids ) {
        foreach ( (array) $ids as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) {
                continue;
            }
            $parent     = wp_get_post_parent_id( $id );
            $product_id = $parent ? (int) $parent : $id;
            if ( 'product' === get_post_type( $product_id ) && 'publish' === get_post_status( $product_id ) ) {
                return $product_id;
            }
        }
        return 0;
    }

    /** Cache key material: when the licence config last changed. */
    private static function map_signature() {
        $path = self::license_config_path();
        return ( $path && is_readable( $path ) ) ? (string) filemtime( $path ) : '0';
    }

    /* --------------------------------------------------------------------- */
    /* license-config.json — the same file auth-server enforces licences from  */
    /* --------------------------------------------------------------------- */

    /**
     * Full path to license-config.json. Live sets SSP_LICENSE_CONFIG_PATH in
     * wp-config.php (the file lives outside the docroot, reachable via an ACL);
     * the dev fallbacks keep auth-server as a sibling of the WordPress root.
     * Same candidate list and same filter as ssp-licensing's wpi_license_config().
     */
    private static function license_config_path() {
        static $resolved = false;
        static $path     = '';

        if ( $resolved ) {
            return $path;
        }
        $resolved = true;

        $candidates = array();
        if ( defined( 'SSP_LICENSE_CONFIG_PATH' ) ) {
            $candidates[] = SSP_LICENSE_CONFIG_PATH;
        }
        $candidates[] = dirname( dirname( ABSPATH ) ) . '/auth-server/license-config.json';
        $candidates[] = dirname( ABSPATH ) . '/auth-server/license-config.json';

        $candidates = apply_filters( 'ssp_license_config_paths', $candidates );

        foreach ( $candidates as $candidate ) {
            if ( $candidate && is_readable( $candidate ) ) {
                $path = (string) $candidate;
                break;
            }
        }

        return $path;
    }

    private static function license_config() {
        static $config = null;
        static $loaded = false;

        if ( $loaded ) {
            return $config;
        }
        $loaded = true;

        $path = self::license_config_path();
        if ( $path ) {
            $data = json_decode( (string) file_get_contents( $path ), true );
            if ( is_array( $data ) ) {
                $config = $data;
            }
        }

        return $config;
    }

    /**
     * slug => product/variation IDs, from the per-plugin rules ONLY. Bundle rules
     * (group / universal / global_exclude) are deliberately skipped: a bundle is
     * not a plugin, and its owners already receive each component plugin's email
     * through the owner-cache bundle expansion.
     *
     * @return array slug => int[]
     */
    private static function license_config_products() {
        $config = self::license_config();
        $out    = array();

        foreach ( (array) ( isset( $config['rules'] ) ? $config['rules'] : array() ) as $rule ) {
            if ( ! is_array( $rule ) || 'plugin' !== ( isset( $rule['type'] ) ? $rule['type'] : '' ) ) {
                continue;
            }
            if ( empty( $rule['plugin'] ) || empty( $rule['products'] ) || ! is_array( $rule['products'] ) ) {
                continue;
            }
            $slug = sanitize_title( (string) $rule['plugin'] );
            if ( '' === $slug ) {
                continue;
            }
            $ids = array_map( 'intval', array_keys( $rule['products'] ) );
            // Later rules win, matching auth-server's own resolution order.
            $out[ $slug ] = isset( $out[ $slug ] ) ? array_merge( $out[ $slug ], $ids ) : $ids;
        }

        return $out;
    }

    /** slug => canonical slug, for plugins whose licences predate a rename. */
    private static function license_config_aliases() {
        $config = self::license_config();
        return ( $config && ! empty( $config['plugin_aliases'] ) && is_array( $config['plugin_aliases'] ) )
            ? $config['plugin_aliases']
            : array();
    }

    /** Merge our map into the core admin's view of mapped products. */
    public static function filter_product_map( $map ) {
        return self::product_map() + (array) $map;
    }

    /**
     * The plugin slug this product sells, or '' when it sells none of ours.
     *
     * Deliberately does NOT start by building the full map. The overwhelmingly common
     * case — a product whose URL slug is the plugin slug — costs one cached get_post()
     * and one stat, and it answers correctly the moment a new plugin is deployed,
     * with no cache to expire first. The full map is only consulted for the rest.
     */
    private static function slug_for( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return '';
        }

        // An explicit override must beat the URL-slug guess. Checking for a
        // registered filter first keeps that precedence without paying for the
        // full map on the sites (all of ours) that register none.
        if ( has_filter( 'ssp_changelog_product_map' ) ) {
            $map = self::product_map();
            if ( isset( $map[ $product_id ] ) ) {
                return $map[ $product_id ];
            }
        }

        $post = get_post( $product_id );
        if ( $post && 'product' === $post->post_type && self::ships( $post->post_name ) ) {
            return sanitize_title( $post->post_name );
        }

        $map = self::product_map();
        return isset( $map[ $product_id ] ) ? $map[ $product_id ] : '';
    }

    private static function name_for( $product_id, $slug ) {
        $title = get_the_title( (int) $product_id );
        return $title ? $title : ucwords( str_replace( '-', ' ', $slug ) );
    }

    /* --------------------------------------------------------------------- */
    /* CALLBACK 1 — should a support email be sent for this product?           */
    /* --------------------------------------------------------------------- */

    /**
     * Per-product precondition. Runs ONCE per product before owners are resolved.
     * Returns true only when the plugin's version has advanced past the version
     * we last emailed about. First sight of a plugin records the baseline and
     * returns false (so nobody gets spammed on first deploy).
     *
     * @param bool                $should
     * @param SSE_Support_Context $context  product mode — read $context->product_id
     */
    public static function should_send( $should, $context ) {
        $slug = self::slug_for( isset( $context->product_id ) ? $context->product_id : 0 );
        if ( ! $slug ) {
            // Ticked in the Support Email but absent from the product map: we have no
            // readme to read, so content() cannot fill {changelog} either. Refuse,
            // rather than send a body with our own placeholders left in it.
            return false;
        }

        $current = self::current_version( $slug );
        if ( '' === $current ) {
            return false; // can't read a version → never send
        }

        $opt  = self::VERSION_OPTION . $slug;
        $last = get_option( $opt, '' );

        if ( '' === $last ) {
            // Baseline on first sight, email nobody. NEVER write during preview —
            // filters may run with is_preview = true and must be side-effect-free,
            // or a preview would silently consume the next real release.
            if ( ! $context->is_preview ) {
                update_option( $opt, $current );
            }
            return false;
        }

        return version_compare( $current, $last, '>' );
    }

    /* --------------------------------------------------------------------- */
    /* CALLBACK 2 — what does the email say?                                   */
    /* --------------------------------------------------------------------- */

    /**
     * Fill the changelog placeholders INSIDE the admin's body. The admin writes
     * the body in the Support Email GUI and drops {changelog} (and optionally
     * {changelog_url} / {version}) where they want the update notes to appear; we
     * replace just those tokens with the entries since the version we last emailed
     * about. Returns { body, name }.
     *
     * We never touch the rest of the body. The subject is left to the GUI field
     * (e.g. "Updates available for {product_name}"). {first_name} /
     * {unsubscribe_url} and the other core tokens are filled per-recipient by the
     * engine AFTER this filter runs, so we leave them in place. Any token neither
     * we nor the core recognises is left verbatim.
     *
     * Our own tokens are ALWAYS replaced — even when there are no new entries — so
     * a literal "{changelog}" never ships in an email.
     *
     * @param array               $content  { body, name } defaults from the GUI.
     * @param SSE_Support_Context $context
     */
    public static function content( $content, $context ) {
        $product_id = isset( $context->product_id ) ? (int) $context->product_id : 0;
        $slug = self::slug_for( $product_id );
        if ( ! $slug ) {
            return $content; // not one of ours — leave the body (and its tokens) untouched
        }

        $current       = self::current_version( $slug );
        $changelog_url = self::CHANGELOG_URL . rawurlencode( $slug ) . '/';
        $body          = isset( $content['body'] ) ? (string) $content['body'] : '';
        $subject       = isset( $content['subject'] ) ? (string) $content['subject'] : '';

        // What this reader is missing, counted from THEIR version when the licence
        // telemetry knows it and from our own baseline when it does not. See
        // counting_baseline() for why the fallback matters more than the personalisation.
        $baseline = self::counting_baseline( $context, $slug, $current );
        $missing  = ( '' !== $current ) ? self::releases_since( $slug, $baseline['version'] ) : array();

        $total = 0;
        foreach ( $missing as $release ) {
            $total += count( $release['items'] );
        }

        // The email shows TODAY'S release only, and only the first few items of it.
        // Everything else is a number and a link: the click is the point.
        $latest = ( '' !== $current ) ? self::releases_since( $slug, '' ) : array();
        $latest = $latest ? $latest[0] : null;

        $shown_items   = $latest ? array_slice( $latest['items'], 0, self::MAX_ITEMS ) : array();
        $rest_of_this  = $latest ? count( $latest['items'] ) - count( $shown_items ) : 0;
        // Older releases they have not got. Subtracting today's release avoids
        // counting the same change in both halves of the sentence.
        $rest_missing  = $total - ( $latest ? count( $latest['items'] ) : 0 );
        if ( $rest_missing < 0 ) {
            $rest_missing = 0;
        }

        $as_html = class_exists( 'SSE_Sender' ) && SSE_Sender::looks_like_html( $body );
        $changes = $as_html
            ? self::render_release_html( $latest, $shown_items, $rest_of_this, $rest_missing, $changelog_url )
            : self::render_release_text( $latest, $shown_items, $rest_of_this, $rest_missing, $changelog_url );

        // Never advertise zero. The baseline can already sit at the current version —
        // a preview, or a second Support Email for the same product having advanced it
        // — and "0 changes since we last sent this email" is not a subject line. Today's
        // release is always something to announce, so fall back to counting that.
        if ( $total < 1 && $latest ) {
            $total = count( $latest['items'] );
            $summary = sprintf(
                /* translators: %d: number of changes in the release just published */
                _n( '%d change in this release', '%d changes in this release', $total, 'ssp-changelog-emails' ),
                $total
            );
        } else {
            // The subject phrase has to read correctly in BOTH cases, so it carries its
            // own wording rather than leaving the admin to write a sentence that is only
            // true for the customers we happen to have telemetry for.
            $summary = sprintf(
                $baseline['known']
                    /* translators: %d: number of changes the customer does not have */
                    ? _n( '%d change you do not have yet', '%d changes you do not have yet', $total, 'ssp-changelog-emails' )
                    /* translators: %d: number of changes since the last email */
                    : _n( '%d change since we last sent this email', '%d changes since we last sent this email', $total, 'ssp-changelog-emails' ),
                $total
            );
        }

        // Replace the changelog-specific placeholders IN the admin's body — the
        // wording stays under the admin's control in the Support Email editor.
        // {first_name}/{product_name}/{unsubscribe_url}/etc. are NOT touched here;
        // the core engine fills those afterwards. We always replace our own tokens
        // (even when there are no entries) so a literal {changelog} never ships.
        $find    = array(
            '{changelog}', '{{changelog}}',
            '{changelog_url}', '{{changelog_url}}',
            '{version}', '{{version}}',
            '{changes_count}', '{{changes_count}}',
            '{changes_summary}', '{{changes_summary}}',
        );
        $replace = array(
            $changes, $changes,
            $changelog_url, $changelog_url,
            $current, $current,
            (string) $total, (string) $total,
            $summary, $summary,
        );

        $out = array(
            'body' => str_replace( $find, $replace, $body ),
            'name' => self::name_for( $product_id, $slug ),
        );

        // The subject gets the same treatment, minus {changelog} itself — a list of
        // releases has no business in a subject line, and leaving it out means an
        // admin who drops it there sees it blocked rather than sent mangled.
        if ( '' !== $subject ) {
            $out['subject'] = str_replace(
                array_slice( $find, 2 ),
                array_slice( $replace, 2 ),
                $subject
            );
        }

        return $out;
    }

    /* --------------------------------------------------------------------- */
    /* Which version do we count from?                                        */
    /* --------------------------------------------------------------------- */

    /**
     * The version to count changes from for THIS reader, and whether it is theirs.
     *
     * Personalisation here is a bonus, never a requirement. The version telemetry
     * only exists for customers who entered their licence key and whose sites have
     * checked in since auth-server started recording it, so a large slice of any
     * list has no version at all. Every one of those still needs a true sentence,
     * which is why the fallback (our own "last emailed about" baseline) is the
     * default and the personalised path has to earn its way in:
     *
     *   - No subscriber (the product-level probe used by preview and planning),
     *     no linked WP user, or no telemetry → baseline, known = false.
     *   - Several sites on several versions → their LATEST, so "changes you do not
     *     have" counts only what is missing from every site they run. Understating
     *     is recoverable; telling someone they are missing something they already
     *     have is not.
     *   - Already on the current version → baseline, known = false. They are being
     *     told about a release they have; "0 changes you do not have" is not a
     *     sentence worth sending.
     *
     * @return array{version:string, known:bool}
     */
    private static function counting_baseline( $context, $slug, $current ) {
        $fallback = array(
            'version' => (string) get_option( self::VERSION_OPTION . $slug, '' ),
            'known'   => false,
        );

        if ( ! function_exists( 'ssp_licensing_installed_version' ) ) {
            return $fallback;
        }

        $subscriber = isset( $context->subscriber ) ? $context->subscriber : null;
        $user_id    = ( $subscriber && ! empty( $subscriber->wp_user_id ) ) ? (int) $subscriber->wp_user_id : 0;
        if ( $user_id < 1 ) {
            return $fallback;
        }

        $theirs = ssp_licensing_installed_version( $user_id, $slug, 'latest' );
        if ( '' === $theirs ) {
            return $fallback;
        }
        if ( '' !== $current && version_compare( $theirs, $current, '>=' ) ) {
            return $fallback;
        }

        return array( 'version' => $theirs, 'known' => true );
    }

    /* --------------------------------------------------------------------- */
    /* Rendering the releases                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Rich-HTML body: bold date, then "Version 2.79.0" as the link, deep-linked to
     * that release's own anchor on the changelog page (ssp-changelogs emits them),
     * then the items as bullets. No heading tag — this sits inside the admin's own
     * body copy, so it has to read as part of the sentence around it, not as a
     * document section that inherits some template's h2 styling.
     */
    private static function render_release_html( $release, $items, $rest_of_this, $rest_missing, $changelog_url ) {
        if ( ! $release ) {
            return '';
        }

        $href = $changelog_url . '#' . rawurlencode( $release['anchor'] );
        $out  = '<p style="margin:16px 0 6px;">';
        if ( '' !== $release['date'] ) {
            $out .= '<strong>' . esc_html( $release['date'] ) . '</strong> ';
        }
        $out .= '<a href="' . esc_url( $href ) . '">'
            . esc_html( sprintf( __( 'Version %s', 'ssp-changelog-emails' ), $release['version'] ) )
            . '</a></p>';

        if ( $items ) {
            $out .= '<ul style="margin:0 0 8px;padding-left:20px;">';
            foreach ( $items as $item ) {
                $out .= '<li>' . esc_html( $item ) . '</li>';
            }
            $out .= '</ul>';
        }

        $tail = self::tail_phrase( $rest_of_this, $rest_missing );
        if ( '' !== $tail ) {
            $out .= '<p style="margin:12px 0;">' . esc_html( $tail ) . ' '
                . '<a href="' . esc_url( $changelog_url ) . '">'
                . esc_html__( 'See the full changelog', 'ssp-changelog-emails' ) . '</a>.</p>';
        }

        return $out;
    }

    /** Plain-text body: same shape, same deep link, written out. */
    private static function render_release_text( $release, $items, $rest_of_this, $rest_missing, $changelog_url ) {
        if ( ! $release ) {
            return '';
        }

        $heading = '';
        if ( '' !== $release['date'] ) {
            $heading .= $release['date'] . ' ';
        }
        $heading .= sprintf( __( 'Version %s', 'ssp-changelog-emails' ), $release['version'] );

        $lines   = array( $heading, $changelog_url . '#' . rawurlencode( $release['anchor'] ), '' );
        foreach ( $items as $item ) {
            $lines[] = '- ' . $item;
        }

        $tail = self::tail_phrase( $rest_of_this, $rest_missing );
        if ( '' !== $tail ) {
            $lines[] = '';
            $lines[] = $tail . ' ' . __( 'See the full changelog:', 'ssp-changelog-emails' );
            $lines[] = $changelog_url;
        }

        return rtrim( implode( "\n", $lines ) );
    }

    /**
     * The line that carries the click, in two honest halves: what is left in today's
     * release, and what is waiting in the releases before it. Either half is dropped
     * when its count is zero, and the whole line disappears when the email already
     * shows everything — inventing a tease with nothing behind the link is the one
     * thing that would make this dishonest.
     */
    private static function tail_phrase( $rest_of_this, $rest_missing ) {
        $parts = array();

        if ( $rest_of_this > 0 ) {
            $parts[] = sprintf(
                /* translators: %d: further items in the release just published */
                _n(
                    '%d more item in this release',
                    '%d more items in this release',
                    $rest_of_this,
                    'ssp-changelog-emails'
                ),
                $rest_of_this
            );
        }

        if ( $rest_missing > 0 ) {
            $parts[] = sprintf(
                /* translators: %d: items from earlier releases the reader has not got */
                _n(
                    '%d more item you do not already have',
                    '%d more items you do not already have',
                    $rest_missing,
                    'ssp-changelog-emails'
                ),
                $rest_missing
            );
        }

        if ( ! $parts ) {
            return '';
        }

        /* translators: %s: "3 more items in this release and 19 more items you do not already have" */
        return sprintf( __( 'And %s.', 'ssp-changelog-emails' ), implode( __( ' and ', 'ssp-changelog-emails' ), $parts ) );
    }

    /* --------------------------------------------------------------------- */
    /* CALLBACK 3 — should THIS specific owner receive it? (optional)         */
    /* --------------------------------------------------------------------- */

    /**
     * Per-recipient gate. What it does is the admin's choice, not ours: the record's
     * "Send to" option (declared in register_type as an admin_field) picks between
     * every owner and only those on an active licence.
     *
     * @param bool                $send
     * @param SSE_Support_Context $context  has ->subscriber, ->product_id,
     *                                       ->matching_product, ->all_products
     */
    public static function should_send_to_user( $send, $context ) {
        if ( ! $send ) {
            return false;
        }
        // Older engines have no settings on the context: treat that as "all owners",
        // which is exactly what this plugin did before the option existed.
        $scope = method_exists( $context, 'setting' ) ? $context->setting( 'audience_scope', 'all' ) : 'all';
        if ( 'active_licence' !== $scope ) {
            return true;
        }
        return self::has_active_licence( $context );
    }

    /**
     * Is this person's licence for the product in context still current?
     *
     * Any ONE of these is enough:
     *   - they bought or renewed it within the last year;
     *   - their renewal date is still in the future;
     *   - they hold a lifetime licence (which never expires, and for which
     *     ssp-product-renewals deliberately stores NO renewal date).
     *
     * The renewal date and tier are owner meta on the ownership row, written by
     * ssp-product-renewals when it ingests an order. Super Speedy Emails itself knows
     * nothing about licences; it just carries the meta through on the context, batched
     * once per run.
     *
     * A bundle owner (direct = 0) usually has no licence meta of their own, so they
     * qualify through the one-year limb while their bundle purchase is recent and drop
     * out afterwards. That is the intended reading of "active": we do not have a
     * separate licence record to consult for them.
     */
    private static function has_active_licence( $context ) {
        $row = $context->matching_product;
        if ( ! $row ) {
            // per_user mode, or an ownership row we could not resolve. Nothing to
            // judge them on, so do not silently drop them.
            return true;
        }

        // 1. Bought or renewed within the last year.
        if ( null !== $row->days_since_last && $row->days_since_last <= self::ACTIVE_LICENCE_DAYS ) {
            return true;
        }

        $meta = ( isset( $row->meta ) && is_array( $row->meta ) ) ? $row->meta : array();

        // 2. A lifetime licence never expires.
        if ( isset( $meta['license_tier'] ) && 'lifetime' === $meta['license_tier'] ) {
            return true;
        }

        // 3. A renewal date still in the future. Stored as Y-m-d (UTC) by
        //    ssp-product-renewals; compared as a date, so "expires today" still counts.
        if ( ! empty( $meta['renewal_date'] ) ) {
            $renewal = strtotime( $meta['renewal_date'] . ' 23:59:59 UTC' );
            if ( $renewal && $renewal >= time() ) {
                return true;
            }
        }

        return false;
    }

    /* --------------------------------------------------------------------- */
    /* CALLBACK 4 — advance the stored version after a successful send        */
    /* --------------------------------------------------------------------- */

    /**
     * Fires ONCE per product, after every one of its recipients has been queued
     * (or legitimately skipped). The net effect is "this product's baseline is now
     * the version we just emailed about."
     *
     * Deliberately NOT the per-recipient on_sent callback. Advancing there moved the
     * baseline on recipient one of the run, so a run that died partway through a
     * large audience left everyone it had not reached stranded until the next
     * version bump. Waiting for the fan-out to finish means a mid-run failure just
     * leaves the baseline alone: the next run re-covers the product, and the engine's
     * per-day guard stops anyone already queued being emailed twice.
     *
     * On a pre-1.11.2 engine this is wired to on_sent instead (see register_type),
     * so it may run per recipient there. Writing the same value repeatedly is
     * idempotent — update_option() does not touch the DB when nothing changed — so
     * the method is correct either way; only the stranding protection is lost.
     *
     * @param SSE_Support_Context $context  product-level (no subscriber).
     */
    public static function on_complete( $context ) {
        $slug = self::slug_for( isset( $context->product_id ) ? $context->product_id : 0 );
        if ( ! $slug ) {
            return;
        }
        $current = self::current_version( $slug );
        if ( '' !== $current ) {
            update_option( self::VERSION_OPTION . $slug, $current );
        }
    }

    /* --------------------------------------------------------------------- */
    /* Filesystem readers (the changelog source)                              */
    /* --------------------------------------------------------------------- */

    /** Base dir holding {slug}.json + {slug}/readme.txt. Filterable for dev. */
    public static function assets_dir() {
        $dir = defined( 'SSE_SUPPORT_ASSETS_DIR' ) ? SSE_SUPPORT_ASSETS_DIR : self::LIVE_BASE;
        $dir = apply_filters( 'sse_support_assets_dir', $dir );
        return rtrim( (string) $dir, '/' );
    }

    /**
     * Authoritative current version from {slug}.json (the readme Stable tag is
     * unreliable — some carry "This is beta (x.y.z)"). Read from the local
     * filesystem, never over HTTP.
     */
    public static function current_version( $slug ) {
        // Memoised per request. The content callback runs once per RECIPIENT, so on a
        // 3,000-owner product an unmemoised read is 3,000 stats and 3,000 file reads
        // for a file that cannot change mid-run.
        static $cache = array();
        if ( isset( $cache[ $slug ] ) ) {
            return $cache[ $slug ];
        }

        $version = '';
        $path    = self::assets_dir() . '/' . $slug . '.json';
        if ( is_readable( $path ) ) {
            $data = json_decode( (string) file_get_contents( $path ), true );
            if ( is_array( $data ) && ! empty( $data['version'] ) ) {
                $version = sanitize_text_field( (string) $data['version'] );
            }
        }

        $cache[ $slug ] = $version;
        return $version;
    }

    /**
     * Releases strictly newer than $since_version, newest first, each keeping the
     * version and date from its "= 2.79.0 (25th July 2026) =" header.
     *
     * The header is the point. Flattening every release into one undated bullet list
     * (which this did until 1.4.0) threw away the most persuasive thing in the email:
     * six dated releases in three weeks is the evidence that the plugin is alive and
     * the renewal is worth having.
     *
     * Empty $since_version returns only the topmost (current) release.
     *
     * @return array[] [ { version, date, anchor, items[] } ]
     */
    public static function releases_since( $slug, $since_version ) {
        // Memoised on BOTH arguments, per request. Same reason as current_version():
        // this runs per recipient. Keying on the slug alone would be worse than not
        // caching at all - one dispatch asks for two different $since_version values
        // (today's release, and everything since the reader's own version), so a
        // slug-only cache would hand back the wrong release set.
        static $cache = array();
        $key = $slug . '|' . $since_version;
        if ( isset( $cache[ $key ] ) ) {
            return $cache[ $key ];
        }

        $path = self::assets_dir() . '/' . $slug . '/readme.txt';
        if ( ! is_readable( $path ) ) {
            $cache[ $key ] = array();
            return array();
        }
        $raw = (string) file_get_contents( $path );

        if ( ! preg_match( '/==\s*Changelog\s*==(.*)/is', $raw, $m ) ) {
            $cache[ $key ] = array();
            return array();
        }
        $section = $m[1];

        // Split on "= <version> (...) =" headers: [preamble, v1, body1, v2, body2, ...].
        $blocks = preg_split( '/^=\s*([^\s=][^=]*?)\s*=\s*$/m', $section, -1, PREG_SPLIT_DELIM_CAPTURE );

        $out = array();
        for ( $i = 1; $i < count( $blocks ); $i += 2 ) {
            $header = trim( $blocks[ $i ] );
            $body   = isset( $blocks[ $i + 1 ] ) ? $blocks[ $i + 1 ] : '';

            if ( ! preg_match( '/^([0-9][0-9A-Za-z.\-]*)/', $header, $vm ) ) {
                continue;
            }
            $version = $vm[1];

            if ( '' !== $since_version && version_compare( $version, $since_version, '<=' ) ) {
                break; // reached versions we've already emailed about
            }

            // "2.79.0 (25th July 2026)" — the date is whatever is in the brackets.
            $date = '';
            if ( preg_match( '/\(([^)]+)\)\s*$/', $header, $dm ) ) {
                $date = trim( $dm[1] );
            }

            $out[] = array(
                'version' => $version,
                'date'    => $date,
                'anchor'  => self::anchor( $version ),
                'items'   => self::extract_bullets( $body ),
            );

            if ( '' === $since_version ) {
                break; // baseline: only the topmost block
            }
        }

        $cache[ $key ] = $out;
        return $out;
    }

    /**
     * Flat bullet list, kept for anything still calling the pre-1.4.0 shape.
     *
     * @return string[]
     */
    public static function changelog_since( $slug, $since_version ) {
        $out = array();
        foreach ( self::releases_since( $slug, $since_version ) as $release ) {
            foreach ( $release['items'] as $item ) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Deep-link anchor for a release on the public changelog page: 2.79.0 -> v2-79-0.
     *
     * Mirrors SSP_Changelog_Readme::anchor() in ssp-changelogs, which is the source
     * of truth for the page these links land on. Duplicated rather than called so
     * this plugin stays standalone; if that format changes, change it here too.
     */
    private static function anchor( $version ) {
        $slug = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $version ) );
        return 'v' . trim( $slug, '-' );
    }

    /** Pull "* ..." bullets from a changelog block, stripped to plain text. */
    private static function extract_bullets( $body ) {
        $lines   = preg_split( '/\r\n|\r|\n/', $body );
        $bullets = array();
        $current = '';
        foreach ( $lines as $line ) {
            if ( preg_match( '/^\s*\*\s+(.*)$/', $line, $m ) ) {
                if ( '' !== $current ) { $bullets[] = $current; }
                $current = trim( $m[1] );
            } elseif ( trim( $line ) === '' ) {
                if ( '' !== $current ) { $bullets[] = $current; $current = ''; }
            } else {
                if ( '' !== $current ) { $current .= ' ' . trim( $line ); }
            }
        }
        if ( '' !== $current ) { $bullets[] = $current; }

        return array_map( function ( $b ) {
            $b = preg_replace( '/`([^`]*)`/', '$1', $b );
            $b = preg_replace( '/\*\*([^*]*)\*\*/', '$1', $b );
            $b = str_replace( '*', '', $b );
            return trim( $b );
        }, $bullets );
    }
}

add_action( 'plugins_loaded', array( 'SSP_Changelog_Emails', 'init' ) );

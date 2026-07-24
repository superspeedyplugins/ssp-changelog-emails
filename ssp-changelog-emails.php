<?php
/**
 * Plugin Name: Super Speedy Plugins — Changelog Emails
 * Description: Sends product-update (changelog) emails to plugin owners via Super Speedy Emails. A worked reference implementation of the three support-email filters — read each plugin's readme.txt and email owners when a new version ships. Adapt the product→slug map and the readme location to your own setup.
 * Version:     1.2.0
 * Author:      Super Speedy Plugins
 * Author URI:  https://www.superspeedyplugins.com
 * License:     GPL-2.0-or-later
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
 *   should_send_to_user  (per owner)   — should THIS person get it?
 *   on_sent              — advance the "last emailed version" baseline
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

    /** Cap the changelog lines per email so it stays readable. */
    const MAX_LINES      = 12;

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
        sse_register_support_email_type( 'changelog', array(
            'label'        => __( 'Changelog (product update)', 'ssp-changelog-emails' ),
            'mode'         => 'per_product',
            'stages'       => 1,
            'placeholders' => array(
                '{changelog}'     => __( 'Changelog entries since the last version we emailed about', 'ssp-changelog-emails' ),
                '{changelog_url}' => __( 'Link to the full changelog page for the product', 'ssp-changelog-emails' ),
                '{version}'       => __( 'The current version', 'ssp-changelog-emails' ),
            ),
            'footer'       => __( "You're receiving this because you own {product_name}.", 'ssp-changelog-emails' ),
            'callbacks'    => array(
                'should_send'         => array( __CLASS__, 'should_send' ),
                'should_send_to_user' => array( __CLASS__, 'should_send_to_user' ),
                'content'             => array( __CLASS__, 'content' ),
                'on_sent'             => array( __CLASS__, 'on_sent' ),
            ),
        ) );
    }

    /* --------------------------------------------------------------------- */
    /* Product → plugin-slug map                                              */
    /* --------------------------------------------------------------------- */

    /**
     * WooCommerce product_id => plugin slug, for the individual plugins (NOT
     * bundles or services — bundle owners receive each component plugin's email
     * via the owner-cache bundle expansion). Each slug has a readme.txt +
     * {slug}.json under the assets dir.
     *
     * EDIT THIS for your own products, or override via the sse_changelog_product_map
     * filter from elsewhere.
     */
    public static function product_map() {
        $default = array(
            6018    => 'scalability-pro',
            24899   => 'super-speedy-filters',
            10510   => 'super-speedy-search',
            1689897 => 'super-speedy-imports',
            6689    => 'external-images',
            24853   => 'auto-infinite-scroll',
            1687502 => 'super-speedy-sitemaps',
            1692164 => 'super-speedy-chat',
            1692165 => 'super-speedy-ajax-prices',
        );
        $clean = array();
        foreach ( (array) $default as $pid => $slug ) {
            $clean[ (int) $pid ] = sanitize_title( $slug );
        }
        return $clean;
    }

    /** Merge our map into the core admin's view of mapped products. */
    public static function filter_product_map( $map ) {
        return self::product_map() + (array) $map;
    }

    private static function slug_for( $product_id ) {
        $map = self::product_map();
        return isset( $map[ (int) $product_id ] ) ? $map[ (int) $product_id ] : '';
    }

    private static function name_for( $product_id, $slug ) {
        $title = get_the_title( (int) $product_id );
        return $title ? $title : ucwords( str_replace( '-', ' ', $slug ) );
    }

    /* --------------------------------------------------------------------- */
    /* FILTER 1 — should a support email be sent for this product?            */
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
            return $should; // not one of ours — leave any other implementation's decision
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
    /* FILTER 2 — what does the email say?                                     */
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

        $current = self::current_version( $slug );
        $last    = get_option( self::VERSION_OPTION . $slug, '' );
        $entries = ( '' !== $current ) ? self::changelog_since( $slug, $last ) : array();

        // Cap + format the changelog bullets.
        $shown = 0;
        $lines = array();
        foreach ( $entries as $entry ) {
            $lines[] = '- ' . $entry;
            if ( ++$shown >= self::MAX_LINES ) {
                break;
            }
        }
        if ( count( $entries ) > $shown ) {
            $lines[] = '';
            $lines[] = '...and more — see the full changelog below.';
        }
        $changes       = implode( "\n", $lines );
        $changelog_url = self::CHANGELOG_URL . rawurlencode( $slug ) . '/';

        // Replace the changelog-specific placeholders IN the admin's body — the
        // wording stays under the admin's control in the Support Email editor.
        // {first_name}/{product_name}/{unsubscribe_url}/etc. are NOT touched here;
        // the core engine fills those afterwards. We always replace our own tokens
        // (even when there are no entries) so a literal {changelog} never ships.
        $body = isset( $content['body'] ) ? (string) $content['body'] : '';
        $body = str_replace(
            array( '{changelog}', '{{changelog}}', '{changelog_url}', '{{changelog_url}}', '{version}', '{{version}}' ),
            array( $changes,      $changes,        $changelog_url,     $changelog_url,       $current,    $current ),
            $body
        );

        return array(
            'body' => $body,
            'name' => self::name_for( $product_id, $slug ),
        );
    }

    /* --------------------------------------------------------------------- */
    /* FILTER 3 — should THIS specific owner receive it? (optional)           */
    /* --------------------------------------------------------------------- */

    /**
     * Per-recipient gate. Default: everyone who owns the product and isn't
     * suppressed gets it (we just return $send unchanged). This is where you'd
     * add per-customer policy — e.g. active-licence-only, a region, recency.
     * Left as a pass-through here, included to show the hook + its context.
     *
     * @param bool                $send
     * @param SSE_Support_Context $context  has ->subscriber, ->product_id,
     *                                       ->matching_product, ->all_products
     */
    public static function should_send_to_user( $send, $context ) {
        // Example you could enable:
        //   if ( $context->is_product_mode()
        //        && $context->matching_product
        //        && ! $context->matching_product->direct ) {
        //       return false; // skip bundle-only owners for changelog emails
        //   }
        return $send;
    }

    /* --------------------------------------------------------------------- */
    /* ACTION — advance the stored version after a successful send            */
    /* --------------------------------------------------------------------- */

    /**
     * Fires once per queued email. Advancing to the same value repeatedly is
     * idempotent; the net effect is "this product's baseline is now the version
     * we just emailed about." Done here (after a successful queue) rather than in
     * should_send so a mid-run failure can't skip a release.
     *
     * @param SSE_Support_Context $context
     */
    public static function on_sent( $context ) {
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
        $path = self::assets_dir() . '/' . $slug . '.json';
        if ( ! is_readable( $path ) ) {
            return '';
        }
        $data = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $data ) || empty( $data['version'] ) ) {
            return '';
        }
        return sanitize_text_field( (string) $data['version'] );
    }

    /**
     * Changelog bullet lines for every version strictly newer than $since_version.
     * Empty $since_version returns only the topmost (current) version's lines.
     *
     * @return string[]
     */
    public static function changelog_since( $slug, $since_version ) {
        $path = self::assets_dir() . '/' . $slug . '/readme.txt';
        if ( ! is_readable( $path ) ) {
            return array();
        }
        $raw = (string) file_get_contents( $path );

        if ( ! preg_match( '/==\s*Changelog\s*==(.*)/is', $raw, $m ) ) {
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

            foreach ( self::extract_bullets( $body ) as $bullet ) {
                $out[] = $bullet;
            }

            if ( '' === $since_version ) {
                break; // baseline: only the topmost block
            }
        }
        return $out;
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

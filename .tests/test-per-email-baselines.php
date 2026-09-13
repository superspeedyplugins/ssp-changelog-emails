<?php
require __DIR__."/fleet/bootstrap.php";
/**
 * Integration coverage for independent changelog baselines per support email.
 *
 * Run from a WordPress installation with the plugin active:
 *   wp eval-file wp-content/plugins/ssp-changelog-emails/.tests/test-per-email-baselines.php
 */

if ( ! class_exists( 'SSP_Changelog_Emails' ) ) {
    fwrite( STDERR, "FAIL: SSP_Changelog_Emails is not loaded.\n" );
    exit( 1 );
}

if ( ! defined( 'SSE_SUPPORT_ASSETS_DIR' ) ) {
    define( 'SSE_SUPPORT_ASSETS_DIR', __DIR__ . '/fixtures/assets' );
}

class SSP_Changelog_Baseline_Test_Context {
    public $support_email_id;
    public $product_id;
    public $is_preview;

    private $settings;

    public function __construct( $support_email_id, $product_id, $is_preview, $settings = array() ) {
        $this->support_email_id = (int) $support_email_id;
        $this->product_id       = (int) $product_id;
        $this->is_preview       = (bool) $is_preview;
        $this->settings         = $settings;
    }

    public function setting( $key, $default = null ) {
        return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $default;
    }
}

add_filter('sse_support_assets_dir',fn()=>__DIR__.'/fixtures/assets',1000);
$slug    = 'changelog-baseline-fixture';
$product = get_page_by_path( $slug, OBJECT, 'product' );
$product_id = $product ? (int) $product->ID : 0;
if ( ! $product_id ) {
    $product_id = wp_insert_post( array(
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_title'  => 'Changelog Baseline Fixture',
        'post_name'   => $slug,
    ) );
}
if ( ! $product_id || is_wp_error( $product_id ) ) {
    fwrite( STDERR, "FAIL: could not create the fixture product.\n" );
    exit( 1 );
}

$legacy_option = 'sse_support_last_sent_version_' . $slug;
$send_now_id   = 9000001;
$behind_id     = 9000002;
$wait_id       = 9000003;
$send_now_opt  = $legacy_option . '_' . $send_now_id;
$behind_opt    = $legacy_option . '_' . $behind_id;
$wait_opt      = $legacy_option . '_' . $wait_id;

// Reset the predictable fixture state on the way in. The live failure has a shared
// baseline at the current version and no per-email baseline for the new email.
update_option( $legacy_option, '2.0.0' );
delete_option( $send_now_opt );
update_option( $behind_opt, '1.9.0' );
delete_option( $wait_opt );

$send_now = new SSP_Changelog_Baseline_Test_Context(
    $send_now_id,
    $product_id,
    true,
    array( 'send_current_on_first_run' => true )
);
if ( true !== SSP_Changelog_Emails::should_send( true, $send_now ) ) {
    fwrite( STDERR, "FAIL: a new support email configured to send the current version inherited the shared plugin baseline and reported no update.\n" );
    exit( 1 );
}

// The editor previews an unsaved new email with ID 0. The explicit first-run
// choice must still make that preview show the current release as pending.
$unsaved_preview = new SSP_Changelog_Baseline_Test_Context(
    0,
    $product_id,
    true,
    array( 'send_current_on_first_run' => true )
);
if ( true !== SSP_Changelog_Emails::should_send( true, $unsaved_preview ) ) {
    fwrite( STDERR, "FAIL: an unsaved new email ignored its send-current first-run choice during preview.\n" );
    exit( 1 );
}

// One email's own baseline must decide only that email's eligibility.
$behind = new SSP_Changelog_Baseline_Test_Context( $behind_id, $product_id, true );
if ( true !== SSP_Changelog_Emails::should_send( true, $behind ) ) {
    fwrite( STDERR, "FAIL: the email with its own older baseline did not see the update.\n" );
    exit( 1 );
}

// The safe first-run default waits for the next update, but must establish this
// email's own key rather than continuing to depend on the shared legacy option.
$wait = new SSP_Changelog_Baseline_Test_Context( $wait_id, $product_id, false );
if ( false !== SSP_Changelog_Emails::should_send( true, $wait ) ) {
    fwrite( STDERR, "FAIL: the safe first-run default attempted to send immediately.\n" );
    exit( 1 );
}
if ( '2.0.0' !== get_option( $wait_opt, '' ) ) {
    fwrite( STDERR, "FAIL: the safe first run did not create the support email's own plugin baseline.\n" );
    exit( 1 );
}

// Completing one email records only that email + plugin pair. It must not consume
// the same release for another support email.
SSP_Changelog_Emails::on_complete( $send_now );
if ( '2.0.0' !== get_option( $send_now_opt, '' ) ) {
    fwrite( STDERR, "FAIL: completion did not record the sending email's baseline.\n" );
    exit( 1 );
}
if ( '1.9.0' !== get_option( $behind_opt, '' ) ) {
    fwrite( STDERR, "FAIL: completing one support email changed another email's baseline.\n" );
    exit( 1 );
}
if ( false !== SSP_Changelog_Emails::should_send( true, $send_now ) ) {
    fwrite( STDERR, "FAIL: the completed support email would send the same version twice.\n" );
    exit( 1 );
}
if ( true !== SSP_Changelog_Emails::should_send( true, $behind ) ) {
    fwrite( STDERR, "FAIL: one email's completion consumed the release for another email.\n" );
    exit( 1 );
}

echo "PASS: support emails keep independent per-plugin changelog baselines\n";

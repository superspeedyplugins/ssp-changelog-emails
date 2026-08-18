<?php
/**
 * Integration coverage for the combined expired-over-six-months audience.
 *
 * Run from a WordPress installation with the plugin active:
 *   wp eval-file wp-content/plugins/ssp-changelog-emails/.tests/test-expired-over-six-months.php
 */

if ( ! class_exists( 'SSP_Changelog_Emails' ) ) {
    fwrite( STDERR, "FAIL: SSP_Changelog_Emails is not loaded.\n" );
    exit( 1 );
}

class SSP_Changelog_Expiry_Test_Context {
    public $matching_product;
    public $matching_products;
    public $all_products;
    public $product_id = 6018;

    private $audience_scope;

    public function __construct( $rows, $audience_scope = 'expired_over_6_months' ) {
        $this->matching_products = array_values( (array) $rows );
        $this->all_products      = $this->matching_products;
        $this->matching_product  = isset( $this->matching_products[0] ) ? $this->matching_products[0] : null;
        $this->audience_scope    = $audience_scope;
    }

    public function setting( $key, $default = null ) {
        return 'audience_scope' === $key ? $this->audience_scope : $default;
    }
}

function ssp_changelog_test_owner_row( $renewal_date, $days_since_last = 500, $license_tier = 'yearly', $direct = true ) {
    return (object) array(
        'owner_id'          => wp_rand( 1, PHP_INT_MAX ),
        'product_id'        => 6018,
        'direct'            => $direct,
        'days_since_last'   => $days_since_last,
        'last_purchased_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) ),
        'meta'              => array(
            'renewal_date' => $renewal_date,
            'license_tier' => $license_tier,
        ),
    );
}

$cases = array(
    'five months ago is excluded'     => array( '-5 months', false, 500, 'yearly' ),
    'six months ago is included'      => array( '-6 months', true,  500, 'yearly' ),
    'seven months ago is included'    => array( '-7 months', true,  500, 'yearly' ),
    'thirteen months ago is included' => array( '-13 months', true, 500, 'yearly' ),
    'future renewal is excluded'      => array( '+1 month', false, 500, 'yearly' ),
    'lifetime licence is excluded'    => array( '-13 months', false, 500, 'lifetime' ),
);

foreach ( $cases as $label => $case ) {
    list( $relative_date, $expected, $days_since_last, $license_tier ) = $case;
    $context = new SSP_Changelog_Expiry_Test_Context( array(
        ssp_changelog_test_owner_row(
            gmdate( 'Y-m-d', strtotime( $relative_date ) ),
            $days_since_last,
            $license_tier
        ),
    ) );
    $actual = SSP_Changelog_Emails::should_send_to_user( true, $context );
    if ( $expected !== $actual ) {
        fwrite( STDERR, sprintf( "FAIL: %s. Expected %s, got %s.\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
        exit( 1 );
    }
}

$exact_boundary = ssp_changelog_test_owner_row(
    gmdate( 'Y-m-d', strtotime( '-6 months' ) ),
    500,
    'yearly'
);
$context = new SSP_Changelog_Expiry_Test_Context(
    array( $exact_boundary ),
    'expired_within_6_months'
);
if ( false !== SSP_Changelog_Emails::should_send_to_user( true, $context ) ) {
    fwrite( STDERR, "FAIL: exact six-month boundary overlapped the within-six and six-plus audiences.\n" );
    exit( 1 );
}

// Expired audiences are defined by the stored renewal/expiry date. An old
// purchase with no renewal date is unknown, not proof that the licence expired.
$undated = ssp_changelog_test_owner_row( '', 1095, 'yearly', true );
$context = new SSP_Changelog_Expiry_Test_Context(
    array( $undated ),
    'expired_over_6_months'
);
$actual = SSP_Changelog_Emails::should_send_to_user( true, $context );
if ( false !== $actual ) {
    fwrite( STDERR, "FAIL: undated ownership was inferred expired from its purchase age.\n" );
    exit( 1 );
}

// A subscriber may own the same product directly and through a bundle. The engine
// exposes every row in matching_products, but matching_product is whichever row its
// unordered query returned first. An active row must win regardless of that order.
$old_bundle = ssp_changelog_test_owner_row(
    gmdate( 'Y-m-d', strtotime( '-2 years' ) ),
    1095,
    'yearly',
    false
);
$active_direct = ssp_changelog_test_owner_row(
    gmdate( 'Y-m-d', strtotime( '+11 months' ) ),
    10,
    'yearly',
    true
);
$context = new SSP_Changelog_Expiry_Test_Context(
    array( $old_bundle, $active_direct ),
    'expired_over_6_months'
);
$actual = SSP_Changelog_Emails::should_send_to_user( true, $context );
if ( false !== $actual ) {
    fwrite( STDERR, "FAIL: active direct owner was included in expired-over-six audience because the older bundle row was selected first.\n" );
    exit( 1 );
}

$context = new SSP_Changelog_Expiry_Test_Context(
    array( $old_bundle, $active_direct ),
    'active_licence'
);
$actual = SSP_Changelog_Emails::should_send_to_user( true, $context );
if ( true !== $actual ) {
    fwrite( STDERR, "FAIL: active direct owner was excluded from the active audience because the older bundle row was selected first.\n" );
    exit( 1 );
}

// When every row is expired, the most recent expiry decides the age band. An old
// bundle row must not drag a more recently expired direct licence into over-six.
$recent_direct = ssp_changelog_test_owner_row(
    gmdate( 'Y-m-d', strtotime( '-2 months' ) ),
    500,
    'yearly',
    true
);
$context = new SSP_Changelog_Expiry_Test_Context(
    array( $old_bundle, $recent_direct ),
    'expired_over_6_months'
);
$actual = SSP_Changelog_Emails::should_send_to_user( true, $context );
if ( false !== $actual ) {
    fwrite( STDERR, "FAIL: recently expired direct owner was included in expired-over-six audience because the older bundle row was selected first.\n" );
    exit( 1 );
}

$context = new SSP_Changelog_Expiry_Test_Context(
    array( $old_bundle, $recent_direct ),
    'expired_within_6_months'
);
$actual = SSP_Changelog_Emails::should_send_to_user( true, $context );
if ( true !== $actual ) {
    fwrite( STDERR, "FAIL: most recent expiry did not qualify for the within-six-months audience.\n" );
    exit( 1 );
}

echo "PASS: expired licence audience boundaries and duplicate ownership precedence\n";

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

    public function __construct( $renewal_date, $days_since_last = 500, $license_tier = 'yearly' ) {
        $this->matching_product = (object) array(
            'days_since_last'  => $days_since_last,
            'last_purchased_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) ),
            'meta'             => array(
                'renewal_date' => $renewal_date,
                'license_tier' => $license_tier,
            ),
        );
    }

    public function setting( $key, $default = null ) {
        return 'audience_scope' === $key ? 'expired_over_6_months' : $default;
    }
}

$cases = array(
    'five months ago is excluded'     => array( '-5 months', false, 500, 'yearly' ),
    'seven months ago is included'    => array( '-7 months', true,  500, 'yearly' ),
    'thirteen months ago is included' => array( '-13 months', true, 500, 'yearly' ),
    'future renewal is excluded'      => array( '+1 month', false,  500, 'yearly' ),
    'lifetime licence is excluded'    => array( '-13 months', false, 500, 'lifetime' ),
);

foreach ( $cases as $label => $case ) {
    list( $relative_date, $expected, $days_since_last, $license_tier ) = $case;
    $context = new SSP_Changelog_Expiry_Test_Context(
        gmdate( 'Y-m-d', strtotime( $relative_date ) ),
        $days_since_last,
        $license_tier
    );
    $actual = SSP_Changelog_Emails::should_send_to_user( true, $context );
    if ( $expected !== $actual ) {
        fwrite( STDERR, sprintf( "FAIL: %s. Expected %s, got %s.\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
        exit( 1 );
    }
}

echo "PASS: expired-over-six-months audience boundaries\n";

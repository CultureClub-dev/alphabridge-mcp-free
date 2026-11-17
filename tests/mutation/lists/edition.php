<?php
/**
 * The clauses of the fourth edition value, 'trial' (a trial of AlphaBridge
 * MCP Pro without an active valid licence, ab_mcp_site_edition): the
 * whitelist of the self-description (AB_MCP_REST_Controller::site_meta())
 * and the four places that compare the edition, the box about Pro and the
 * box «On: full power» (AB_MCP_Admin), the refusals of the option guard
 * (AB_MCP_Option_Guard::edition_has()) and the moments of the takeover
 * (AB_MCP_Transfer_Moments::possible()). Per place two entries: one takes
 * 'trial' out of the comparison, one turns the value of the filter into a
 * string again, as before, instead of checking it.
 *
 * AB_MCP_Site_Mode (#156-B1): terms_required() and the record of
 * switch_to_full() check the value as well instead of turning it into a
 * string; one entry each turns it into a string again. 'trial' has no entry
 * there: terms_required() never let it through, a trial stays required as
 * the free edition does, and the record keeps any string as it comes.
 *
 * Held by the unit suite (EditionTrialTest).
 *
 * @package AlphaBridge_MCP
 */

$unit = array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'EditionTrialTest' );

$rc = 'includes/class-rest-controller.php';
$ad = 'includes/class-admin.php';
$og = 'includes/class-option-guard.php';
$mo = 'includes/class-transfer-moments.php';
$sm = 'includes/class-site-mode.php';

return array(
	array(
		'id'      => 'edition/meta-trial',
		'clause'  => 'site_meta(): the whitelist lets trial through',
		'file'    => $rc,
		'search'  => "in_array( \$edition, array( 'free', 'pro', 'agency', 'trial' ), true )",
		'replace' => "in_array( \$edition, array( 'free', 'pro', 'agency' ), true )",
		'run'     => $unit,
		'expect'  => '/testTheSelfDescriptionReportsATrial/',
	),
	array(
		'id'      => 'edition/meta-string',
		'clause'  => 'site_meta(): a value that is not a string is free',
		'file'    => $rc,
		'search'  => "\t\t\$edition = apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\t// Fail closed",
		'replace' => "\t\t\$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\t// Fail closed",
		'run'     => $unit,
		'expect'  => '/testAnEditionThatIsNotAStringIsFreeInTheSelfDescription/',
	),
	array(
		'id'      => 'edition/pro-box-trial',
		'clause'  => 'pro_box_html(): no box for a trial',
		'file'    => $ad,
		'search'  => "\t\tif ( is_string( \$edition ) && in_array( \$edition, array( 'pro', 'agency', 'trial' ), true ) ) {",
		'replace' => "\t\tif ( is_string( \$edition ) && in_array( \$edition, array( 'pro', 'agency' ), true ) ) {",
		'run'     => $unit,
		'expect'  => '/testATrialIsNotOfferedTheTrialItRuns/',
	),
	array(
		'id'      => 'edition/pro-box-string',
		'clause'  => 'pro_box_html(): a value that is not a string is offered Pro',
		'file'    => $ad,
		'search'  => "\t\t\$edition = apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\tif ( is_string( \$edition )",
		'replace' => "\t\t\$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\tif ( is_string( \$edition )",
		'run'     => $unit,
		'expect'  => '/testAnEditionThatIsNotAStringIsOfferedPro/',
	),
	array(
		'id'      => 'edition/mode-card-trial',
		'clause'  => 'mode_card_html(): a trial reads the sentence of Pro',
		'file'    => $ad,
		'search'  => "\$writes  = is_string( \$edition ) && in_array( \$edition, array( 'pro', 'agency', 'trial' ), true )",
		'replace' => "\$writes  = is_string( \$edition ) && in_array( \$edition, array( 'pro', 'agency' ), true )",
		'run'     => $unit,
		'expect'  => '/testATrialReadsWhatProWrites/',
	),
	array(
		'id'      => 'edition/mode-card-string',
		'clause'  => 'mode_card_html(): a value that is not a string reads the sentence of the free edition',
		'file'    => $ad,
		'search'  => "\t\t\$edition = apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\t\$writes",
		'replace' => "\t\t\$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\t\$writes",
		'run'     => $unit,
		'expect'  => '/testAnEditionThatIsNotAStringReadsWhatTheFreeEditionWrites/',
	),
	array(
		'id'      => 'edition/guard-trial',
		'clause'  => 'edition_has(): a trial has the tools of Pro',
		'file'    => $og,
		'search'  => "\t\treturn in_array( \$edition, array( 'pro', 'agency', 'trial' ), true );",
		'replace' => "\t\treturn in_array( \$edition, array( 'pro', 'agency' ), true );",
		'run'     => $unit,
		'expect'  => '/testOnATrialTheRefusalsNameTheToolsOfPro/',
	),
	array(
		'id'      => 'edition/guard-string',
		'clause'  => 'edition_has(): a value that is not a string has no tool of Pro or Agency',
		'file'    => $og,
		'search'  => "? apply_filters( 'ab_mcp_site_edition', 'free' ) : 'free';",
		'replace' => "? (string) apply_filters( 'ab_mcp_site_edition', 'free' ) : 'free';",
		'run'     => $unit,
		'expect'  => '/testAnEditionThatIsNotAStringHasNoToolOfProOrAgency/',
	),
	array(
		'id'      => 'edition/moments-trial',
		'clause'  => 'possible(): no edition part for a trial',
		'file'    => $mo,
		'search'  => "in_array( \$edition, array( 'pro', 'agency', 'trial' ), true ) ) && ",
		'replace' => "in_array( \$edition, array( 'pro', 'agency' ), true ) ) && ",
		'run'     => $unit,
		'expect'  => '/testOnATrialNoEditionPartIsPossible/',
	),
	array(
		'id'      => 'edition/moments-string',
		'clause'  => 'possible(): a value that is not a string is the free edition',
		'file'    => $mo,
		'search'  => "\t\t\$edition = apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\treturn ! ( is_string",
		'replace' => "\t\t\$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\treturn ! ( is_string",
		'run'     => $unit,
		'expect'  => '/testAnEditionThatIsNotAStringIsTheFreeOneForTheMoments/',
	),
	array(
		'id'      => 'edition/terms-string',
		'clause'  => 'terms_required(): a value that is not a string must agree to the terms',
		'file'    => $sm,
		'search'  => "\t\t\$edition = apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\treturn ! ( is_string",
		'replace' => "\t\t\$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\treturn ! ( is_string",
		'run'     => $unit,
		'expect'  => '/testAnEditionThatIsNotAStringMustAgreeToTheTerms/',
	),
	array(
		'id'      => 'edition/record-string',
		'clause'  => 'switch_to_full(): the record of a value that is not a string holds free',
		'file'    => $sm,
		'search'  => "\t\t\$edition = apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\t\$record  = array(",
		'replace' => "\t\t\$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );\n\t\t\$record  = array(",
		'run'     => $unit,
		'expect'  => '/testAnEditionThatIsNotAStringIsRecordedAsTheFreeOne/',
	),
);

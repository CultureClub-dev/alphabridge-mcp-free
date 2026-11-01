<?php
/**
 * The clauses of AB_MCP_Option_Guard::refusal() and ::lifted(), the one
 * place both questions about an option name are made of
 * (includes/class-option-guard.php). Held by the unit suite.
 *
 * @package AlphaBridge_MCP
 */

$unit = array( 'vendor/bin/phpunit', '--no-progress', '--filter', '/OptionGuardStageTwoTest|FreeSinceFourTenTest/' );

return array(
	array(
		'id'      => 'option-guard/refusal-length',
		'clause'  => 'refusal(): a name longer than the option_name column is refused as too_long',
		'file'    => 'includes/class-option-guard.php',
		'search'  => "if ( self::too_long_for_column( \$name ) ) {\n\t\t\treturn 'too_long';",
		'replace' => "if ( false && self::too_long_for_column( \$name ) ) {\n\t\t\treturn 'too_long';",
		'run'     => $unit,
		'expect'  => '/testTheSecondQuestionAsksTheLengthTheInvisibleCharactersAndTheFoldAsTheFirst/',
	),
	array(
		'id'      => 'option-guard/refusal-invisible',
		'clause'  => 'refusal(): a name with a control or format character is refused as invalid',
		'file'    => 'includes/class-option-guard.php',
		'search'  => "if ( self::has_invisible_chars( \$name ) ) {\n\t\t\treturn 'invalid';",
		'replace' => "if ( false && self::has_invisible_chars( \$name ) ) {\n\t\t\treturn 'invalid';",
		'run'     => $unit,
		'expect'  => '/testTheSecondQuestionAsksTheLengthTheInvisibleCharactersAndTheFoldAsTheFirst/',
	),
	array(
		'id'      => 'option-guard/refusal-fold',
		'clause'  => 'refusal(): a name that only folds onto a protected one is refused',
		'file'    => 'includes/class-option-guard.php',
		'search'  => 'if ( self::listed( $name, $question ) || self::folds_onto_listed( $name, $question ) ) {',
		'replace' => 'if ( self::listed( $name, $question ) ) {',
		'run'     => $unit,
		'expect'  => '/testAFoldOntoAProtectedNameWithoutARowIsRefusedUnderBothQuestions/',
	),
	array(
		'id'      => 'option-guard/lifted-question',
		'clause'  => 'lifted(): only the confirmed question lifts the registration pair off the list',
		'file'    => 'includes/class-option-guard.php',
		'search'  => 'return self::QUESTION_CONFIRMED === $question && in_array( $name, self::CONFIRMED, true );',
		'replace' => 'return in_array( $name, self::CONFIRMED, true );',
		'run'     => $unit,
		'expect'  => '/testTheFirstQuestionStillRefusesThePairAndTheSecondLetsExactlyThePairThrough/',
	),
);

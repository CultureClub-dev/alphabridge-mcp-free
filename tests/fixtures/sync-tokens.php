<?php
/**
 * Credentials in the forms of their services for the tests of branch 11b
 * (the steering, 09.11.2026), composed at run time from parts and from
 * hashes of fixed seeds: no file holds a token whole, so the push
 * protection of GitHub (GH013) has nothing to report and is never asked to
 * let one through. None of them is or was ever valid anywhere.
 *
 * Returns name => text: ghp, github_pat, sk_live, rk_live, akia, xoxb, xoxp,
 * mailchimp, jwt, cookie_tail (expiration|token|hmac of a login cookie),
 * pem_body (lines of base64), hex32; examples, the publicly documented
 * examples of AB_MCP_Sync_Net::EXAMPLES by name (the two tokens of jwt.io
 * signed anew with their documented keys); and as closures alnum( seed, n ),
 * hex( seed, n ), b64url( text ), pem( label, body ).
 *
 * @package AlphaBridge_MCP
 */

$alnum  = static function ( string $seed, int $n ): string {
	$set = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	$out = '';
	for ( $i = 0; strlen( $out ) < $n; $i++ ) {
		foreach ( str_split( hash( 'sha256', $seed . $i, true ) ) as $byte ) {
			if ( strlen( $out ) < $n ) {
				$out .= $set[ ord( $byte ) % 62 ];
			}
		}
	}
	return $out;
};
$hex    = static fn( string $seed, int $n ): string => substr( str_repeat( hash( 'sha256', $seed ), (int) ceil( $n / 64 ) ), 0, $n );
$b64url = static fn( string $text ): string => rtrim( strtr( base64_encode( $text ), '+/', '-_' ), '=' );
$caps   = static fn( string $seed, int $n ): string => strtoupper( substr( $hex( $seed, 64 ) . $alnum( $seed, 64 ), 0, $n ) );
$pem    = static fn( string $label, string $body ): string => '-----BEGIN ' . $label . 'PRIVATE' . ' KEY-----' . "\n" . $body . '-----END ' . $label . 'PRIVATE' . ' KEY-----';

$jwtio  = static function ( string $payload, string $key ) use ( $b64url ): string {
	$head = $b64url( '{"alg":"HS256","typ":"JWT"}' ) . '.' . $b64url( $payload );
	return $head . '.' . $b64url( hash_hmac( 'sha256', $head, $key, true ) );
};

return array(
	'examples'    => array(
		'aws access key id'     => 'AK' . 'IA' . 'IOSFODNN7' . 'EXAMPLE',
		'aws secret access key' => 'wJalrXUtnFEMI/K7MDENG/' . 'bPxRfiCY' . 'EXAMPLEKEY',
		'jwt.io'                => $jwtio( '{"sub":"1234567890","name":"John Doe","iat":1516239022}', 'your-256-bit-secret' ),
		'jwt.io admin'          => $jwtio( '{"sub":"1234567890","name":"John Doe","admin":true,"iat":1516239022}', 'a-string-secret-at-least-256-bits-long' ),
		'rfc 6750'              => 'mF_9.' . 'B5f-4.1JqM',
		'slack 1'               => 'xo' . 'xp-111-222-333-' . 'd6bc76',
		'slack 2'               => 'xo' . 'xp-111-222-333-' . 'd6bc768412',
		'slack 3'               => 'xo' . 'xp-111-222-333-' . 'd6bc768406e5c2e6958cfc399b438004',
	),
	'ghp'         => 'gh' . 'p_' . $alnum( 'ghp', 36 ),
	'github_pat'  => 'github' . '_pat_' . $alnum( 'pat1', 22 ) . '_' . $alnum( 'pat2', 59 ),
	'sk_live'     => 'sk' . '_live_' . $alnum( 'sk', 24 ),
	'rk_live'     => 'rk' . '_live_' . $alnum( 'rk', 24 ),
	'akia'        => 'AK' . 'IA' . $caps( 'akia', 16 ),
	'xoxb'        => 'xo' . 'xb-' . '2048276413-4096551828-' . $alnum( 'xoxb', 24 ),
	'xoxp'        => 'xo' . 'xp-' . '2048276413-4096551828-7065432198-' . $hex( 'xoxp', 32 ),
	'mailchimp'   => $hex( 'mc', 32 ) . '-us' . '21',
	'jwt'         => $b64url( '{"alg":"HS256","typ":"JWT"}' ) . '.' . $b64url( '{"sub":"4242","iat":1762698000}' ) . '.' . $b64url( hash( 'sha256', 'sig', true ) ),
	'cookie_tail' => '1762790400|' . $alnum( 'tok', 43 ) . '|' . $hex( 'hmac', 64 ),
	'pem_body'    => chunk_split( base64_encode( str_repeat( hash( 'sha256', 'pem', true ), 6 ) ), 64, "\n" ),
	'hex32'       => $hex( 'h32', 32 ),
	'alnum'       => $alnum,
	'hex'         => $hex,
	'b64url'      => $b64url,
	'pem'         => $pem,
);

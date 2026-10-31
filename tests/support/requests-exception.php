<?php
/**
 * Stand-in for WpOrg\Requests\Exception, the exception of the Requests
 * library inside WordPress: a hook that throws it ends the request, and
 * WP_Http::request() turns it into a WP_Error with its message. Same
 * constructor as in WordPress 7.0.2 (wp-includes/Requests/src/Exception.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace WpOrg\Requests;

if ( ! class_exists( Exception::class, false ) ) {
	class Exception extends \Exception {
		/** @var string */
		protected $type;
		/** @var mixed */
		protected $data;

		public function __construct( $message, $type, $data = null, $code = 0 ) {
			parent::__construct( (string) $message, (int) $code );
			$this->type = (string) $type;
			$this->data = $data;
		}

		public function getType() {
			return $this->type;
		}
	}
}

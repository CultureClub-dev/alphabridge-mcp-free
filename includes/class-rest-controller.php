<?php
/**
 * REST controller carrying the MCP protocol (JSON-RPC 2.0 over Streamable HTTP).
 *
 * Endpoint: POST /wp-json/alphabridge/v1/mcp
 *
 * Implements: initialize, ping, tools/list, tools/call, resources/list,
 * prompts/list and notifications/*. Responds with application/json (single or
 * batch). SSE streaming is intentionally not used: JSON-RPC over HTTP POST is
 * the widely-supported MCP transport and is far more stable behind shared
 * hosting. Clients that require a server-initiated SSE stream are not supported.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_REST_Controller
 */
class AB_MCP_REST_Controller {

	/**
	 * MCP protocol versions this server supports (newest last).
	 */
	const SUPPORTED_PROTOCOL_VERSIONS = array( '2024-11-05', '2025-03-26', '2025-06-18' );

	/**
	 * Registry.
	 *
	 * @var AB_MCP_Tool_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param AB_MCP_Tool_Registry $registry Registry.
	 */
	public function __construct( AB_MCP_Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register the route.
	 */
	public function register_routes() {
		$handlers = array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
		);

		// Header-token endpoint (Authorization: Bearer / X-Api-Key clients).
		register_rest_route( AB_MCP_REST_NAMESPACE, AB_MCP_REST_ROUTE, $handlers );

		// Connector endpoint with the token embedded in the path, so a single URL
		// can be pasted into Claude.ai with no header:
		// POST /wp-json/alphabridge/v1/mcp/<token>
		register_rest_route(
			AB_MCP_REST_NAMESPACE,
			AB_MCP_REST_ROUTE . '/(?P<token>[A-Za-z0-9_]+)',
			$handlers
		);

		// Apply the no-referrer / no-store headers to EVERY response from these
		// routes — GET, POST and error responses (e.g. 401) alike.
		add_filter( 'rest_post_dispatch', array( $this, 'add_security_headers' ), 10, 3 );
	}

	/**
	 * Stamp no-referrer / no-store on every response from this plugin's MCP
	 * routes so a token embedded in the connector URL cannot leak through the
	 * Referer header or be cached by intermediaries — regardless of method or
	 * status code.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server (unused).
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public function add_security_headers( $response, $server, $request ) {
		unset( $server );
		$route = is_object( $request ) ? $request->get_route() : '';
		$base  = '/' . AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE;
		if ( is_string( $route ) && 0 === strpos( $route, $base )
			&& is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Referrer-Policy', 'no-referrer' );
			$response->header( 'Cache-Control', 'no-store' );

			// OAuth discovery bridge (RFC 9728): an unauthenticated MCP client is
			// told where this site's protected-resource metadata lives, so
			// Claude's native "Connect" flow can start from a plain 401 instead
			// of dead-ending. Only when AlphaBridge Connect is switched on.
			if ( 401 === (int) $response->get_status()
				&& class_exists( 'AB_MCP_OAuth' ) && AB_MCP_OAuth::enabled() ) {
				$response->header(
					'WWW-Authenticate',
					'Bearer resource_metadata="' . esc_url_raw( AB_MCP_OAuth::resource_metadata_url() ) . '"'
				);
			}
		}
		return $response;
	}

	/**
	 * Auth gate for the route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		// MCP transport security: when a browser sends an Origin header it must
		// match this site (DNS-rebinding protection per the MCP spec). Requests
		// WITHOUT an Origin header (server-to-server clients such as the
		// Claude.ai connector) are unaffected.
		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin ) {
			$allowed = array( home_url(), site_url() );
			/**
			 * Filter the origins allowed to call the MCP endpoint from a browser
			 * context. Compared against scheme://host[:port].
			 *
			 * @param string[] $allowed Allowed origins.
			 */
			$allowed = (array) apply_filters( 'ab_mcp_allowed_origins', $allowed );
			$match   = false;
			foreach ( $allowed as $candidate ) {
				$candidate = untrailingslashit( (string) $candidate );
				$parts     = wp_parse_url( $candidate );
				$normal    = isset( $parts['scheme'], $parts['host'] )
					? $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
					: $candidate;
				if ( 0 === strcasecmp( untrailingslashit( $origin ), $normal ) ) {
					$match = true;
					break;
				}
			}
			if ( ! $match ) {
				return new WP_Error(
					'ab_mcp_bad_origin',
					__( 'Origin not allowed.', 'alphabridge-mcp' ),
					array( 'status' => 403 )
				);
			}
		}

		$user_id = AB_MCP_Auth::verify_request( $request );
		if ( ! $user_id ) {
			return new WP_Error(
				'ab_mcp_unauthorized',
				__( 'Invalid or missing bearer token.', 'alphabridge-mcp' ),
				array( 'status' => 401 )
			);
		}
		wp_set_current_user( $user_id );
		return true;
	}

	/**
	 * GET handler. Streamable-HTTP clients may open GET to request a
	 * server-initiated SSE stream; this server does not offer one, and the MCP
	 * spec's answer for that case is 405 Method Not Allowed.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get() {
		$response = new WP_REST_Response(
			array(
				'code'    => 'ab_mcp_method_not_allowed',
				'message' => 'This MCP endpoint does not offer a server-initiated stream. Send JSON-RPC 2.0 requests via HTTP POST.',
			),
			405
		);
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * Main POST handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		// MCP-Protocol-Version header (sent by Streamable-HTTP clients after
		// initialize): when present it must be a version this server supports.
		$proto = (string) $request->get_header( 'mcp_protocol_version' );
		if ( '' !== $proto && ! in_array( $proto, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ) {
			return $this->secure(
				new WP_REST_Response(
					array(
						'code'    => 'ab_mcp_bad_protocol_version',
						'message' => 'Unsupported MCP-Protocol-Version. Supported: ' . implode( ', ', self::SUPPORTED_PROTOCOL_VERSIONS ) . '.',
					),
					400
				)
			);
		}

		$body = $request->get_json_params();

		if ( null === $body || ! is_array( $body ) ) {
			return $this->secure( new WP_REST_Response( $this->error( null, -32700, 'Parse error' ), 200 ) );
		}

		// Batch request (JSON array of messages).
		if ( array_is_list( $body ) ) {
			// An empty batch is invalid per JSON-RPC 2.0 — a single error, not
			// an empty array response.
			if ( 0 === count( $body ) ) {
				return $this->secure( new WP_REST_Response( $this->error( null, -32600, 'Invalid Request: empty batch.' ), 200 ) );
			}
			if ( count( $body ) > AB_MCP_MAX_BATCH ) {
				return $this->secure(
					new WP_REST_Response(
						$this->error( null, -32600, sprintf( 'Batch too large: max %d messages per request.', AB_MCP_MAX_BATCH ) ),
						200
					)
				);
			}
			$responses = array();
			foreach ( $body as $message ) {
				if ( is_array( $message ) ) {
					$resp = $this->dispatch( $message );
					if ( null !== $resp ) {
						$responses[] = $resp;
					}
				}
			}
			return $this->secure( new WP_REST_Response( $responses, 200 ) );
		}

		$response = $this->dispatch( $body );
		if ( null === $response ) {
			// Notification only – no body.
			return $this->secure( new WP_REST_Response( null, 202 ) );
		}
		return $this->secure( new WP_REST_Response( $response, 200 ) );
	}

	/**
	 * Add hardening headers. A token embedded in the connector URL path must
	 * not leak through the Referer header, and token-bearing responses must not
	 * be cached by intermediaries.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private function secure( $response ) {
		$response->header( 'Referrer-Policy', 'no-referrer' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Route a single JSON-RPC message.
	 *
	 * @param array $message Decoded message.
	 * @return array|null Response or null for notifications.
	 */
	private function dispatch( $message ) {
		// A message with no "id" member is a notification: per JSON-RPC 2.0 the
		// server sends NO response for it — not even for errors. Real MCP clients
		// only omit the id on notifications/* acknowledgements, which need no
		// action here, so a notification is simply not answered.
		$is_notification = ! array_key_exists( 'id', $message );
		$id              = $is_notification ? null : $message['id'];
		$method          = isset( $message['method'] ) ? $message['method'] : '';
		$params          = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		// The "jsonrpc" member, when present, must be exactly "2.0".
		if ( isset( $message['jsonrpc'] ) && '2.0' !== $message['jsonrpc'] ) {
			return $is_notification ? null : $this->error( $id, -32600, 'Invalid Request: jsonrpc must be "2.0".' );
		}

		if ( ! is_string( $method ) || '' === $method ) {
			return $is_notification ? null : $this->error( $id, -32600, 'Invalid Request' );
		}

		if ( $is_notification ) {
			return null; // Notification: acknowledged, nothing to return.
		}

		switch ( $method ) {
			case 'initialize':
				return $this->result( $id, $this->initialize( $params ) );

			case 'ping':
				return $this->result( $id, (object) array() );

			case 'tools/list':
				return $this->result( $id, $this->tools_list() );

			case 'tools/call':
				return $this->tools_call( $id, $params );

			case 'resources/list':
				return $this->result( $id, array( 'resources' => array() ) );

			case 'resources/templates/list':
				return $this->result( $id, array( 'resourceTemplates' => array() ) );

			case 'prompts/list':
				return $this->result( $id, $this->prompts_list() );

			case 'prompts/get':
				return $this->prompts_get( $id, $params );

			default:
				if ( 0 === strpos( $method, 'notifications/' ) ) {
					return null; // Notifications require no response.
				}
				if ( null === $id ) {
					return null; // Unknown notification.
				}
				return $this->error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * initialize handshake.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	private function initialize( $params ) {
		$requested = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
		$supported = self::SUPPORTED_PROTOCOL_VERSIONS;
		$version   = in_array( $requested, $supported, true ) ? $requested : AB_MCP_PROTOCOL_VERSION;

		$counts = sprintf( '%d tools available.', $this->registry->count() );

		$instructions = 'Control this WordPress site through AlphaBridge MCP. ' . $counts .
			' Tools cover content, media, taxonomies, comments, widgets, site settings, site info, ' .
			'SEO reads and search. ' .
			'Working effectively: enable only the tool groups you need — powerful ("mighty") tools are off by ' .
			'default and are hidden until the admin switches them on. Prefer the specific list/get tools over ' .
			'broad queries, and read a resource before overwriting it. Tool groups can be switched off by the admin.';

		/**
		 * Filter the MCP server instructions delivered on initialize. Add-ons
		 * append edition-specific guidance here, only when their features are
		 * actually available on this site.
		 *
		 * Keep additions short and high-signal: this string is loaded into the
		 * client's context on every session.
		 *
		 * @param string $instructions Base instructions.
		 */
		$instructions = (string) apply_filters( 'ab_mcp_instructions', $instructions );

		if ( AB_MCP_Settings::get( 'read_only', false ) ) {
			$instructions .= ' READ-ONLY MODE is active: every writing tool is blocked by the administrator right now; only read, list and search tools will run.';
		}

		$capabilities = array(
			'tools' => array( 'listChanged' => false ),
		);
		if ( ! empty( $this->prompts() ) ) {
			$capabilities['prompts'] = array( 'listChanged' => false );
		}

		return array(
			'protocolVersion' => $version,
			'capabilities'    => $capabilities,
			'serverInfo'      => array(
				'name'    => 'AlphaBridge MCP',
				'version' => AB_MCP_VERSION,
			),
			'instructions'    => $instructions,
		);
	}

	/**
	 * Registered MCP prompts (guided playbooks). Add-ons contribute via the
	 * 'ab_mcp_prompts' filter. Each entry:
	 *   name        (string)  Unique prompt id.
	 *   description (string)  What the prompt is for.
	 *   arguments   (array)   Optional [ { name, description, required } ].
	 *   text        (string)  The prompt body; {{arg}} placeholders are filled
	 *                         from the caller's arguments on prompts/get.
	 *
	 * @return array<int,array>
	 */
	private function prompts() {
		$prompts = apply_filters( 'ab_mcp_prompts', array() );
		return is_array( $prompts ) ? array_values( $prompts ) : array();
	}

	/**
	 * prompts/list – descriptors only (no bodies).
	 *
	 * @return array
	 */
	private function prompts_list() {
		$out = array();
		foreach ( $this->prompts() as $p ) {
			if ( empty( $p['name'] ) ) {
				continue;
			}
			$out[] = array(
				'name'        => (string) $p['name'],
				'description' => isset( $p['description'] ) ? (string) $p['description'] : '',
				'arguments'   => isset( $p['arguments'] ) && is_array( $p['arguments'] ) ? array_values( $p['arguments'] ) : array(),
			);
		}
		return array( 'prompts' => $out );
	}

	/**
	 * prompts/get – return a prompt's messages, with {{arg}} placeholders filled
	 * from the caller-supplied arguments.
	 *
	 * @param mixed $id     Request id.
	 * @param array $params Params: name (string), arguments (object).
	 * @return array
	 */
	private function prompts_get( $id, $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		foreach ( $this->prompts() as $p ) {
			if ( empty( $p['name'] ) || (string) $p['name'] !== $name ) {
				continue;
			}
			$text = isset( $p['text'] ) ? (string) $p['text'] : '';
			foreach ( $args as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$text = str_replace( '{{' . $k . '}}', (string) $v, $text );
				}
			}
			// Drop any placeholders the caller did not supply.
			$text = preg_replace( '/\{\{[a-z0-9_]+\}\}/i', '', $text );

			return $this->result(
				$id,
				array(
					'description' => isset( $p['description'] ) ? (string) $p['description'] : '',
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => array(
								'type' => 'text',
								'text' => $text,
							),
						),
					),
				)
			);
		}

		return $this->error( $id, -32602, 'Unknown prompt: ' . $name );
	}

	/**
	 * tools/list – returns tool descriptors.
	 *
	 * @return array
	 */
	private function tools_list() {
		$tools = array();

		$scope = AB_MCP_Auth::current_scope();

		foreach ( $this->registry->all() as $name => $def ) {
			// Disabled tools are removed from the MCP surface entirely.
			if ( ! AB_MCP_Settings::is_tool_enabled( $name, $def ) ) {
				continue;
			}

			// A scoped token only sees the tools it may actually call, so the
			// client's picture matches what tools/call will allow.
			if ( ! AB_MCP_Tool_Registry::scope_allows( $scope, $name, $def ) ) {
				continue;
			}

			$tools[] = array(
				'name'        => $name,
				'title'       => $this->tool_title( $name, $def ),
				'description' => $def['description'],
				'inputSchema' => $this->normalize_schema( $def['inputSchema'] ),
				'annotations' => $this->tool_annotations( $name, $def ),
			);
		}

		return array( 'tools' => $tools );
	}

	/**
	 * Human title for a tool (from the definition, else derived from the name).
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Definition.
	 * @return string
	 */
	private function tool_title( $name, $def ) {
		if ( ! empty( $def['title'] ) && $def['title'] !== $name ) {
			return (string) $def['title'];
		}
		$t = $name;
		if ( 0 === strpos( $t, 'wp_' ) ) {
			$t = substr( $t, 3 );
		} elseif ( 0 === strpos( $t, 'wc_' ) ) {
			$t = 'WC ' . substr( $t, 3 );
		}
		return ucwords( str_replace( '_', ' ', $t ) );
	}

	/**
	 * MCP tool annotations (protocol 2025-03-26+; older clients ignore them).
	 * Derived from the central read-only classification and the dangerous flag.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Definition.
	 * @return array
	 */
	private function tool_annotations( $name, $def ) {
		$read_only = AB_MCP_Tool_Registry::is_read_only( $name, $def );
		return array(
			'title'           => $this->tool_title( $name, $def ),
			'readOnlyHint'    => $read_only,
			'destructiveHint' => ! $read_only && ! empty( $def['dangerous'] ),
			'idempotentHint'  => $read_only,
			'openWorldHint'   => AB_MCP_Tool_Registry::is_open_world( $name, $def ),
		);
	}

	/**
	 * Ensure a JSON Schema encodes as an object.
	 *
	 * @param mixed $schema Schema.
	 * @return array
	 */
	private function normalize_schema( $schema ) {
		if ( ! is_array( $schema ) ) {
			return array(
				'type'       => 'object',
				'properties' => (object) array(),
			);
		}
		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}
		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = (object) array();
		}
		return $schema;
	}

	/**
	 * tools/call – enforce policy, run the tool, wrap the result.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param array $params Params.
	 * @return array
	 */
	private function tools_call( $id, $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$def = $this->registry->get( $name );
		if ( ! $def ) {
			/* translators: %s: tool name */
			return $this->result( $id, $this->tool_error( sprintf( __( 'Unknown tool: %s', 'alphabridge-mcp' ), $name ) ) );
		}

		$authorized = AB_MCP_Security::authorize( $name, $def );
		if ( is_wp_error( $authorized ) ) {
			AB_MCP_Audit_Log::record( $name, $args, 'denied', $authorized->get_error_message() );
			return $this->result( $id, $this->tool_error( $authorized->get_error_message() ) );
		}

		// Central argument validation (required fields, declared types, size caps).
		$valid = AB_MCP_Security::validate_args( $def, $args );
		if ( is_wp_error( $valid ) ) {
			AB_MCP_Audit_Log::record( $name, $args, 'denied', $valid->get_error_message() );
			return $this->result( $id, $this->tool_error( $valid->get_error_message() ) );
		}

		if ( ! is_callable( $def['callback'] ) ) {
			return $this->result( $id, $this->tool_error( __( 'Tool has no handler.', 'alphabridge-mcp' ) ) );
		}

		try {
			$result = call_user_func( $def['callback'], $args );
		} catch ( \Throwable $e ) {
			// The real exception text (which can name file paths, SQL or internal
			// state) goes to the audit log only; the client gets a generic message.
			AB_MCP_Audit_Log::record( $name, $args, 'error', $e->getMessage() );
			return $this->result( $id, $this->tool_error( __( 'The tool failed with an internal error. Check the AlphaBridge MCP log for details.', 'alphabridge-mcp' ) ) );
		}

		if ( is_wp_error( $result ) ) {
			AB_MCP_Audit_Log::record( $name, $args, 'error', $result->get_error_message() );
			return $this->result( $id, $this->tool_error( $result->get_error_message() ) );
		}

		AB_MCP_Audit_Log::record( $name, $args, 'ok', null );
		return $this->result( $id, $this->tool_ok( $result ) );
	}

	/**
	 * Build a successful tool result payload (MCP content + structuredContent).
	 *
	 * @param mixed $data Result data.
	 * @return array
	 */
	private function tool_ok( $data ) {
		if ( is_string( $data ) ) {
			$text = $data;
		} else {
			$text = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$payload = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => false,
		);

		if ( is_array( $data ) ) {
			$payload['structuredContent'] = $data;
		}

		return $payload;
	}

	/**
	 * Build an error tool result (MCP convention: result with isError true).
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private function tool_error( $message ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}

	/**
	 * JSON-RPC success envelope.
	 *
	 * @param mixed $id     Id.
	 * @param mixed $result Result.
	 * @return array
	 */
	private function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * JSON-RPC error envelope.
	 *
	 * @param mixed  $id      Id.
	 * @param int    $code    Error code.
	 * @param string $message Message.
	 * @param mixed  $data    Optional data.
	 * @return array
	 */
	private function error( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}
}

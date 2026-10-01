<?php
/**
 * An adapter that reads nothing real: it stands in for the builder adapters
 * the free core does not ship yet (Elementor …), so the registry,
 * the guard and the tool can be tested with a supported storage-B builder.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Builder_Adapter;

final class FakeBuilderAdapter extends AB_MCP_Builder_Adapter {

	/** @var string */
	private $id;

	/** @var array<int,array> */
	private $elements;

	/** @var string[] */
	private $serves;

	/**
	 * @param string   $id       Adapter id (a builder id of signatures.php, or a new one).
	 * @param array    $elements What outline() returns.
	 * @param string[] $serves   Builder ids it reads; default: its own id.
	 */
	public function __construct( string $id, array $elements = array(), array $serves = array() ) {
		$this->id       = $id;
		$this->elements = $elements;
		$this->serves   = array() === $serves ? array( $id ) : $serves;
	}

	public function id(): string {
		return $this->id;
	}

	public function builders(): array {
		return $this->serves;
	}

	public function outline( $post, array $o ): array {
		return array_slice( $this->elements, 0, (int) ( $o['max_elements'] ?? 500 ) );
	}

	public function verified(): bool {
		return true;
	}
}

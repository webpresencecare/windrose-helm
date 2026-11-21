<?php
/**
 * A REST server that records headers instead of sending them (BunnyFontsTest).
 *
 * @package Windrose\Helm
 */

/**
 * REST server that records headers instead of sending them.
 */
class Windrose_Helm_Recording_Server extends WP_REST_Server {

	/**
	 * Headers set.
	 *
	 * @var array<string, string>
	 */
	public array $sent = array();

	/**
	 * Records a header.
	 *
	 * @param string $key   Name.
	 * @param string $value Value.
	 */
	public function send_header( $key, $value ) {
		$this->sent[ $key ] = $value;
	}

	/**
	 * Records a removal.
	 *
	 * @param string $key Name.
	 */
	public function remove_header( $key ) {
		unset( $this->sent[ $key ] );
	}
}

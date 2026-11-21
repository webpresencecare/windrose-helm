<?php
/**
 * A Requests transport for tests: answers locally, so core's HTTP code (redirects included) runs without the network.
 *
 * @package Windrose\Helm
 */

/**
 * A Requests transport that never goes online: a 302 to the address in `$location` for the first request, 200 after.
 */
class Windrose_Helm_Redirecting_Transport implements \WpOrg\Requests\Transport {

	/**
	 * Where the first answer redirects to.
	 *
	 * @var string
	 */
	public static string $location = '';

	/**
	 * Addresses asked for.
	 *
	 * @var list<string>
	 */
	public static array $asked = array();

	/**
	 * Answers one request.
	 *
	 * @param string       $url     Address.
	 * @param array<mixed> $headers Headers.
	 * @param mixed        $data    Body.
	 * @param array<mixed> $options Options.
	 * @return string Raw HTTP answer.
	 */
	public function request( $url, $headers = array(), $data = array(), $options = array() ) {
		self::$asked[] = $url;
		if ( 1 === count( self::$asked ) ) {
			return "HTTP/1.1 302 Found\r\nLocation: " . self::$location . "\r\nContent-Length: 0\r\n\r\n";
		}
		return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
	}

	/**
	 * Not used.
	 *
	 * @param array<mixed> $requests Requests.
	 * @param array<mixed> $options  Options.
	 * @return array<mixed>
	 */
	public function request_multiple( $requests, $options ) {
		return array();
	}

	/**
	 * Always usable.
	 *
	 * @param array<mixed> $capabilities Capabilities.
	 * @return bool
	 */
	public static function test( $capabilities = array() ) {
		return true;
	}
}

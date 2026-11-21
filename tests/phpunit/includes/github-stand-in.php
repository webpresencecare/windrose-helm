<?php
/**
 * A stand-in for GitHub's API in tests: answers `pre_http_request` in GitHub's own format (release JSON with asset
 * `digest`, 302 from the asset API to a signed download host, the download itself).
 *
 * @package Windrose\Helm
 */

/**
 * GitHub stand-in.
 */
final class Windrose_Test_GitHub {

	/**
	 * Requests seen: url and args.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	public array $requests = array();

	/**
	 * Slug => release JSON (array) or HTTP status (int).
	 *
	 * @var array<string, array<string, mixed>|int>
	 */
	public array $releases = array();

	/**
	 * Asset ID => local zip path served as the download.
	 *
	 * @var array<int, string>
	 */
	public array $assets = array();

	/**
	 * Host the asset API redirects to.
	 *
	 * @var string
	 */
	public string $redirect_host = 'release-assets.githubusercontent.com';

	/**
	 * Starts answering.
	 *
	 * @return void
	 */
	public function hook(): void {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 5, 3 );
	}

	/**
	 * Stops answering.
	 *
	 * @return void
	 */
	public function unhook(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 5 );
	}

	/**
	 * Requests whose address starts with a prefix.
	 *
	 * @param string $prefix Address prefix.
	 * @return list<array{url: string, args: array<string, mixed>}>
	 */
	public function requests_to( string $prefix ): array {
		return array_values( array_filter( $this->requests, static fn( array $r ): bool => str_starts_with( $r['url'], $prefix ) ) );
	}

	/**
	 * Answers GitHub requests.
	 *
	 * @param mixed  $pre  Earlier answer.
	 * @param array  $args Request arguments.
	 * @param string $url  Address.
	 * @return mixed
	 */
	public function answer( $pre, $args, $url ) {
		if ( preg_match( '#^https://api\.github\.com/repos/webpresencecare/([^/]+)/releases/latest$#', $url, $m ) ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			$release          = $this->releases[ $m[1] ] ?? 404;
			return is_int( $release ) ? self::response( $release, '{"message":"Not Found"}' ) : self::response( 200, (string) wp_json_encode( $release ) );
		}
		if ( preg_match( '#^https://api\.github\.com/repos/webpresencecare/([^/]+)/releases/tags/([^/]+)$#', $url, $m ) ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			$release          = $this->releases[ $m[1] ] ?? 404;
			if ( is_int( $release ) ) {
				return self::response( $release, '{"message":"Not Found"}' );
			}
			if ( ( $release['tag_name'] ?? '' ) !== $m[2] ) {
				return self::response( 404, '{"message":"Not Found"}' );
			}
			return self::response( 200, (string) wp_json_encode( $release ) );
		}
		if ( preg_match( '#^https://api\.github\.com/repos/webpresencecare/[^/]+/releases/assets/(\d+)$#', $url, $m ) ) {
			$this->requests[]                = array(
				'url'  => $url,
				'args' => $args,
			);
			$response                        = self::response( 302, '' );
			$response['headers']['location'] = 'https://' . $this->redirect_host . '/github-production-release-asset/1/' . $m[1] . '?sp=r&sig=abc';
			return $response;
		}
		if ( preg_match( '#^https://[^/]+/github-production-release-asset/1/(\d+)#', $url, $m ) ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			$file             = $this->assets[ (int) $m[1] ] ?? '';
			if ( '' === $file ) {
				return self::response( 404, '' );
			}
			if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
				copy( $file, $args['filename'] );
			}
			$response             = self::response( 200, '' );
			$response['filename'] = $args['filename'] ?? null;
			return $response;
		}
		return $pre;
	}

	/**
	 * A GitHub release answer for a zip.
	 *
	 * @param string $slug     Repository.
	 * @param string $version  Version without "v".
	 * @param string $zip      Local zip path.
	 * @param int    $asset_id Asset ID.
	 * @return array<string, mixed>
	 */
	public function add_release( string $slug, string $version, string $zip, int $asset_id ): array {
		$tag                       = 'v' . $version;
		$this->assets[ $asset_id ] = $zip;
		$this->releases[ $slug ]   = array(
			'tag_name'   => $tag,
			'draft'      => false,
			'prerelease' => false,
			'html_url'   => "https://github.com/webpresencecare/$slug/releases/tag/$tag",
			'body'       => "Release $tag",
			'assets'     => array(
				array(
					'url'                  => "https://api.github.com/repos/webpresencecare/$slug/releases/assets/$asset_id",
					'name'                 => "$slug.zip",
					'state'                => 'uploaded',
					'size'                 => filesize( $zip ),
					'digest'               => 'sha256:' . hash_file( 'sha256', $zip ),
					'browser_download_url' => "https://github.com/webpresencecare/$slug/releases/download/$tag/$slug.zip",
				),
			),
		);
		return $this->releases[ $slug ];
	}

	/**
	 * Adds another file to a release added before.
	 *
	 * @param string $slug     Repository.
	 * @param string $name     Asset name.
	 * @param string $file     Local file path.
	 * @param int    $asset_id Asset ID.
	 * @return array<string, mixed> The asset entry.
	 */
	public function add_asset( string $slug, string $name, string $file, int $asset_id ): array {
		$release                   = $this->releases[ $slug ];
		$tag                       = $release['tag_name'];
		$this->assets[ $asset_id ] = $file;
		$asset                     = array(
			'url'                  => "https://api.github.com/repos/webpresencecare/$slug/releases/assets/$asset_id",
			'name'                 => $name,
			'state'                => 'uploaded',
			'size'                 => filesize( $file ),
			'digest'               => 'sha256:' . hash_file( 'sha256', $file ),
			'browser_download_url' => "https://github.com/webpresencecare/$slug/releases/download/$tag/$name",
		);
		$release['assets'][]       = $asset;
		$this->releases[ $slug ]   = $release;
		return $asset;
	}

	/**
	 * Builds a plugin zip.
	 *
	 * @param string                $slug    Plugin slug.
	 * @param string                $version Version header.
	 * @param string|null           $root    Root folder in the zip (default: the slug).
	 * @param array<string, string> $extra   Extra files (path in zip => content).
	 * @return string Zip path.
	 */
	public static function plugin_zip( string $slug, string $version, ?string $root = null, array $extra = array() ): string {
		$file = wp_tempnam( $slug . '.zip' );
		$zip  = new ZipArchive();
		$zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$root = $root ?? $slug;
		$zip->addFromString( "$root/$slug.php", self::plugin_header( $slug, $version ) );
		foreach ( $extra as $path => $content ) {
			$zip->addFromString( $path, $content );
		}
		$zip->close();
		return $file;
	}

	/**
	 * A plugin main file.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Version.
	 * @return string
	 */
	public static function plugin_header( string $slug, string $version ): string {
		return "<?php\n/**\n * Plugin Name: Windrose Test Dummy\n * Version: $version\n * Update URI: https://github.com/webpresencecare/$slug\n */\n";
	}

	/**
	 * An HTTP API response.
	 *
	 * @param int    $code Status.
	 * @param string $body Body.
	 * @return array<string, mixed>
	 */
	private static function response( int $code, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}

<?php
/**
 * Make a network: turns the single site Windrose Helm is activated on into a network of sites, as core's Network
 * Setup does, without editing files by hand.
 *
 * Tools, "Make a network" (capability setup_network, single sites only). The screen explains what changes and asks:
 * subdirectories (always offered: the post addresses are handled below) or subdomains (only where core allows them:
 * not in a folder, not localhost or an IP address), the network title, the main site's post addresses (keep the
 * structure with "/blog" in front, or core's default "/blog/%year%/%monthnum%/%day%/%postname%/"), and a backup
 * confirmation. Then: core's install_network() and populate_network() (the current user becomes super admin), the
 * network language, plugins that only work network-wide (Windrose Helm included) moved to network-active, the other
 * active plugins kept on the main site, windrose_helm_setup pending (Set up opens after logging in again), the six
 * constants into wp-config.php and the rules into .htaccess, or the lines to add by hand. Refused when network tables
 * already exist or the address has a port.
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm;

use Windrose\Common\Files;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Make a network screen.
 */
final class Network_Maker {

	public const SLUG = 'windrose-helm-network';

	private const NONCE = 'windrose_helm_network';

	/**
	 * Result of a conversion in this request (shown by render()).
	 *
	 * @var array<string, mixed>|WP_Error|null
	 */
	private static $result = null;

	/**
	 * Hooks the screen and the notice (single sites only).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Address of the screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Adds Tools, Make a network.
	 *
	 * @return void
	 */
	public static function menu(): void {
		$hook = add_management_page(
			__( 'Make this site a network', 'windrose-helm' ),
			__( 'Make a network', 'windrose-helm' ),
			'setup_network',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'handle' ) );
		}
	}

	/**
	 * Says Windrose Helm needs a network, with a link to the screen.
	 *
	 * @return void
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null !== $screen && 'tools_page_' . self::SLUG === $screen->id ) {
			return;
		}
		if ( ! current_user_can( 'setup_network' ) ) {
			wp_admin_notice( esc_html__( 'Windrose Helm works on a network of sites only. It does nothing on this single site.', 'windrose-helm' ), array( 'type' => 'warning' ) );
			return;
		}
		wp_admin_notice(
			sprintf(
				'%1$s <a href="%2$s">%3$s</a>',
				esc_html__( 'Windrose Helm works on a network of sites. It can turn this site into a network, without editing files by hand.', 'windrose-helm' ),
				esc_url( self::url() ),
				esc_html__( 'Make this site a network', 'windrose-helm' )
			),
			array( 'type' => 'warning' )
		);
	}

	/**
	 * Gives $wpdb the network table names, empty on a single site (core's Network Setup does the same first).
	 *
	 * @return void
	 */
	private static function network_tables(): void {
		global $wpdb;
		foreach ( $wpdb->tables( 'ms_global' ) as $table => $prefixed_table ) {
			$wpdb->$table = $prefixed_table;
		}
	}

	/**
	 * What stops the conversion, or ''.
	 *
	 * @return string
	 */
	public static function problem(): string {
		require_once ABSPATH . 'wp-admin/includes/network.php';
		if ( is_multisite() ) {
			return __( 'This site is already part of a network.', 'windrose-helm' );
		}
		self::network_tables();
		if ( network_domain_check() ) {
			return __( 'The database already holds the tables of a WordPress network (an earlier, unfinished conversion?). WordPress’s Tools, Network Setup screen explains how to finish or remove it.', 'windrose-helm' );
		}
		$home = wp_parse_url( (string) get_option( 'home' ) );
		$port = is_array( $home ) ? (int) ( $home['port'] ?? 0 ) : 0;
		if ( 0 !== $port && 80 !== $port && 443 !== $port ) {
			/* translators: %d: port number. */
			return sprintf( __( 'WordPress networks cannot use port %d in their address.', 'windrose-helm' ), $port );
		}
		return '';
	}

	/**
	 * The main site's post address structure after the conversion, or null for core's default.
	 *
	 * @param string $old       The structure now.
	 * @param bool   $subdomain Subdomain network.
	 * @param string $choice    keep or default.
	 * @return string|null
	 */
	public static function structure( string $old, bool $subdomain, string $choice ): ?string {
		if ( 'keep' !== $choice || str_contains( $old, 'index.php' ) ) {
			return null;
		}
		if ( '' === $old || $subdomain || str_starts_with( $old, '/blog/' ) ) {
			return $old;
		}
		return '/blog' . ( str_starts_with( $old, '/' ) ? $old : '/' . $old );
	}

	/**
	 * Handles the form before the screen is printed.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! isset( $_POST['windrose_helm_network_nonce'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE, 'windrose_helm_network_nonce' );
		if ( ! current_user_can( 'setup_network' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to make this site a network.', 'windrose-helm' ), '', array( 'response' => 403 ) );
		}
		if ( empty( $_POST['backup'] ) ) {
			self::$result = new WP_Error( 'windrose_helm_network_backup', __( 'Confirm that you have a backup of the files and the database first.', 'windrose-helm' ) );
			return;
		}
		$type      = isset( $_POST['type'] ) && 'subdomain' === $_POST['type'] ? 'subdomain' : 'subdirectory';
		$addresses = isset( $_POST['addresses'] ) && 'default' === $_POST['addresses'] ? 'default' : 'keep';
		$title     = isset( $_POST['title'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) ) : '';
		if ( 'subdomain' === $type && empty( $_POST['wildcard'] ) ) {
			self::$result = new WP_Error( 'windrose_helm_network_wildcard', __( 'Subdomains need a wildcard DNS record: confirm it exists, or choose subdirectories.', 'windrose-helm' ) );
			return;
		}
		self::$result = self::make( $type, $addresses, '' === $title ? get_bloginfo( 'name' ) : $title );
	}

	/**
	 * Makes the network.
	 *
	 * @param string $type      subdirectory or subdomain.
	 * @param string $addresses keep or default (the main site's post addresses).
	 * @param string $title     Network title.
	 * @return array{config: string, rules: string, server: string, warning: string, backups: list<string>, restore: string}|WP_Error
	 */
	public static function make( string $type, string $addresses, string $title ) {
		$problem = self::problem();
		if ( '' !== $problem ) {
			return new WP_Error( 'windrose_helm_network', $problem );
		}
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		// install_network() and populate_network() (schema.php) use dbDelta() (upgrade.php), as core's Network Setup.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		require_once ABSPATH . 'wp-admin/includes/schema.php';
		$subdomain = 'subdomain' === $type;
		if ( $subdomain && ! allow_subdomain_install() ) {
			return new WP_Error( 'windrose_helm_network', __( 'Subdomains are not possible for this site’s address (a folder, localhost or an IP address).', 'windrose-helm' ) );
		}
		global $wpdb;
		$user          = wp_get_current_user();
		$old_structure = (string) get_option( 'permalink_structure', '' );
		$active        = (array) get_option( 'active_plugins', array() );
		$locale        = (string) get_option( 'WPLANG', '' );
		$domain        = (string) get_clean_basedomain();
		$path          = (string) wp_parse_url( trailingslashit( (string) get_option( 'home' ) ), PHP_URL_PATH );

		// Copies of the two files as they are now, before anything changes: populate_network() below
		// already rewrites .htaccess with single-site rules. wp-config.backup.php is a .php file, so the server runs it
		// instead of showing it; Apache refuses to serve .htaccess.backup (.ht* files). Kept only when the file is
		// then changed.
		$server        = self::server();
		$config_copy   = self::backup( Setup::config_path(), 'wp-config.backup', '.php' );
		$htaccess_copy = in_array( $server, array( 'nginx', 'iis' ), true ) ? '' : self::backup( get_home_path() . '.htaccess', '.htaccess', '.backup', Files::direct() );

		// As core's Network Setup: the multisite table names (set by problem() above), the tables, the network and its
		// main site.
		install_network();
		$warning = '';
		$created = populate_network( 1, $domain, $user->user_email, $title, $path, $subdomain );
		if ( is_wp_error( $created ) ) {
			if ( 'no_wildcard_dns' !== $created->get_error_code() ) {
				self::forget( $config_copy );
				self::forget( $htaccess_copy );
				return $created;
			}
			$warning = wp_strip_all_tags( $created->get_error_message() );
		}

		$structure = self::structure( $old_structure, $subdomain, $addresses );
		if ( null !== $structure ) {
			update_option( 'permalink_structure', $structure );
			if ( $structure !== $old_structure ) {
				update_option( Old_Addresses::OPTION, '1' );
			}
		}
		delete_option( 'rewrite_rules' );

		// The site is not a network yet in this request: network options go to the network meta table directly.
		self::set_network_meta( 'WPLANG', $locale );
		$sitewide = array();
		$kept     = array();
		foreach ( $active as $file ) {
			if ( is_string( $file ) && is_network_only_plugin( $file ) ) {
				$sitewide[ $file ] = time();
			} elseif ( is_string( $file ) ) {
				$kept[] = $file;
			}
		}
		$sitewide[ plugin_basename( WINDROSE_HELM_FILE ) ] = time();
		self::set_network_meta( 'active_sitewide_plugins', $sitewide );
		update_option( 'active_plugins', $kept );

		$report = array(
			'config'  => '',
			'rules'   => '',
			'server'  => $server,
			'warning' => $warning,
			'backups' => array(),
			'restore' => '',
		);
		$lines  = self::config_lines( $domain, $path, $subdomain );
		// No copy, no write: the lines are then shown to add by hand.
		$written = '' === $config_copy ? null : self::write_config( $lines );
		if ( true === $written ) {
			$report['backups'][] = $config_copy;
		} else {
			// Not tried: the copy is not needed. Tried and failed: the file may be half written, the copy stays.
			if ( null === $written ) {
				self::forget( $config_copy );
			} else {
				$report['backups'][] = $config_copy;
				$report['restore']   = $config_copy;
			}
			$report['config'] = $lines;
		}
		$rules = Setup::htaccess_rules( $path, $subdomain );
		if ( 'nginx' === $report['server'] ) {
			$report['rules'] = self::nginx_rules( $path, $subdomain );
		} elseif ( 'iis' === $report['server'] ) {
			$report['rules'] = $rules;
		} elseif ( self::write_htaccess( $rules ) ) {
			$report['backups'][] = $htaccess_copy;
		} else {
			self::forget( $htaccess_copy );
			$report['rules'] = $rules;
		}
		$report['backups'] = array_values( array_filter( $report['backups'] ) );
		self::set_network_meta(
			Setup::OPTION,
			array(
				'status'  => 'pending',
				'source'  => 'windrose-helm',
				'version' => WINDROSE_HELM_VERSION,
				'time'    => time(),
				'manual'  => array(
					'rules'  => $report['rules'],
					'server' => $report['server'],
				),
			)
		);
		return $report;
	}

	/**
	 * Copies a file next to itself before it changes: <name><suffix>, or <name>-<date and time><suffix> when that
	 * name is taken (an earlier copy is never overwritten).
	 *
	 * @param string                   $file       The file.
	 * @param string                   $name       Name of the copy without suffix (wp-config.backup, .htaccess).
	 * @param string                   $suffix     Suffix (.php, .backup).
	 * @param \WP_Filesystem_Base|null $filesystem How the file itself is written: by default wp-config.php's way
	 *                                              (Setup::filesystem()); .htaccess is written with plain PHP by
	 *                                              core's insert_with_markers(), so its copy uses the direct method.
	 * @return string Path of the copy, or '' when there was nothing to copy or it failed.
	 */
	public static function backup( string $file, string $name, string $suffix, ?\WP_Filesystem_Base $filesystem = null ): string {
		$filesystem ??= Setup::filesystem( $file );
		if ( null === $filesystem || ! $filesystem->is_file( $file ) ) {
			return '';
		}
		$target = dirname( $file ) . '/' . $name . $suffix;
		if ( $filesystem->exists( $target ) ) {
			$target = dirname( $file ) . '/' . $name . '-' . gmdate( 'Ymd-His' ) . $suffix;
		}
		// The copy keeps the file's permissions: a copy of wp-config.php holds the database password too.
		$mode = (int) octdec( $filesystem->getchmod( $file ) );
		return $filesystem->copy( $file, $target, false, $mode > 0 ? $mode : false ) ? $target : '';
	}

	/**
	 * Deletes a copy that is not needed (the file it copied was not changed).
	 *
	 * @param string $copy Path of the copy, or ''.
	 * @return void
	 */
	private static function forget( string $copy ): void {
		if ( '' !== $copy ) {
			wp_delete_file( $copy );
		}
	}

	/**
	 * Writes one option of network 1 to the network meta table (the site is not a network yet in this request).
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private static function set_network_meta( string $key, $value ): void {
		global $wpdb;
		$stored = maybe_serialize( $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No network cache exists yet.
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->sitemeta} WHERE site_id = 1 AND meta_key = %s", $key ) );
		if ( null !== $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same; one row by ID.
			$wpdb->update( $wpdb->sitemeta, array( 'meta_value' => $stored ), array( 'meta_id' => (int) $exists ) );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Same.
		$wpdb->insert(
			$wpdb->sitemeta,
			array(
				'site_id'    => 1,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Insert.
				'meta_value' => $stored, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Insert.
			)
		);
	}

	/**
	 * The web server: apache, nginx, iis or other.
	 *
	 * @return string
	 */
	private static function server(): string {
		global $is_apache, $is_nginx, $is_IIS, $is_iis7; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core's globals.
		if ( ! empty( $is_nginx ) ) {
			return 'nginx';
		}
		if ( ! empty( $is_IIS ) || ! empty( $is_iis7 ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core's globals.
			return 'iis';
		}
		return ! empty( $is_apache ) ? 'apache' : 'other';
	}

	/**
	 * The six multisite constants.
	 *
	 * @param string $domain    Network domain.
	 * @param string $path      Network path.
	 * @param bool   $subdomain Subdomain network.
	 * @return string
	 */
	public static function config_lines( string $domain, string $path, bool $subdomain ): string {
		return implode(
			"\n",
			array(
				'/* WordPress network (multisite), made by Windrose Helm. */',
				"define( 'MULTISITE', true );",
				"define( 'SUBDOMAIN_INSTALL', " . ( $subdomain ? 'true' : 'false' ) . ' );',
				"define( 'DOMAIN_CURRENT_SITE', " . self::php_string( $domain ) . ' );',
				"define( 'PATH_CURRENT_SITE', " . self::php_string( $path ) . ' );',
				"define( 'SITE_ID_CURRENT_SITE', 1 );",
				"define( 'BLOG_ID_CURRENT_SITE', 1 );",
			)
		) . "\n";
	}

	/**
	 * A PHP string literal in single quotes for wp-config.php: backslashes and quotes escaped.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function php_string( string $value ): string {
		return "'" . addcslashes( $value, "'\\" ) . "'";
	}

	/**
	 * Adds the lines above the "stop editing" line of wp-config.php (or above the wp-settings.php line).
	 *
	 * @param string $contents Current contents.
	 * @param string $lines    Lines to add.
	 * @return string|null New contents, or null when there is no place or a network constant is already there.
	 */
	public static function add_config( string $contents, string $lines ): ?string {
		if ( preg_match( "/define\\(\\s*['\"](MULTISITE|SUBDOMAIN_INSTALL|DOMAIN_CURRENT_SITE)['\"]/", $contents ) ) {
			return null;
		}
		$anchors = array(
			"/^\\/\\*\\s*That's all, stop editing!.*$/m",
			'/^\\/\\*\\*\\s*Absolute path to the WordPress directory\\..*$/m',
			"/^\\s*require_once\\s*\\(?\\s*ABSPATH\\s*\\.\\s*['\"]wp-settings\\.php['\"].*$/m",
		);
		foreach ( $anchors as $anchor ) {
			if ( preg_match( $anchor, $contents, $found, PREG_OFFSET_CAPTURE ) ) {
				$at = (int) $found[0][1];
				return substr( $contents, 0, $at ) . $lines . "\n" . substr( $contents, $at );
			}
		}
		return null;
	}

	/**
	 * Writes the constants into wp-config.php when PHP may change it.
	 *
	 * @param string $lines Lines to add.
	 * @return bool|null True when written, false when the write failed (the file may be half written), null when it was
	 *                   not tried (no direct access, or no place for the lines).
	 */
	private static function write_config( string $lines ): ?bool {
		$file       = Setup::config_path();
		$filesystem = Setup::filesystem( $file );
		if ( null === $filesystem || ! $filesystem->is_file( $file ) || ! $filesystem->is_writable( $file ) ) {
			return null;
		}
		$changed = self::add_config( (string) $filesystem->get_contents( $file ), $lines );
		return null === $changed ? null : Files::write( $file, $changed );
	}

	/**
	 * Replaces WordPress's block of .htaccess with the network rules.
	 *
	 * @param string $rules Rules.
	 * @return bool
	 */
	private static function write_htaccess( string $rules ): bool {
		$file = get_home_path() . '.htaccess';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Same check as core's save_mod_rewrite_rules().
		if ( ( file_exists( $file ) && ! is_writable( $file ) ) || ( ! file_exists( $file ) && ! is_writable( dirname( $file ) ) ) ) {
			return false;
		}
		return insert_with_markers( $file, 'WordPress', explode( "\n", $rules ) );
	}

	/**
	 * Nginx rules (WordPress's Nginx documentation).
	 *
	 * @param string $base      Network path.
	 * @param bool   $subdomain Subdomain network.
	 * @return string
	 */
	public static function nginx_rules( string $base, bool $subdomain ): string {
		$lines = array( 'location ' . $base . ' {', "\ttry_files \$uri \$uri/ " . $base . 'index.php?$args;', '}' );
		if ( ! $subdomain ) {
			$prefix = rtrim( $base, '/' );
			$lines  = array_merge(
				$lines,
				array(
					'if (!-e $request_filename) {',
					"\trewrite " . $prefix . '/wp-admin$ $scheme://$host$uri/ permanent;',
					"\trewrite ^" . $base . '[_0-9a-zA-Z-]+(/wp-.*) ' . $prefix . '$1 last;',
					"\trewrite ^" . $base . '[_0-9a-zA-Z-]+(/.*\.php)$ ' . $prefix . '$1 last;',
					'}',
				)
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * Prints the screen: the form, or what happened.
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<div class="wrap windrose-helm-network"><h1>' . esc_html__( 'Make this site a network', 'windrose-helm' ) . '</h1>';
		if ( is_array( self::$result ) ) {
			self::render_result( self::$result );
			echo '</div>';
			return;
		}
		if ( self::$result instanceof WP_Error ) {
			wp_admin_notice( esc_html( self::$result->get_error_message() ), array( 'type' => 'error' ) );
		}
		$problem = self::problem();
		if ( '' !== $problem ) {
			wp_admin_notice( esc_html( $problem ), array( 'type' => 'error' ) );
			// Closes the screen's wrap: no form follows.
			echo '</div>';
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/network.php';
		$subdomain_ok = allow_subdomain_install();
		$old          = (string) get_option( 'permalink_structure', '' );
		$kept         = self::structure( $old, false, 'keep' );
		$domain       = (string) get_clean_basedomain();
		$path         = (string) wp_parse_url( trailingslashit( (string) get_option( 'home' ) ), PHP_URL_PATH );
		?>
		<p><?php esc_html_e( 'A network holds several sites (for example one per language) that share WordPress, its plugins and themes, and the user accounts. This site becomes the network’s main site; its content stays as it is. You become the network’s administrator (super admin).', 'windrose-helm' ); ?></p>
		<p><?php esc_html_e( 'WordPress changes its configuration file (wp-config.php) and its rewrite rules (.htaccess). If it may not write them, the next screen shows the lines to add. After that, log in again: Windrose Helm’s Set up screen then offers languages, plugins and privacy.', 'windrose-helm' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, 'windrose_helm_network_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Addresses of the sites', 'windrose-helm' ); ?></th>
					<td><fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Addresses of the sites', 'windrose-helm' ); ?></legend>
						<label><input type="radio" name="type" value="subdirectory" checked> <?php echo esc_html( sprintf( /* translators: %s: example address. */ __( 'Subdirectories, for example %s', 'windrose-helm' ), $domain . $path . 'fr/' ) ); ?></label><br>
						<label><input type="radio" name="type" value="subdomain"<?php disabled( ! $subdomain_ok ); ?>> <?php echo esc_html( sprintf( /* translators: %s: example address. */ __( 'Subdomains, for example %s', 'windrose-helm' ), 'fr.' . $domain . $path ) ); ?></label>
						<p class="description">
							<?php
							echo esc_html(
								$subdomain_ok
									? __( 'Subdomains need a wildcard DNS record (*) pointing to this website and an HTTPS certificate covering the subdomains: set them up with your host first.', 'windrose-helm' )
									: __( 'Subdomains are not possible here: the site is in a folder, or its address is localhost or an IP address.', 'windrose-helm' )
							);
							?>
						</p>
						<?php if ( $subdomain_ok ) : ?>
							<label><input type="checkbox" name="wildcard" value="1"> <?php esc_html_e( 'For subdomains: the wildcard DNS record exists.', 'windrose-helm' ); ?></label>
						<?php endif; ?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post addresses of this site', 'windrose-helm' ); ?></th>
					<td><fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Post addresses of this site', 'windrose-helm' ); ?></legend>
						<p><?php esc_html_e( 'With subdirectories, the main site’s post addresses start with /blog, so they can never clash with the address of a site. WordPress redirects the old addresses to the new ones. Pages keep their addresses.', 'windrose-helm' ); ?></p>
						<label><input type="radio" name="addresses" value="keep" checked> <?php echo esc_html( null !== $kept && '' !== $kept ? sprintf( /* translators: %s: permalink structure. */ __( 'Keep the current structure, with /blog in front: %s', 'windrose-helm' ), $kept ) : __( 'Keep the current structure', 'windrose-helm' ) ); ?></label><br>
						<label><input type="radio" name="addresses" value="default"> <?php echo esc_html( sprintf( /* translators: %s: permalink structure. */ __( 'WordPress’s default for networks: %s', 'windrose-helm' ), '/blog/%year%/%monthnum%/%day%/%postname%/' ) ); ?></label>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: permalink structure. */ __( 'Now: %s. With subdomains, the current structure is kept as it is.', 'windrose-helm' ), '' === $old ? __( 'plain (?p=123)', 'windrose-helm' ) : $old ) ); ?></p>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><label for="windrose-helm-network-title"><?php esc_html_e( 'Network title', 'windrose-helm' ); ?></label></th>
					<td><input type="text" id="windrose-helm-network-title" name="title" class="regular-text" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Backup', 'windrose-helm' ); ?></th>
					<td><label><input type="checkbox" name="backup" value="1"> <?php esc_html_e( 'I have a backup of the files and the database of this site.', 'windrose-helm' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( __( 'Make this site a network', 'windrose-helm' ) ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Prints what happened.
	 *
	 * @param array<string, mixed> $result The report of make().
	 * @return void
	 */
	private static function render_result( array $result ): void {
		wp_admin_notice( esc_html__( 'This site is now the main site of a network.', 'windrose-helm' ), array( 'type' => 'success' ) );
		if ( '' !== $result['warning'] ) {
			wp_admin_notice( esc_html( (string) $result['warning'] ), array( 'type' => 'warning' ) );
		}
		if ( '' !== (string) ( $result['restore'] ?? '' ) ) {
			wp_admin_notice(
				wp_kses(
					sprintf(
						/* translators: %s: file name of the copy of wp-config.php. */
						__( 'WordPress could not finish writing wp-config.php, so the file may be broken. First replace it with its copy %s (by FTP or your host’s file manager), then add the lines below.', 'windrose-helm' ),
						'<code>' . esc_html( basename( (string) $result['restore'] ) ) . '</code>'
					),
					array( 'code' => array() )
				),
				array( 'type' => 'error' )
			);
		}
		if ( '' !== $result['config'] ) {
			printf(
				'<p id="windrose-helm-network-config">%1$s</p><textarea class="large-text code" rows="9" readonly aria-labelledby="windrose-helm-network-config">%2$s</textarea>',
				esc_html__( 'WordPress could not change wp-config.php. Add these lines to it, above the line “/* That’s all, stop editing! */”, then log in again:', 'windrose-helm' ),
				esc_textarea( (string) $result['config'] )
			);
		}
		if ( '' !== $result['rules'] ) {
			printf(
				'<p id="windrose-helm-network-rules">%1$s</p><textarea class="large-text code" rows="12" readonly aria-labelledby="windrose-helm-network-rules">%2$s</textarea>',
				esc_html(
					'nginx' === $result['server']
						? __( 'This server runs Nginx: add these rules to the site’s server configuration (or ask your host to). Until then, the other sites of the network cannot be reached.', 'windrose-helm' )
						: __( 'WordPress could not write the network rules into .htaccess. Replace WordPress’s rules there with these (or give them to your host):', 'windrose-helm' )
				),
				esc_textarea( (string) $result['rules'] )
			);
		}
		if ( array() !== $result['backups'] ) {
			$names = array_map( static fn( string $path ): string => '<code>' . esc_html( basename( $path ) ) . '</code>', (array) $result['backups'] );
			wp_admin_notice(
				wp_kses(
					sprintf(
						/* translators: %s: file names. */
						__( 'Copies of the files as they were before were saved next to them, as a backup: %s. Once everything works (the site, logging in, Network Admin), delete them: the copy of wp-config.php holds the database password.', 'windrose-helm' ),
						implode( ', ', $names )
					),
					array( 'code' => array() )
				),
				array( 'type' => 'info' )
			);
		}
		printf(
			'<p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p>',
			esc_html__( 'WordPress asks everyone to log in again after this change. Then Windrose Helm’s Set up screen opens: languages, plugins, privacy.', 'windrose-helm' ),
			esc_url( wp_login_url() ),
			esc_html__( 'Log in again', 'windrose-helm' )
		);
	}
}

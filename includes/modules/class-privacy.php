<?php
/**
 * Module Privacy: one switch per outgoing request type found in an inventory of WordPress 7.2's outgoing requests.
 *
 * | Switch          | What it does                                                                                       |
 * |-----------------|----------------------------------------------------------------------------------------------------|
 * | update_data     | Version checks send only WordPress and PHP versions and the locale; every wordpress.org request goes without the site address (User-Agent, wp_install and wp_blog headers) |
 * | site_address    | WordPress's default User-Agent ("WordPress/<version>; <site address>") loses the site address, for every server |
 * | dashboard_news  | Events and News widget removed (site, network and user dashboards), its AJAX answers refused      |
 * | happy_checks    | Browse Happy (sends the admin's browser) and Serve Happy (PHP version) checks not sent            |
 * | emoji           | Emoji detection script and s.w.org emoji images not used                                          |
 * | gravatar        | Avatars served from this plugin instead of Gravatar                                              |
 * | openverse       | Openverse not offered in the inserter's Media tab                                                 |
 * | remote_patterns | Patterns from the wordpress.org Pattern Directory not loaded                                      |
 * | block_directory | Block Directory search (sends the search term) removed from the inserter                          |
 * | google_fonts    | Google Fonts collection removed from the Font Library (fonts can still be uploaded)               |
 * | bunny_fonts     | Bunny Fonts collection not offered (switched off: offered, files fetched by the server; see Bunny_Fonts) |
 * | login_languages | Language dropdown removed from the login screen                                                   |
 * | welcome_guide   | Editor Welcome Guides (images from s.w.org) closed by default for people who never opened them    |
 * | rest_users      | Visitors who are not logged in get no usernames from the REST users endpoint (no slug, no author link) and cannot filter users by slug or search them |
 * | request_log     | The request log (see Request_Log)                                                                  |
 *
 * Each switch except request_log blocks what it names: on means blocked. Also, without a switch: the blocked hosts
 * list (see Blocked_Hosts).
 *
 * @package Windrose\Helm
 */

declare( strict_types=1 );

namespace Windrose\Helm\Modules;

use Windrose\Helm\Bunny_Fonts;
use Windrose\Helm\Request_Log;
use Windrose\Helm\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy switches.
 */
final class Privacy {

	/** Version-check fields kept by update_data. */
	public const VERSION_CHECK_FIELDS = array( 'version', 'php', 'locale' );

	/**
	 * Hooks the items that are switched on.
	 *
	 * @return void
	 */
	public static function init(): void {
		$on = static fn( string $id ): bool => Settings::privacy_on( $id );

		if ( $on( 'request_log' ) ) {
			Request_Log::init();
		}
		Blocked_Hosts::init();
		if ( $on( 'update_data' ) ) {
			add_filter( 'core_version_check_query_args', array( __CLASS__, 'trim_version_check' ) );
			add_filter( 'http_request_args', array( __CLASS__, 'anonymize_wporg_request' ), 10, 2 );
		}
		if ( $on( 'site_address' ) ) {
			add_filter( 'http_request_args', array( __CLASS__, 'user_agent_without_address' ), 10, 2 );
		}
		if ( $on( 'rest_users' ) ) {
			add_filter( 'rest_prepare_user', array( __CLASS__, 'hide_username' ) );
			add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'refuse_user_probing' ), 10, 3 );
		}
		if ( $on( 'dashboard_news' ) ) {
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'remove_news_widget' ), 999 );
			add_action( 'wp_network_dashboard_setup', array( __CLASS__, 'remove_news_widget' ), 999 );
			add_action( 'wp_user_dashboard_setup', array( __CLASS__, 'remove_news_widget' ), 999 );
			add_action( 'wp_ajax_get-community-events', array( __CLASS__, 'refuse_news_ajax' ), 0 );
			add_action( 'wp_ajax_dashboard-widgets', array( __CLASS__, 'refuse_news_ajax' ), 0 );
		}
		if ( $on( 'happy_checks' ) ) {
			add_filter( 'pre_http_request', array( __CLASS__, 'block_happy_checks' ), 20, 3 );
		}
		if ( $on( 'emoji' ) ) {
			self::remove_emoji();
			// WordPress adds its admin emoji hooks after plugins load (wp-admin/includes/admin-filters.php).
			add_action( 'admin_init', array( __CLASS__, 'remove_emoji' ), 0 );
		}
		if ( $on( 'gravatar' ) ) {
			add_filter( 'pre_get_avatar_data', array( __CLASS__, 'local_avatar' ), 1000 );
		}
		if ( $on( 'openverse' ) ) {
			add_filter( 'block_editor_settings_all', array( __CLASS__, 'no_openverse' ) );
		}
		if ( $on( 'remote_patterns' ) ) {
			add_filter( 'should_load_remote_block_patterns', '__return_false' );
		}
		if ( $on( 'block_directory' ) ) {
			remove_action( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' );
		}
		if ( $on( 'google_fonts' ) ) {
			add_action( 'init', array( __CLASS__, 'remove_google_fonts' ), 20 );
		}
		if ( ! $on( 'bunny_fonts' ) ) {
			Bunny_Fonts::offer();
		}
		if ( $on( 'login_languages' ) ) {
			add_filter( 'login_display_language_dropdown', '__return_false' );
		}
		if ( $on( 'welcome_guide' ) ) {
			add_filter( 'default_user_metadata', array( __CLASS__, 'welcome_guides_closed' ), 10, 4 );
		}
	}

	/**
	 * Keeps only the WordPress version, PHP version and locale in version checks (`core_version_check_query_args`).
	 *
	 * @param mixed $query Query arguments.
	 * @return mixed
	 */
	public static function trim_version_check( $query ) {
		if ( ! is_array( $query ) ) {
			return $query;
		}
		return array_intersect_key( $query, array_flip( self::VERSION_CHECK_FIELDS ) );
	}

	/**
	 * Sends wordpress.org requests without the site address (`http_request_args`): plain "WordPress/<version>"
	 * User-Agent, no wp_install or wp_blog headers.
	 *
	 * @param mixed $args Request arguments.
	 * @param mixed $url  Request address.
	 * @return mixed
	 */
	public static function anonymize_wporg_request( $args, $url ) {
		if ( ! is_array( $args ) || ! is_string( $url ) || ! Updates::is_wporg_host( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			return $args;
		}
		$args['user-agent'] = 'WordPress/' . wp_get_wp_version();
		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( array_keys( $args['headers'] ) as $name ) {
				if ( in_array( strtolower( (string) $name ), array( 'wp_install', 'wp_blog', 'user-agent' ), true ) ) {
					unset( $args['headers'][ $name ] );
				}
			}
		}
		return $args;
	}

	/**
	 * Removes the site address from WordPress's default User-Agent, for every server (`http_request_args`). Other
	 * User-Agents (set by a plugin for its own service) are left alone.
	 *
	 * @param mixed $args Request arguments.
	 * @param mixed $url  Request address (unused).
	 * @return mixed
	 */
	public static function user_agent_without_address( $args, $url = '' ) {
		unset( $url );
		if ( is_array( $args ) && is_string( $args['user-agent'] ?? null ) ) {
			$args['user-agent'] = (string) preg_replace( '#^(WordPress/[^;\s]+);\s*https?://\S*$#', '$1', $args['user-agent'] );
		}
		return $args;
	}

	/**
	 * Removes the username (slug) and the author link (which holds it) from REST user data for visitors who are not
	 * logged in (`rest_prepare_user`).
	 *
	 * @param mixed $response The response.
	 * @return mixed
	 */
	public static function hide_username( $response ) {
		if ( is_user_logged_in() || ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			unset( $data['slug'], $data['link'] );
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Refuses the users endpoint's slug and search filters to visitors who are not logged in, so usernames cannot be
	 * guessed one by one (`rest_request_before_callbacks`).
	 *
	 * @param mixed $response Response so far.
	 * @param mixed $handler  Route handler (unused).
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public static function refuse_user_probing( $response, $handler, $request ) {
		unset( $handler );
		// Core passes null, or an earlier error (kept as it is). Core matches routes in any letter case, so this check does too.
		if ( null !== $response || ! $request instanceof \WP_REST_Request || is_user_logged_in() || ! str_starts_with( strtolower( $request->get_route() ), '/wp/v2/users' ) ) {
			return $response;
		}
		if ( ! empty( $request->get_param( 'slug' ) ) || ! empty( $request->get_param( 'search' ) ) ) {
			return new WP_Error( 'windrose_helm_user_filters', __( 'Filtering users by username or searching them is only available to logged-in people.', 'windrose-helm' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return $response;
	}

	/**
	 * Removes the Events and News widget.
	 *
	 * @return void
	 */
	public static function remove_news_widget(): void {
		foreach ( array( 'dashboard', 'dashboard-network', 'dashboard-user' ) as $screen ) {
			remove_meta_box( 'dashboard_primary', $screen, 'side' );
			remove_meta_box( 'dashboard_primary', $screen, 'normal' );
		}
	}

	/**
	 * Refuses the AJAX calls that would load events and news.
	 *
	 * @return void
	 */
	public static function refuse_news_ajax(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which widget is asked for; the answer is a refusal.
		$widget = isset( $_GET['widget'] ) ? sanitize_key( wp_unslash( $_GET['widget'] ) ) : '';
		if ( 'wp_ajax_get-community-events' === current_action() || 'dashboard_primary' === $widget ) {
			wp_send_json_error();
		}
	}

	/**
	 * Stops the Browse Happy and Serve Happy requests (`pre_http_request`). WordPress then shows no browser or PHP
	 * nag; Site Health's PHP test is replaced (see Health).
	 *
	 * @param mixed $pre  False, or an earlier answer.
	 * @param mixed $args Request arguments.
	 * @param mixed $url  Request address.
	 * @return mixed
	 */
	public static function block_happy_checks( $pre, $args, $url ) {
		if ( false !== $pre || ! is_string( $url ) ) {
			return $pre;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 'api.wordpress.org' !== $host || ( ! str_starts_with( $path, '/core/browse-happy/' ) && ! str_starts_with( $path, '/core/serve-happy/' ) ) ) {
			return $pre;
		}
		Request_Log::record( $url, is_array( $args ) ? $args : array(), 'blocked' );
		return new WP_Error( 'windrose_helm_privacy', __( 'This request is switched off in Windrose Helm, Privacy.', 'windrose-helm' ) );
	}

	/**
	 * Removes the emoji script, styles and image links.
	 *
	 * @return void
	 */
	public static function remove_emoji(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'embed_head', 'print_emoji_detection_script' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * Serves every avatar from this plugin instead of Gravatar (`pre_get_avatar_data`), unless another plugin already
	 * set a local avatar.
	 *
	 * @param mixed $args Avatar arguments.
	 * @return mixed
	 */
	public static function local_avatar( $args ) {
		if ( ! is_array( $args ) || ! empty( $args['url'] ) ) {
			return $args;
		}
		$args['url']          = plugins_url( 'assets/avatar.svg', WINDROSE_HELM_FILE );
		$args['found_avatar'] = false;
		return $args;
	}

	/**
	 * Removes Openverse from the inserter's Media tab (`block_editor_settings_all`).
	 *
	 * @param mixed $settings Editor settings.
	 * @return mixed
	 */
	public static function no_openverse( $settings ) {
		if ( is_array( $settings ) ) {
			$settings['enableOpenverseMediaCategory'] = false;
		}
		return $settings;
	}

	/**
	 * Removes the Google Fonts collection from the Font Library.
	 *
	 * @return void
	 */
	public static function remove_google_fonts(): void {
		if ( function_exists( 'wp_unregister_font_collection' ) && \WP_Font_Library::get_instance()->get_font_collection( 'google-fonts' ) ) {
			wp_unregister_font_collection( 'google-fonts' );
		}
	}

	/**
	 * Starts the editors with their Welcome Guides closed, for people who have no stored editor preferences yet
	 * (`default_user_metadata`). Once someone changes a preference, theirs are stored and used.
	 *
	 * @param mixed $value     Default value.
	 * @param mixed $object_id User ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $single    Whether one value is asked for.
	 * @return mixed
	 */
	public static function welcome_guides_closed( $value, $object_id, $meta_key, $single ) {
		global $wpdb;
		if ( $wpdb->get_blog_prefix() . 'persisted_preferences' !== $meta_key ) {
			return $value;
		}
		$preferences = array(
			'core/edit-post'         => array(
				'welcomeGuide'         => false,
				'welcomeGuideTemplate' => false,
			),
			'core/edit-site'         => array(
				'welcomeGuide'         => false,
				'welcomeGuidePage'     => false,
				'welcomeGuideStyles'   => false,
				'welcomeGuideTemplate' => false,
			),
			'core/edit-widgets'      => array( 'welcomeGuide' => false ),
			'core/customize-widgets' => array( 'welcomeGuide' => false ),
		);
		return $single ? $preferences : array( $preferences );
	}
}

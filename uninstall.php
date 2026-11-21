<?php
/**
 * Uninstall Windrose Helm: removes its network options (switches, blocked hosts, request log, recommended plugins list, set-up state), the main site's
 * old-address redirect switch (Make a network) and cached update checks.
 *
 * @package Windrose\Helm
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'windrose_helm_modules', 'windrose_helm_defaults', 'windrose_helm_privacy', 'windrose_helm_wporg', 'windrose_helm_request_log', 'windrose_helm_blocked_hosts', 'windrose_helm_recommended', 'windrose_helm_setup' ) as $windrose_helm_option ) {
	delete_site_option( $windrose_helm_option );
}
delete_site_transient( 'windrose_common_release_windrose-helm' );
if ( is_multisite() ) {
	// The redirects of old post addresses after Make a network: without Windrose Helm they stop anyway.
	delete_blog_option( get_main_site_id(), 'windrose_helm_old_addresses' );
}

<?php
/**
 * The default list of recommended plugins: actively maintained, open source, on wordpress.org. Network administrators
 * can change the list in Windrose Helm, Recommended plugins (stored in windrose_helm_recommended); "Restore the
 * default list" brings this one back.
 *
 * Keys: slug (wordpress.org slug, also the plugin folder), name, category (see Recommended::categories()),
 * why (translatable), network (activate network-wide; false: activate on each site that needs it).
 * Page builders and classic-editing plugins are never listed.
 *
 * @package Windrose\Helm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	array(
		'slug'     => 'multisite-language-switcher',
		'name'     => 'Multisite Language Switcher',
		'category' => 'multilingual',
		'why'      => __( 'Links the language versions of your content across sites (one site per language) and adds a language switcher and hreflang links. Needed by Windrose Sabir.', 'windrose-helm' ),
		'network'  => true,
	),
	array(
		'slug'     => 'multisyde',
		'name'     => 'MultiSyde',
		'category' => 'network',
		'why'      => __( 'Practical improvements for multisite networks, by the author of Multisite Language Switcher.', 'windrose-helm' ),
		'network'  => true,
	),
	array(
		'slug'     => 'accessibility-checker',
		'name'     => 'Accessibility Checker',
		'category' => 'accessibility',
		'why'      => __( 'Checks posts and pages for accessibility problems (WCAG) while you edit, with explanations to fix them.', 'windrose-helm' ),
		'network'  => false,
	),
	array(
		'slug'     => 'accessibility-new-window-warnings',
		'name'     => 'Accessibility New Window Warnings',
		'category' => 'accessibility',
		'why'      => __( 'Warns visitors, including screen reader users, when a link opens in a new window, as WCAG asks.', 'windrose-helm' ),
		'network'  => true,
	),
	array(
		'slug'     => 'fluent-smtp',
		'name'     => 'FluentSMTP',
		'category' => 'email',
		'why'      => __( 'Sends the network’s emails through a real mail service (SMTP or an email provider), so they arrive, with a log of what was sent.', 'windrose-helm' ),
		'network'  => true,
	),
	array(
		'slug'     => 'redirection',
		'name'     => 'Redirection',
		'category' => 'redirects',
		'why'      => __( 'Manages redirects and shows which missing pages (404) visitors ask for, site by site.', 'windrose-helm' ),
		'network'  => false,
	),
	array(
		'slug'     => 'stream',
		'name'     => 'Stream',
		'category' => 'activity',
		'why'      => __( 'Keeps an activity log: who changed what and when (logins, content, settings, plugins), across the network.', 'windrose-helm' ),
		'network'  => true,
	),
);

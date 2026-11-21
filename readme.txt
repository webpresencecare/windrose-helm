=== Windrose Helm ===
Contributors: patriciabt
Tags: multisite, privacy, block themes, updates, site health
Requires at least: 7.2
Tested up to: 7.2
Requires PHP: 8.4
Stable tag: 0.6.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The base of the Windrose Suite for multisite networks: block-only defaults, privacy switches, a wordpress.org switch and health checks.

== Description ==

Windrose Helm adds a network admin screen with five tabs:

* **Overview**: one switch per module.
* **Defaults**: block themes only, no Customizer or Widgets screens for block themes, no classic editing plugins, no Classic block in the inserter. On a network that already uses a classic theme or the Classic Editor, the first three start switched off and Site Health reports instead.
* **Privacy**: fourteen switches that block what the network sends to other services and shows to visitors; a switch turned on means blocked (update check details, site address in requests, Events and News, Browse Happy and Serve Happy, emoji, Gravatar, Openverse, Pattern Directory, Block Directory, Google Fonts, Bunny Fonts, login language menu, editor Welcome Guides, usernames in the REST API). A list of blocked hosts: WordPress answers their requests itself, with an error, and never contacts them. A log of the last 200 outgoing requests (server and path only), with its own switch.
* **Bunny Fonts** in the Font Library (off until "Block the Bunny Fonts collection" is turned off): the Site Editor's Install Fonts tab offers the Bunny Fonts collection next to Google Fonts. The server of the network downloads the fonts someone previews or installs, so browsers never contact Bunny Fonts, and installed fonts are served from the site like any Font Library font. Each weight keeps all the scripts of its family (Latin, Cyrillic, Greek, Arabic, Hebrew, Devanagari…), and pages load only the scripts their text needs. Japanese, Korean and Chinese families are not offered.
* **Updates**: switch wordpress.org off. Update checks then get an empty answer locally and every other request to wordpress.org is stopped; updates become manual and WordPress says so. Windrose plugins keep updating from their GitHub releases, with a checksum check.
* **Recommended plugins**: a list you can change, with Install and Activate buttons.

On a network installed from the Windrose Suite zip, Windrose Helm also shows **Set up** until it is finished: the choices left after installation (subdirectories or subdomains while the network has one site; the main language and, for a multilingual network, one site per language linked by Windrose Sabir; Windrose Anchors and recommended plugins; privacy and wordpress.org). After logging in, network administrators land there. Once finished, the screen and its routes are gone for good. Networks not installed from the zip never see it.

Site Health gains tests for block themes, classic editing plugins, page builders, the wordpress.org switch and Windrose updates.

Windrose Helm works on networks of sites. Activated on a single site, it offers Tools, Make a network: it turns the site into a network without editing files by hand (or shows the lines when it may not write them), keeps the post address structure with /blog in front and redirects the old addresses, then opens Set up. Copies of wp-config.php and .htaccess as they were are saved first (wp-config.backup.php, .htaccess.backup), to delete once everything works.

== External services ==

Windrose Helm itself sends nothing about visitors. It contacts these services:

* **GitHub API** (api.github.com), to check for and download updates of installed Windrose plugins (plugins whose Update URI is a webpresencecare repository on GitHub). Sent: the repository name and a "Windrose" User-Agent. No site address or other site data. Downloads come from GitHub's release download host. GitHub terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service, privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

* **Bunny Fonts** (fonts.bunny.net, by BunnyWay), only while a network administrator has turned off "Block the Bunny Fonts collection" (it is on by default), and only when someone who may install fonts on a site opens Install Fonts, Bunny Fonts in the Font Library: the server of the network downloads the font files they preview or install. Sent: the file's address and a "WordPress/<version>" User-Agent, from the server's IP address. No site address, no data about the person or visitors; browsers never contact Bunny Fonts. The list of families ships with the plugin. Bunny terms: https://bunny.net/tos/, privacy: https://bunny.net/privacy/, about Bunny Fonts: https://fonts.bunny.net/about

Requests WordPress itself makes to wordpress.org are reduced by the Privacy switches, or stopped when wordpress.org is switched off.

== Privacy ==

The request log keeps server addresses and paths only, never query strings, content or personal data, and is visible to network administrators only. Suggested privacy policy text is added under Settings, Privacy, Policy guide. Fonts installed from the Bunny Fonts collection are served from the site; visitors never contact Bunny Fonts.

== Uninstall ==

Uninstalling removes the network options of Windrose Helm (switches, blocked hosts, request log, recommended plugins list, set-up state).

== Changelog ==

= 0.6.3 =
* Windrose Common 1.3.0: updates come from the public GitHub repositories, without a token (WordPress 7.2 RC no longer has the Secrets API, where the token was kept).
* Updates tab: the GitHub token field is gone.

= 0.6.2 =
* Notices use WordPress's own notice function (`wp_admin_notice()`).
* Tables: WordPress's newest DataViews; when rows are selected, the actions now show at the top of the table.
* Documentation: WordPress 7.2 beta 5 needs `WP_SECRETS_KEY` in `wp-config.php` before a GitHub token can be saved.
* Code: all WordPress packages on their newest releases.

= 0.6.1 =
* Privacy fix: "Usernames hidden from visitors in the REST API" also refuses the username and search filters when the address is written in capital letters (/wp/v2/USERS), which WordPress accepts.
* Overview: the Status card says wordpress.org is on while the Updates module is off, as it is.
* wordpress.org off: update data cached while it was on no longer shows wordpress.org's updates (whose icons the Updates screen loaded from ps.w.org); Windrose updates from GitHub stay.
* "No classic editing plugins": Classic Editor, Classic Widgets and Disable Gutenberg can no longer be activated at all (bulk actions, a site's Plugins screen, the REST API), not only without their Activate link.
* The Recommended plugins module switch now works: switched off, the tab shows no list and its routes refuse.
* Windrose Common 1.2.0: plugin updates and other release files share one asset check (exact download address) and one size and checksum check.
* Bunny Fonts: the index of scripts and their letters is a PHP file, which PHP keeps in memory, instead of a JSON file decoded on every page that uses a Bunny font with several scripts.

= 0.6.0 =
* Privacy: Bunny Fonts in the Font Library. A new switch, "Block the Bunny Fonts collection", on by default, next to Google's. Turned off, the Site Editor's Install Fonts tab offers Bunny Fonts' families (Japanese, Korean and Chinese families excepted). Previews and downloads go through the site: its server fetches the files from fonts.bunny.net (Blocked hosts and the request log apply), browsers never contact Bunny Fonts, and installed fonts are served from the site. Each weight keeps every script of its family; pages load only the scripts their text needs.

= 0.5.0 =
* Privacy: blocked hosts. Network administrators list hosts WordPress must never contact (a host also covers its subdomains; an address copied from the request log keeps only its host). Requests to them, and redirects into them, get a local error at once and are logged as Blocked. This network's own addresses and wordpress.org (switched off on the Updates tab) cannot be blocked.
* Privacy wording: every switch now says what it blocks ("Block Gravatar", "Block emoji images from s.w.org"…), so on always means blocked; a sentence at the top of the tab says so. The request log switch moved into the Outgoing requests section, as it records instead of blocking. Set up's privacy step uses the same labels.

= 0.4.0 =
* Make a network: on a single site, Tools, Make a network turns it into a network (subdirectories, or subdomains where possible; post addresses kept with /blog in front, old addresses redirected; wp-config.php and .htaccess written or shown, with copies of both as they were), then Set up opens.
* Set up: on networks installed from the Windrose Suite zip (Windrose Shiplaunch) or made with Make a network, a screen with the choices left after installation: addresses (subdomains while the network has one site; the screen says they need wildcard DNS and a certificate), main language, one site per language (address and title proposed, "<network name> - <language code>" also for the main site, all changeable; core's default content in each site's language, the first post in blocks) linked as a Windrose Sabir language group (WordPress's untouched first post and sample page linked as translations) with Multisite Language Switcher installed from wordpress.org (names, no flags), Windrose Anchors with every site allowed, recommended plugins, privacy and wordpress.org. Each task shows its result; a failed one can be tried again or skipped. Finishing closes the screen and its routes for good.

= 0.3.0 =
* Privacy: two new switches, on by default. "No site address in requests to other servers" removes the network's address from WordPress's User-Agent for every server. "Usernames hidden from visitors in the REST API" removes usernames from the public users endpoint and refuses its username and search filters to visitors.

= 0.2.0 =
* Recommended plugins: the list can be changed in the network admin (add a plugin by slug or wordpress.org address, edit its category, reason and activation, remove, restore the default list). Default list: Multisite Language Switcher, MultiSyde, Accessibility Checker, Accessibility New Window Warnings, FluentSMTP, Redirection, Stream. Shows on how many sites a plugin is active.

= 0.1.0 =
* First version: Defaults, Privacy with request log, Updates (wordpress.org switch and Windrose updates from GitHub), Recommended plugins, Site Health tests.

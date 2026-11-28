# Windrose Helm

> Part of **[the Windrose Suite](https://www.webpresencecare.com/windrose/)**: WordPress for networks of sites, private by default, multilingual if you want it, free for everyone. Each Windrose plugin and theme works on its own.

The base plugin of the Windrose Suite, for WordPress multisite networks: block-only defaults, privacy switches with a log of outgoing requests, a wordpress.org switch, updates for Windrose plugins from GitHub, recommended plugins and Site Health checks.

Requires WordPress 7.2 and PHP 8.4. Network-activated only.

## What it does

Network admin, **Windrose Helm**, five tabs. Each module has one switch on the Overview tab.

| Module | Switches | Notes |
|---|---|---|
| Defaults | Block themes only; no Customizer or Widgets screens; no classic editing plugins; no Classic block in the inserter | On a network that already uses a classic theme or the Classic Editor, the first three start off |
| Privacy | 14 blocking switches (on means blocked) and the request log switch, all on by default (see below) | Plus the blocked hosts list, the request log and the Bunny Fonts collection |
| Updates | wordpress.org on (default) or off | Off: update checks answered empty locally, every other wordpress.org and w.org request refused, automatic core and translation updates off, "Updates are manual" on Updates, Add Plugins, Add Themes and Site Health |
| Recommended plugins | | A list network administrators can change (add by slug or wordpress.org address, edit, remove, restore the default list in `data/recommended.php`). Install and Activate through WordPress's own REST plugins endpoint |

Privacy switches. Each label says what it blocks: a switch turned on means blocked (the tab says so at the top). The request log switch sits in the Outgoing requests section, since it records instead of blocking.

| Switch | Effect |
|---|---|
| Block extra data in update checks | Version checks send only WordPress version, PHP version and locale; wordpress.org requests carry `WordPress/<version>` as User-Agent and no `wp_install` or `wp_blog` header |
| Block the site address in requests to other servers | WordPress's default User-Agent `WordPress/<version>; <site address>` becomes `WordPress/<version>` for every server; other User-Agents are left alone |
| Block Events and News on the dashboard | Removed from site, network and user dashboards; its AJAX calls refused |
| Block the browser and PHP checks | Browse Happy and Serve Happy not sent; Site Health's PHP test compares with PHP 8.4 instead |
| Block emoji images from s.w.org | No detection script, styles or s.w.org images |
| Block Gravatar | `assets/avatar.svg` for everyone (unless another plugin already set a local avatar); off shows people's Gravatar pictures |
| Block Openverse in the editor | `enableOpenverseMediaCategory` false |
| Block patterns from the Pattern Directory | `should_load_remote_block_patterns` false |
| Block the Block Directory search | Block Directory assets not loaded in the editor |
| Block the Google Fonts collection | Collection unregistered; fonts can still be uploaded |
| Block the Bunny Fonts collection | Collection not registered. Off: Install Fonts offers Bunny Fonts, files fetched by the server (see below) |
| Block the language menu on the login screen | `login_display_language_dropdown` false |
| Block the editor Welcome Guides | Default editor preferences with the guides closed, for people without stored preferences |
| Block usernames in the REST API for visitors | For visitors who are not logged in: no `slug` and no `link` (author archive address) in `wp/v2/users`, and its `slug` and `search` filters answer 401. Display names stay public |
| Log outgoing requests | Last 200 server-side requests: time, site, context, method, host, path, outcome, HTTP status, source. Never query strings, headers or bodies; path segments that look like tokens are masked |

**Blocked hosts** (Privacy tab, while the Privacy module is on; empty by default): hosts WordPress never contacts. Every request through WordPress's HTTP API to a listed host or one of its subdomains gets a `WP_Error` (`windrose_helm_blocked_host`) at `pre_http_request` (priority 1), before any other answer; a redirect into one is stopped too (`requests-requests.before_redirect`, the caller gets `http_request_failed`). Each stop is logged as Blocked. Entries are host names or IP addresses, lowercase; an address keeps only its host, a leading `*.` is dropped. Refused: wordpress.org hosts (the Updates tab switches wordpress.org off and says updates are manual) and anything covering one of the network's own addresses (loopback requests run cron and Site Health); the current network and site addresses are also never blocked when the option was written by hand. At most 100 hosts. Not covered: files the browser loads, and code that sends requests without WordPress's HTTP API. First use: `llm.automatorplugin.com` (Uncanny Automator's AI credits check, which some sites see refused with 403 on every admin page; Automator treats the local error as it treats the 403 and waits before retrying).

### Bunny Fonts in the Font Library

While the Privacy module is on and **Block the Bunny Fonts collection** is off, `Bunny_Fonts` registers the collection `bunny-fonts` (Site Editor, Styles, Typography, Manage fonts, Install Fonts) next to Google Fonts.

- **The list ships with Helm**: `data/bunny-fonts.json` (core's collection shape, one face per weight and style, `src` = the family's script files on fonts.bunny.net, default script first, at most 20, the PHP upload limit of one request) and `data/bunny-fonts-index.json` (per family: scripts, faces, unicode ranges that differ from the usual range of a script), also written as `data/bunny-fonts-index.php`, the file Helm loads (PHP's opcode cache keeps it in memory; the JSON is its source, not in the release zip). Built by `bin/bunny-fonts.py build` from https://fonts.bunny.net/list and each family's CSS; CI runs `bin/bunny-fonts.py check`. Families cut into numbered slices (Japanese, Korean, Chinese) are left out. Sites never download the list.
- **The server fetches the files**: core's editor downloads previews and files in the browser, so `rest_prepare_font_collection` points every file at `windrose-helm/v1/bunny-fonts/<check>/<family>/<file>.woff2` on the site. The check is a nonce of the person (action `windrose_helm_bunny_fonts`) in the path, because the editor sends cookies but no REST nonce and names the upload after the last path segment. The route restores the person from WordPress's login cookie only when the check is theirs and they have `edit_theme_options` on that site, accepts only file names of the collection (family, script, weight, style), fetches `https://fonts.bunny.net/<family>/files/<file>` with `wp_safe_remote_get()` (no redirects, at most 5 MB, User-Agent `WordPress/<version>`; Blocked hosts and the request log apply), checks it is WOFF2 and sends it as `font/woff2`, kept a week by that browser. No browser contacts Bunny, so core's Google-only Allow step is not needed.
- **Pages load only the scripts they need**: core prints a face with several files as one `src` with fallbacks (only the first file would be used), so `wp_theme_json_data_user` splits each installed face whose files are all Bunny script files of its weight and style into one face per script with Bunny's `unicode-range`. Runs whatever the switch says (fonts already installed keep working). Without Helm such a face shows its first script (Latin for most families).

### Make a network (single sites)

Activated on a single site, Windrose Helm only offers **Tools, Make a network** (capability `setup_network`) and a notice linking to it. The screen explains what changes, then asks: subdirectories (always offered) or subdomains (only where core allows them: not in a folder, not localhost or an IP; with a wildcard DNS confirmation), the main site's post addresses (keep the current structure with `/blog` in front, the default, or core's `/blog/%year%/%monthnum%/%day%/%postname%/`), the network title, and a backup confirmation. Then, as core's Network Setup: `install_network()`, `populate_network()` (the current user becomes super admin), the network language, plugins that only work network-wide (Windrose Helm, Anchors, Sabir…) network-active and the others kept on the main site, `windrose_helm_setup` pending, the constants into `wp-config.php` and the rules into `.htaccess` (or the lines to add). `wp-config.php` is read and written through core's Filesystem API, only where WordPress may write files directly (as for its own updates without FTP details; elsewhere the lines to add, never an FTP form), and keeps its permissions. Before anything changes, copies of both files are saved next to them with the same permissions, `wp-config.backup.php` (a `.php` file: run, never shown, as it holds the database password) and `.htaccess.backup` (Apache refuses `.ht*` files); never an earlier copy overwritten (a date is added); kept only when the file was changed; the result screen names them and says to delete them once everything works. After logging in again, Set up opens. Refused when network tables already exist or the address has a port.

WordPress's guess for missing addresses does not find posts whose structure got `/blog` in front, so `Old_Addresses` redirects (301) a missing main-site address to its `/blog/` form when that is a post (main site option `windrose_helm_old_addresses`, set by the conversion, removed on uninstall).

### Set up (networks installed from the Windrose Suite zip)

The installer drop-in of **Windrose Shiplaunch** (https://github.com/webpresencecare/windrose-shiplaunch) creates the network and stores `windrose_helm_setup` with status `pending`. While it is pending (also after Make a network), network administrators get **Windrose Helm, Set up** (first menu item; the menu's top-level link goes there), a notice on admin screens, and a redirect there after logging in (unless the login asked for another page). Steps:

1. **Addresses**: the screen says subdomains need a wildcard DNS record and an HTTPS certificate covering them, and that a later switch is possible by hand; subdirectories (kept) or subdomains, only while the network has one site and `wp-config.php` holds the installer's `SUBDOMAIN_INSTALL` line; the switch also drops the main site's `/blog` permalink prefix and writes the subdomain rules into `.htaccess` (or shows them).
2. **Languages**: the main site's language (also the network default); multilingual: the main site's title (proposed `<network name> - <LANGUAGE CODE>`), then languages to add, each with its address (proposed: the language code) and title (proposed: `<network name> - <LANGUAGE CODE>`), all changeable. Installs MSLS from wordpress.org, activates Windrose Sabir, creates one site per language and one language group with the main site as its main site. Each site gets its title ("Test - DE" for `de_CH` unless changed) and core's default post, page and comment in its own language; while the network option `first_post` is still the text WordPress stored at installation (in the installer's language), the site gets its own language's first post instead, in blocks (a text the owner changed is kept). WordPress's default content still untouched on the group's sites (never modified since created) is linked as translations: the first post and the sample page (the privacy policy page too where several sites have one; WordPress gives new network sites none unless the network option `default_privacy_policy_content` is set) through Windrose Sabir (MSLS's links), so MSLS shows edit links instead of "add" links for them. MSLS settings saved on each site that has none: language name, current site shown, no flags (switcher "Description only", admin "Label").
3. **Plugins**: Windrose Anchors (activated, every existing site allowed) and recommended plugins (network ones installed and network-activated, per-site ones installed only).
4. **Privacy**: the wordpress.org switch, the 14 blocking privacy switches and the request log switch (all on).
5. **Review** (starting with "Afterwards this screen closes for good…"), then **Set up the network**: one task at a time, each with its result; a failed task can be tried again or skipped. Language packs and plugins are downloaded before wordpress.org is switched off, if it is.

Finishing stores status `done`: from the next request the screen, the notice, the redirect and every `setup` route are gone (routes answer 403 `windrose_helm_setup_done`). Networks without the option never see any of it. Plugins are installed and activated through core's `wp/v2/plugins` routes.

Site Health tests (each site): block theme, classic editing plugins, page builders, wordpress.org switch (replaces "Communication with WordPress.org" when off), PHP version (when Serve Happy is off), Windrose updates.

## Settings and data

| Network option | Holds |
|---|---|
| `windrose_helm_modules` | Module switches |
| `windrose_helm_defaults` | Default switches (written on first use with values that suit the network) |
| `windrose_helm_privacy` | Privacy switches |
| `windrose_helm_blocked_hosts` | Blocked hosts (a list of host names; absent when empty) |
| `windrose_helm_wporg` | 1 = wordpress.org on, 0 = off |
| `windrose_helm_request_log` | The request log |
| `windrose_helm_recommended` | The recommended plugins list, when changed from the default (empty texts mean the default, translated text) |
| `windrose_helm_setup` | Written by the Windrose Suite installer: `status` (`pending`, `done`), `source`, `version`, `time`, `finished`, and while pending `manual` (rewrite rules the installer could not write) |

Site transients `windrose_common_release_<slug>` cache GitHub answers.

**Uninstall** removes the eight network options and Helm's cached release.

REST routes (`windrose-helm/v1`, network administrators only, except `bunny-fonts/<check>/<family>/<file>`, see Bunny Fonts): `settings` (also the blocked hosts, `blocked_hosts`; a refused list saves nothing and answers 400), `request-log`, `updates`, `updates/check`, `recommended` (GET, POST, DELETE), `recommended/lookup`; while the set-up is pending `setup` (GET), `setup/addresses`, `setup/language` (also `install_languages`), `setup/main-language` (also the main site's title), `setup/site` (also `create_sites`), `setup/group` (needs Windrose Sabir), `setup/media` (needs Windrose Anchors), `setup/finish`. Filter `windrose_helm_setup_config_path` (which `wp-config.php` the subdomain switch changes; for tests).

## Windrose Common and updates

`common/` is the source of **Windrose Common**, the small library every Windrose plugin bundles. Each copy registers itself; on `plugins_loaded` (priority -1000) the newest copy is loaded once and fires `windrose_common_loaded`. Copy it into another plugin with `bin/sync-common.sh ../windrose-<name>` (the text domain becomes that plugin's). `common/bootstrap.php` must keep its shape; only its version line changes.

`Windrose\Common\Updater` answers WordPress's update check for every installed plugin whose `Update URI` is `https://github.com/webpresencecare/<its folder>`, from the latest GitHub release:

- not a draft or prerelease, tag `vX.Y.Z`, asset `<slug>.zip` in state `uploaded` with GitHub's sha256 `digest`, asset addresses in the same repository;
- before WordPress unpacks anything, the package is downloaded through the API, and its size, sha256 and `<slug>/` root folder are checked; anything else stops the update;
- cached 12 hours (1 hour when nothing usable was found); User-Agent `Windrose`, no site data;
- never for a plugin folder that is a git working copy (filter `windrose_common_update_checks`).

Other files of a release (Common 1.1.0, first used by Windrose Livery's font packs): `Updater::release_asset( $slug, $tag, $name, $max_bytes )` asks for the release of that exact tag, takes the asset of that exact name (state `uploaded`, GitHub's sha256 `digest`, addresses in the same repository, at most `$max_bytes`), downloads it the same way and checks its size and sha256. It returns the path of a temporary file (the caller deletes it) or a `WP_Error`.

Since Common 1.2.0 the plugin zip goes through the same asset check as other files (`parse_asset()`: exact download address `…/releases/download/<tag>/<slug>.zip`), and both downloads share one size and sha256 check.

Files (Common 1.4.0): `Files::read( $file )`, `Files::write( $file, $contents )` (an existing file keeps its permissions) and `Files::copy( $from, $to, $overwrite )` go through core's Filesystem API, always its direct method (`Files::direct()`): for uploads, temporary files and the plugin's own files, which WordPress writes with PHP on every host. Files of the installation (`wp-config.php`) only after `get_filesystem_method()` says `direct` for their folder (Helm's `Setup::filesystem()`): elsewhere Helm shows the lines to add by hand.

Hooks: action `windrose_common_loaded( $version )`; filter `windrose_common_update_checks( $enabled, $slug )`.

## Releasing

1. Set the version in the plugin header and the `WINDROSE_HELM_VERSION` constant; `npm run build`; run the checks; commit.
2. Tag `vX.Y.Z` and push the tag. `.github/workflows/release.yml` builds `windrose-helm.zip` with `bin/build-zip.sh` (which refuses a tag that does not match the version) and publishes the release.
3. Sites see the update within 12 hours, or at once with **Check now** on the Updates tab.

Local zip: `bin/build-zip.sh` writes `dist/windrose-helm.zip`.

## Development

The admin screens are React (`src/admin` and `src/setup`, built into `build/admin` and `build/setup`, committed) with WordPress components, DataForm and DataViews (`@wordpress/dataviews/wp`, bundled: WordPress does not provide it). After every build, `bin/guard-build.mjs` adds the direct access guard to the generated PHP files (`*.asset.php`); `bin/build-zip.sh` refuses a build without it.

```
npm install
npm run build
npx wp-scripts lint-js src
npx wp-scripts lint-style "src/**/*.scss"
```

PHP checks (`composer install` brings the tools):

```
vendor/bin/phpcs --standard=phpcs.xml.dist
vendor/bin/phpstan analyse -c phpstan.neon.dist
bin/install-wp-tests.sh /tmp/wp-tests           # WordPress nightly and the test library; DB_NAME, DB_USER, DB_PASSWORD, DB_HOST
WP_TESTS_DIR=/tmp/wp-tests/develop/tests/phpunit WP_TESTS_CONFIG_FILE_PATH=/tmp/wp-tests/wp-tests-config.php vendor/bin/phpunit
```

PHPUnit runs in multisite mode on its own test database, never on a site's data. The Playwright tests (`tests/e2e/`) run against a WordPress multisite with WP-CLI and Windrose Helm active; they create and delete their own throwaway sites and users. Settings: `WINDROSE_E2E_HOST` (the network's host, default `localhost`), `WINDROSE_E2E_USER` (a super admin, default `admin`) and `WINDROSE_PASS` (that user's password).

**On GitHub** (`.github/workflows/ci.yml`, every push to `main` and every pull request): `php -l` on PHP 8.4 and 8.5, PHPCS, PHPStan (PHP 8.4), JavaScript and style lint with a fresh build equal to the committed `build/`, PHPUnit multisite on PHP 8.4 and 8.5 against the WordPress nightly (`bin/install-wp-tests.sh`; it uses wordpress-develop `trunk` until WordPress creates its 7.2 branch). Wait for the green tick on the commit before tagging a release. `composer.json` only installs these check tools. Playwright stays local (it needs a running network).

For another Windrose plugin, copy `.github/workflows/`, `composer.json` (rename), `phpunit.xml.dist`, `phpcs.xml.dist` (text domain), `phpstan.neon.dist`, `tests/phpunit/bootstrap.php` (main file name) and `bin/install-wp-tests.sh`, `bin/build-zip.sh`.

Bunny Fonts data: `python3 -I bin/bunny-fonts.py build` (about 1,850 requests to fonts.bunny.net, three at a time; then commit the three files of `data/`); `reformat` rewrites both index files from the JSON index, `python3 -I bin/bunny-fonts.py check` (no network; CI). Refresh it before a release when Bunny's list has changed.

## Status

Updates come from this repository's GitHub releases: each download is checked against the sha256 digest GitHub publishes before it is installed. A wordpress.org version may come later, after independent reviews.

## Security

Please report vulnerabilities privately, never in a public issue: see [SECURITY.md](SECURITY.md).

## Contributing

Reviews, tests and issues are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md).

## How this code is written

I write this plugin with Claude Code (Anthropic's AI coding assistant): I design each feature, test it on real sites and review every release. Commits written with Claude say so in a `Co-Authored-By` line. Human review is especially welcome.

## Licence

GPL-2.0-or-later.

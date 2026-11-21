/**
 * Bunny Fonts in core's Font Library, on a throwaway site /bunny-e2e/ (deleted after the run, with its fonts).
 * The network's Bunny Fonts switch is turned off for the run and put back afterwards.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { test, expect } from '@playwright/test';
import { HOST, wp } from './helpers.mjs';

const SLUG = 'bunny-e2e';
const SITE = `/${ SLUG }/`;
const ROUTE = '/wp-json/windrose-helm/v1/bunny-fonts/';
const DEBUG_LOG = '/var/www/html/web/wp-content/debug.log';
/** What debug.log holds now (it may not exist). */
const debugLog = () => ( existsSync( DEBUG_LOG ) ? readFileSync( DEBUG_LOG, 'utf8' ) : '' );
const TEXT_AR = 'مرحبا بالعالم';
const TEXT_LATIN = 'Hello world';

/** WP-CLI on the throwaway site. */
function wpSite( ...args ) {
	return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=https://${ HOST }${ SITE }` ], {
		encoding: 'utf8',
	} ).trim();
}

/** The fonts the browser really used for an element's text (Chromium's own answer, also inside the editor's iframe). */
async function usedFonts( page, locator ) {
	await locator.first().evaluate( ( el ) => el.setAttribute( 'data-windrose-probe', '1' ) );
	const session = await page.context().newCDPSession( page );
	await session.send( 'DOM.enable' );
	await session.send( 'CSS.enable' );
	const { root } = await session.send( 'DOM.getDocument', { depth: -1, pierce: true } );
	const find = ( node ) => {
		if ( node.attributes?.includes( 'data-windrose-probe' ) ) {
			return node;
		}
		for ( const child of [ ...( node.children ?? [] ), ...( node.contentDocument ? [ node.contentDocument ] : [] ) ] ) {
			const found = find( child );
			if ( found ) {
				return found;
			}
		}
		return null;
	};
	const { fonts } = await session.send( 'CSS.getPlatformFontsForNode', { nodeId: find( root ).nodeId } );
	await session.detach();
	return fonts;
}

let privacyBefore = null;
let siteId = '';
let postId = '';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	try {
		privacyBefore = wp( 'site', 'option', 'get', 'windrose_helm_privacy', '--format=json' );
	} catch {
		privacyBefore = null;
	}
	const privacy = privacyBefore ? JSON.parse( privacyBefore ) : {};
	wp( 'site', 'option', 'update', 'windrose_helm_privacy', JSON.stringify( { ...privacy, bunny_fonts: false } ), '--format=json' );
	try {
		wp( 'site', 'delete', `--slug=${ SLUG }`, '--yes' );
	} catch {
		// No leftover from an earlier run.
	}
	siteId = wp( 'site', 'create', `--slug=${ SLUG }`, '--title=Bunny E2E', '--porcelain' );
	wpSite( 'theme', 'activate', 'ipsum' );
} );

test.afterAll( () => {
	if ( privacyBefore ) {
		wp( 'site', 'option', 'update', 'windrose_helm_privacy', privacyBefore, '--format=json' );
	} else {
		wp( 'site', 'option', 'delete', 'windrose_helm_privacy' );
	}
	wp( 'site', 'delete', `--slug=${ SLUG }`, '--yes' );
	// WordPress leaves the site's empty uploads folder behind.
	if ( /^\d+$/.test( siteId ) ) {
		rmSync( `/var/www/html/web/wp-content/uploads/sites/${ siteId }`, { recursive: true, force: true } );
	}
} );

test( 'Install Fonts offers Bunny Fonts, fetched through this site only', async ( { page, playwright } ) => {
	const logBefore = debugLog().length;
	const outside = [];
	const local = [];
	page.on( 'request', ( request ) => {
		const url = new URL( request.url() );
		if ( url.hostname !== HOST ) {
			outside.push( `${ url.hostname }${ url.pathname }` );
		} else if ( url.pathname.includes( ROUTE ) ) {
			local.push( request.url() );
		}
	} );

	await page.goto( `${ SITE }wp-admin/font-library.php` );
	await page.getByRole( 'tab', { name: 'Bunny Fonts' } ).click();
	await expect( page.getByText( 'This website’s server downloads the fonts' ) ).toBeVisible();
	await page.getByPlaceholder( 'Font name…' ).fill( 'Cairo' );
	await page.getByRole( 'button', { name: /^Cairo(?! Play)/ } ).click();
	// Previews are fonts loaded from this site.
	await expect.poll( () => local.length ).toBeGreaterThan( 0 );

	await page.getByRole( 'checkbox', { name: 'Cairo Normal' } ).check();
	await page.getByRole( 'button', { name: 'Install', exact: true } ).click();
	await expect( page.locator( '.components-notice__content' ).getByText( 'Fonts were installed successfully.' ) ).toBeVisible( { timeout: 60_000 } );

	expect( outside ).toEqual( [] );
	const arabic = local.find( ( url ) => url.endsWith( '/cairo/cairo-arabic-400-normal.woff2' ) );
	expect( arabic ).toBeTruthy();

	// The route sends the font as it is, kept by this browser only; refused without the person's login.
	const file = await page.request.get( arabic );
	expect( file.status() ).toBe( 200 );
	expect( file.headers()[ 'content-type' ] ).toBe( 'font/woff2' );
	expect( file.headers()[ 'cache-control' ] ).toBe( 'private, max-age=604800' );
	expect( ( await file.body() ).subarray( 0, 4 ).toString() ).toBe( 'wOF2' );
	const stranger = await playwright.request.newContext( { ignoreHTTPSErrors: true, storageState: { cookies: [], origins: [] } } );
	expect( ( await stranger.get( arabic ) ).status() ).toBe( 401 );
	await stranger.dispose();
	expect( debugLog().slice( logBefore ) ).not.toContain( 'windrose-helm' );

	const faces = JSON.parse( wpSite( 'post', 'list', '--post_type=wp_font_face', '--fields=post_title,post_content', '--format=json' ) );
	const regular = faces.find( ( face ) => face.post_title.includes( 'normal;400' ) );
	expect( regular, JSON.stringify( faces ) ).toBeTruthy();
	const src = JSON.parse( regular.post_content ).src;
	expect( src.length ).toBeGreaterThanOrEqual( 3 );
	expect( src.some( ( name ) => /cairo-arabic-400-normal(-\d+)?\.woff2$/.test( name ) ) ).toBe( true );

	// The server's requests are in Helm's request log, when the log is on.
	const logOn = JSON.parse( wp( 'site', 'option', 'get', 'windrose_helm_privacy', '--format=json' ) ).request_log !== false;
	if ( logOn ) {
		const log = JSON.parse( wp( 'site', 'option', 'get', 'windrose_helm_request_log', '--format=json' ) );
		expect( log.some( ( entry ) => entry.host === 'fonts.bunny.net' && entry.path.startsWith( '/cairo/files/' ) && entry.outcome === 'sent' ) ).toBe( true );
	}
} );

test( 'pages load only the scripts their text needs, in the installed font', async ( { page } ) => {
	postId = wpSite(
		'post',
		'create',
		'--post_status=publish',
		'--post_title=Bunny E2E',
		`--post_content=<!-- wp:paragraph {"fontFamily":"cairo"} --><p class="has-cairo-font-family windrose-probe">${ TEXT_AR } ${ TEXT_LATIN }</p><!-- /wp:paragraph -->`,
		'--porcelain'
	);
	const fonts = [];
	page.on( 'request', ( request ) => {
		if ( request.resourceType() === 'font' ) {
			fonts.push( new URL( request.url() ).pathname );
		}
	} );
	const response = await page.goto( `${ SITE }?p=${ postId }` );
	const html = await response.text();
	const rules = html.match( /@font-face\{font-family:Cairo;[^}]*\}/g ) ?? [];
	expect( rules.length, html.slice( 0, 2000 ) ).toBeGreaterThanOrEqual( 3 );
	for ( const rule of rules ) {
		expect( rule ).toContain( 'unicode-range' );
	}
	await page.evaluate( () => document.fonts.ready );

	const used = await usedFonts( page, page.locator( '.windrose-probe' ) );
	const cairo = used.find( ( font ) => font.familyName === 'Cairo' );
	expect( cairo, JSON.stringify( used ) ).toBeTruthy();
	expect( used.filter( ( font ) => font.familyName !== 'Cairo' ), 'No glyph from a fallback font.' ).toEqual( [] );
	expect( fonts.some( ( path ) => /cairo-arabic-400-normal/.test( path ) ) ).toBe( true );
	expect( fonts.some( ( path ) => /cairo-latin-400-normal/.test( path ) ) ).toBe( true );
	expect( fonts.some( ( path ) => /cairo-latin-ext-400-normal/.test( path ) ), 'Latin Extended is not needed for this text.' ).toBe( false );
} );

test( 'the editor shows the text in the installed font too', async ( { page } ) => {
	await page.goto( `${ SITE }wp-admin/post.php?post=${ postId }&action=edit` );
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await expect( canvas.locator( '.windrose-probe' ) ).toBeVisible( { timeout: 30_000 } );
	await page.frame( { name: 'editor-canvas' } ).evaluate( () => document.fonts.ready );
	const used = await usedFonts( page, canvas.locator( '.windrose-probe' ) );
	expect( used.filter( ( font ) => font.familyName !== 'Cairo' ), JSON.stringify( used ) ).toEqual( [] );
} );

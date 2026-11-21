/**
 * The Windrose Helm network admin screen.
 */
import { test, expect } from '@playwright/test';
import { expectAccessible, recordExternal, wp } from './helpers.mjs';

const SCREEN = '/wp-admin/network/admin.php?page=windrose-helm';
const TABS = [ 'Overview', 'Defaults', 'Privacy', 'Updates', 'Recommended plugins' ];

test.describe( 'Windrose Helm screen', () => {
	test( 'tabs work with the keyboard and pass WCAG 2.2 AA checks', async ( { page } ) => {
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		page.on( 'console', ( message ) => message.type() === 'error' && errors.push( message.text() ) );
		const external = recordExternal( page );

		await page.goto( SCREEN );
		await expect( page.getByRole( 'heading', { level: 1, name: 'Windrose Helm' } ) ).toBeVisible();
		await expect( page.getByRole( 'tab' ) ).toHaveText( TABS );

		await page.getByRole( 'tab', { name: 'Overview' } ).focus();
		for ( const name of TABS.slice( 1 ) ) {
			await page.keyboard.press( 'ArrowRight' );
			await expect( page.getByRole( 'tab', { name } ) ).toBeFocused();
			await page.keyboard.press( 'Enter' );
			await expect( page.getByRole( 'tab', { name } ) ).toHaveAttribute( 'aria-selected', 'true' );
			await expect( page.getByRole( 'tabpanel' ) ).not.toContainText( 'Loading' );
			await page.waitForLoadState( 'networkidle' );
			await expectAccessible( page, '#windrose-helm-app' );
		}
		await page.getByRole( 'tab', { name: 'Overview' } ).click();
		await expectAccessible( page, '#windrose-helm-app' );

		expect( errors ).toEqual( [] );
		expect( external ).toEqual( [] );
	} );

	test( 'a privacy switch is saved and restored', async ( { page } ) => {
		// Put the switches back as they were, even if the test fails.
		// The value as JSON (an option list gives the stored, serialized form, which update --format=json cannot read).
		const exists = wp( 'site', 'option', 'list', '--search=windrose_helm_privacy', '--format=count' ) !== '0';
		const before = JSON.stringify( exists ? [ { value: wp( 'site', 'option', 'get', 'windrose_helm_privacy', '--format=json' ) } ] : [] );
		test.info().annotations.push( { type: 'restore', description: before } );
		await page.goto( `${ SCREEN }&tab=privacy` );
		const toggle = page.getByRole( 'checkbox', { name: 'Block emoji images from s.w.org' } );
		await expect( toggle ).toBeChecked();
		await toggle.uncheck();
		await page.getByRole( 'button', { name: 'Save changes' } ).first().click();
		await expect( page.locator( '.components-notice' ).getByText( 'Settings saved.' ) ).toBeVisible();
		expect( JSON.parse( wp( 'site', 'option', 'get', 'windrose_helm_privacy', '--format=json' ) ).emoji ).toBe( false );

		await page.reload();
		await expect( page.getByRole( 'checkbox', { name: 'Block emoji images from s.w.org' } ) ).not.toBeChecked();
		await page.getByRole( 'checkbox', { name: 'Block emoji images from s.w.org' } ).check();
		await page.getByRole( 'button', { name: 'Save changes' } ).first().click();
		await expect( page.locator( '.components-notice' ).getByText( 'Settings saved.' ) ).toBeVisible();
		expect( JSON.parse( wp( 'site', 'option', 'get', 'windrose_helm_privacy', '--format=json' ) ).emoji ).toBe( true );
	} );

	test.afterEach( async ( {}, testInfo ) => {
		const restore = testInfo.annotations.find( ( a ) => a.type === 'restore' );
		if ( restore ) {
			const rows = JSON.parse( restore.description );
			if ( rows.length ) {
				wp( 'site', 'option', 'update', 'windrose_helm_privacy', rows[ 0 ].value, '--format=json' );
			} else {
				wp( 'site', 'option', 'delete', 'windrose_helm_privacy' );
			}
		}
	} );

	test( 'privacy switches read the same way: on means blocked', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=privacy` );
		const panel = page.getByRole( 'tabpanel' );
		await expect( panel ).toContainText( 'On this tab, a switch turned on blocks what it names' );
		const card = ( name ) => page.locator( '.components-card' ).filter( { has: page.getByRole( 'heading', { name } ) } );
		const labels = await card( 'Requests to other services' ).getByRole( 'checkbox' ).evaluateAll( ( boxes ) => boxes.map( ( box ) => box.labels[ 0 ].textContent ) );
		expect( labels ).toHaveLength( 14 );
		expect( labels.indexOf( 'Block the Bunny Fonts collection' ) ).toBe( labels.indexOf( 'Block the Google Fonts collection' ) + 1 );
		for ( const label of labels ) {
			expect( label ).toMatch( /^Block / );
		}
		expect( labels ).toContain( 'Block Gravatar' );
		await expect( card( 'Outgoing requests' ).getByRole( 'checkbox', { name: 'Log outgoing requests' } ) ).toBeVisible();
		await expectAccessible( page, '#windrose-helm-app' );
	} );

	test( 'blocked hosts: added, refused, used, unblocked', async ( { page } ) => {
		const host = 'llm.automatorplugin.com';
		try {
			await page.goto( `${ SCREEN }&tab=privacy` );
			const card = page.locator( '.components-card' ).filter( { has: page.getByRole( 'heading', { name: 'Blocked hosts' } ) } );
			await expect( card.getByText( 'No host is blocked.' ) ).toBeVisible();
			const field = card.getByLabel( 'Host to block' );

			// Refused: wordpress.org has its own switch. Nothing is saved, the field keeps the text.
			await field.fill( 'https://api.wordpress.org/plugins/' );
			await card.getByRole( 'button', { name: 'Block', exact: true } ).click();
			await expect( page.locator( '.components-notice.is-error' ) ).toContainText( 'Updates tab' );
			await expect( field ).toHaveValue( 'https://api.wordpress.org/plugins/' );

			// An address copied from a log keeps only its host.
			await field.fill( `https://${ host }/api/credits/allocation-facts` );
			await field.press( 'Enter' );
			await expect( page.locator( '.components-notice' ).getByText( 'Settings saved.' ) ).toBeVisible();
			await expect( field ).toHaveValue( '' );
			await expect( card.locator( '.dataviews-view-table tbody tr' ) ).toHaveText( [ new RegExp( host ) ] );
			expect( JSON.parse( wp( 'site', 'option', 'get', 'windrose_helm_blocked_hosts', '--format=json' ) ) ).toEqual( [ host ] );
			await expectAccessible( page, '#windrose-helm-app' );

			// A real request through WordPress: answered locally with an error, logged as Blocked.
			const answer = wp( 'eval', `$r = wp_remote_post( 'https://${ host }/api/credits/allocation-facts' ); echo is_wp_error( $r ) ? $r->get_error_code() : 'sent';` );
			expect( answer ).toBe( 'windrose_helm_blocked_host' );
			await page.reload();
			const log = page.locator( '.components-card' ).filter( { has: page.getByRole( 'heading', { name: 'Outgoing requests' } ) } );
			await expect( log.locator( '.dataviews-view-table tbody tr' ).filter( { hasText: host } ).first() ).toContainText( 'Blocked' );

			// Unblocked from its row.
			await card.locator( '.dataviews-view-table tbody tr' ).filter( { hasText: host } ).getByRole( 'button', { name: 'Unblock' } ).click();
			await expect( card.getByText( 'No host is blocked.' ) ).toBeVisible();
			expect( wp( 'eval', "var_export( get_site_option( 'windrose_helm_blocked_hosts', false ) );" ) ).toBe( 'false' );
		} finally {
			wp( 'eval', "delete_site_option( 'windrose_helm_blocked_hosts' );" );
		}
	} );

	test( 'the request log lists outgoing requests', async ( { page } ) => {
		// An update check, as WordPress's Updates screen makes one.
		await page.goto( '/wp-admin/network/update-core.php?force-check=1' );
		await page.goto( `${ SCREEN }&tab=privacy` );
		const table = page.locator( '.dataviews-view-table' );
		await expect( table ).toContainText( 'api.wordpress.org' );
		await expect( table ).toContainText( '/core/version-check/1.7/' );
		await page.getByRole( 'searchbox' ).fill( 'plugins/update-check' );
		await expect( table.locator( 'tbody tr' ).first() ).toContainText( '/plugins/update-check/1.1/' );
	} );

	test( 'updates tab lists Windrose plugins, with no token field', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=updates` );
		await expect( page.locator( '.dataviews-view-table' ) ).toContainText( 'Windrose Helm' );
		await expect( page.locator( '.dataviews-view-table' ) ).toContainText( 'git working copy' );
		await expect( page.getByText( /GitHub token/ ) ).toHaveCount( 0 );
		await expect( page.getByRole( 'checkbox', { name: 'Use wordpress.org' } ) ).toBeChecked();
	} );

	test( 'recommended plugins show their state', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=recommended` );
		const rows = page.locator( '.dataviews-view-table tbody tr' );
		await expect( rows ).toHaveCount( 7 );
		const msls = rows.filter( { has: page.getByRole( 'link', { name: /^Multisite Language Switcher/ } ) } );
		await expect( msls ).toContainText( 'Network active' );
		await expect( msls.getByRole( 'button', { name: 'Install' } ) ).toHaveCount( 0 );
		await expect( rows.filter( { has: page.getByRole( 'link', { name: /^Stream/ } ) } ).getByRole( 'button', { name: 'Install' } ) ).toBeVisible();
	} );

	test( 'with the Recommended module off, the tab shows no list', async ( { page } ) => {
		const before = wp( 'eval', "echo wp_json_encode( get_site_option( 'windrose_helm_modules', null ) );" );
		try {
			wp( 'eval', "update_site_option( 'windrose_helm_modules', array( 'recommended' => false ) + (array) get_site_option( 'windrose_helm_modules', array() ) );" );
			await page.goto( `${ SCREEN }&tab=recommended` );
			await expect( page.getByRole( 'tabpanel' ) ).toContainText( 'The Recommended plugins module is off (Overview tab).' );
			await expect( page.locator( '.dataviews-view-table' ) ).toHaveCount( 0 );
		} finally {
			wp( 'eval', 'null' === before ? "delete_site_option( 'windrose_helm_modules' );" : `update_site_option( 'windrose_helm_modules', json_decode( '${ before }', true ) );` );
		}
	} );

	test( 'the recommended list can be edited and restored', async ( { page } ) => {
		try {
			await page.goto( `${ SCREEN }&tab=recommended` );
			const rows = page.locator( '.dataviews-view-table tbody tr' );

			// Add, by wordpress.org address (looked up on wordpress.org).
			await page.getByRole( 'button', { name: 'Add a plugin' } ).click();
			await page.getByLabel( 'Plugin slug or wordpress.org address' ).fill( 'https://wordpress.org/plugins/query-monitor/' );
			await page.getByRole( 'button', { name: 'Look up' } ).click();
			const dialog = page.getByRole( 'dialog' );
			await expect( dialog ).toContainText( 'Query Monitor' );
			await expectAccessible( page, '[role="dialog"]' );
			await dialog.getByRole( 'button', { name: 'Add to the list' } ).click();
			await expect( rows ).toHaveCount( 8 );
			await expect( page.getByRole( 'button', { name: 'Restore the default list' } ) ).toBeVisible();

			// Edit.
			const stream = rows.filter( { has: page.getByRole( 'link', { name: /^Stream/ } ) } );
			await stream.getByRole( 'button', { name: 'Actions' } ).click();
			await page.getByRole( 'menuitem', { name: 'Edit' } ).click();
			await page.getByLabel( 'Why it is recommended' ).fill( 'Our activity log.' );
			await expectAccessible( page, '[role="dialog"]' );
			await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Save', exact: true } ).click();
			await expect( stream ).toContainText( 'Our activity log.' );

			// Remove.
			const added = rows.filter( { hasText: 'Query Monitor' } );
			await added.getByRole( 'button', { name: 'Actions' } ).click();
			await page.getByRole( 'menuitem', { name: 'Remove from the list' } ).click();
			await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Remove', exact: true } ).click();
			await expect( rows ).toHaveCount( 7 );

			// Restore.
			await page.getByRole( 'button', { name: 'Restore the default list' } ).click();
			await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Restore', exact: true } ).click();
			await expect( stream ).not.toContainText( 'Our activity log.' );
			await expect( page.getByRole( 'button', { name: 'Restore the default list' } ) ).toHaveCount( 0 );
		} finally {
			// Back to the default list whatever happened (the option may already be gone).
			wp( 'eval', 'delete_site_option( "windrose_helm_recommended" );' );
		}
	} );
} );

test.describe( 'Windrose Helm screen in Arabic (RTL)', () => {
	const login = 'windrosee2ertl';
	const password = `Rtl-${ Date.now() }-pass`;

	test.beforeAll( () => {
		wp( 'user', 'create', login, `${ login }@example.com`, `--user_pass=${ password }` );
		wp( 'user', 'meta', 'update', login, 'locale', 'ar' );
		wp( 'super-admin', 'add', login );
	} );

	test.afterAll( () => {
		wp( 'super-admin', 'remove', login );
		wp( 'user', 'delete', login, '--yes', '--network' );
	} );

	test( 'right-to-left layout and accessibility', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const page = await context.newPage();
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', login );
		await page.fill( '#user_pass', password );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
		await page.goto( SCREEN );
		await expect( page.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
		await expect( page.getByRole( 'tab' ) ).toHaveCount( 5 );
		await page.getByRole( 'tab' ).nth( 2 ).click();
		await page.waitForLoadState( 'networkidle' );
		const tabs = await page.getByRole( 'tab' ).evaluateAll( ( els ) => els.map( ( el ) => el.getBoundingClientRect().x ) );
		expect( tabs[ 0 ] ).toBeGreaterThan( tabs[ 1 ] ); // First tab on the right.
		await expectAccessible( page, '#windrose-helm-app' );
		await page.screenshot( { path: '/var/www/html/tools/.results/helm-rtl.png', fullPage: true } );
		await context.close();
	} );
} );

/**
 * wordpress.org switched off: nothing reaches wordpress.org and the admin says updates are manual.
 */
import { existsSync, readFileSync } from 'node:fs';
import { test, expect } from '@playwright/test';
import { recordExternal, wp } from './helpers.mjs';

const DEBUG_LOG = '/var/www/html/web/wp-content/debug.log';
const logLength = () => ( existsSync( DEBUG_LOG ) ? readFileSync( DEBUG_LOG, 'utf8' ).length : 0 );

test.describe.configure( { mode: 'serial' } );

test.describe( 'wordpress.org off', () => {
	test.beforeAll( () => {
		wp( 'site', 'option', 'update', 'windrose_helm_wporg', '0' );
		wp( 'site', 'option', 'delete', 'windrose_helm_request_log' );
	} );

	test.afterAll( () => {
		wp( 'site', 'option', 'update', 'windrose_helm_wporg', '1' );
	} );

	test( 'Updates, Add Plugins and Site Health say updates are manual', async ( { page } ) => {
		const external = recordExternal( page );
		await page.goto( '/wp-admin/network/update-core.php?force-check=1' );
		await expect( page.locator( '.windrose-helm-manual-updates' ) ).toContainText( 'Updates are manual' );
		await page.goto( '/wp-admin/network/plugin-install.php' );
		await expect( page.locator( '.windrose-helm-manual-updates' ) ).toBeVisible();
		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByRole( 'button', { name: /Updates are manual: wordpress.org is switched off/ } ) ).toBeVisible( { timeout: 30000 } );
		await expect( page.getByText( 'Your site could not reach WordPress.org' ) ).toHaveCount( 0 );
		expect( external.filter( ( host ) => /(^|\.)(wordpress|w|wp)\.org\//.test( host ) ) ).toEqual( [] );
	} );

	test( 'directory screens and language menus work without PHP warnings', async ( { page } ) => {
		const before = logLength();
		await page.goto( '/wp-admin/network/plugin-install.php?tab=search&s=forms' );
		await expect( page.getByText( 'Updates are manual' ).first() ).toBeVisible();
		await page.goto( '/wp-admin/network/theme-install.php' );
		await page.goto( '/wp-admin/profile.php' );
		await expect( page.locator( '#locale' ) ).toBeVisible();
		await page.goto( '/wp-admin/network/settings.php' );
		await page.goto( '/fr/wp-admin/options-general.php' );
		await page.goto( '/wp-admin/site-health.php' );
		await page.waitForLoadState( 'networkidle' );
		const added = existsSync( DEBUG_LOG ) ? readFileSync( DEBUG_LOG, 'utf8' ).slice( before ) : '';
		expect( added ).toBe( '' );
	} );

	test( 'the log shows wordpress.org requests answered or blocked, never sent', async ( { page } ) => {
		await page.goto( '/wp-admin/network/admin.php?page=windrose-helm&tab=privacy' );
		await expect( page.locator( '.dataviews-view-table' ) ).toContainText( 'api.wordpress.org' );
		const log = JSON.parse( wp( 'site', 'option', 'get', 'windrose_helm_request_log', '--format=json' ) );
		const wporg = log.filter( ( e ) => /(^|\.)(wordpress|w|wp)\.org$/.test( e.host ) );
		expect( wporg.length ).toBeGreaterThan( 0 );
		expect( [ ...new Set( wporg.map( ( e ) => e.outcome ) ) ].sort() ).toEqual( expect.arrayContaining( [ 'answered' ] ) );
		expect( wporg.filter( ( e ) => e.outcome === 'sent' || e.outcome === 'failed' ) ).toEqual( [] );
	} );
} );

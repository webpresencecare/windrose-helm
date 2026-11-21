/**
 * Privacy and Defaults, seen from real pages (every switch on, as on a fresh network).
 */
import { test, expect } from '@playwright/test';
import { USER, recordExternal, wp } from './helpers.mjs';

/** The ID of the auto-draft Add New Post opened (its editor knows it once loaded). */
async function openedPostId( page ) {
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() > 0 );
	return page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostId() );
}

/** Deletes the auto-draft Add New Post created, so each run leaves nothing behind. */
async function deleteOpenedPost( page ) {
	wp( 'post', 'delete', String( await openedPostId( page ) ), '--force' );
}

test.describe( 'Logged out', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'front pages and login make no external request and load no emoji script', async ( { page } ) => {
		const external = recordExternal( page );
		for ( const path of [ '/', '/fr/', '/ar/', '/social/' ] ) {
			const response = await page.goto( path );
			expect( response.status() ).toBe( 200 );
			const html = await response.text();
			expect( html ).not.toContain( '_wpemojiSettings' );
			expect( html ).not.toContain( 's.w.org' );
		}
		await page.goto( '/wp-login.php' );
		await expect( page.locator( '#language-switcher' ) ).toHaveCount( 0 );
		expect( external ).toEqual( [] );
	} );

	test( 'the REST API gives visitors no usernames', async ( { request } ) => {
		const users = await request.get( '/wp-json/wp/v2/users' );
		expect( users.status() ).toBe( 200 );
		const list = await users.json();
		expect( list.length ).toBeGreaterThan( 0 );
		for ( const user of list ) {
			expect( user ).not.toHaveProperty( 'slug' );
			expect( user ).not.toHaveProperty( 'link' );
		}
		expect( ( await request.get( `/wp-json/wp/v2/users?slug=${ USER }` ) ).status() ).toBe( 401 );
		expect( ( await request.get( `/wp-json/wp/v2/users?search=${ USER.slice( 0, 3 ) }` ) ).status() ).toBe( 401 );
	} );
} );

test.describe( 'Logged in', () => {
	test( 'dashboards: no Events and News, no Gravatar, no emoji script', async ( { page } ) => {
		const external = recordExternal( page );
		for ( const path of [ '/wp-admin/', '/fr/wp-admin/', '/ar/wp-admin/', '/wp-admin/network/' ] ) {
			const response = await page.goto( path );
			await page.waitForLoadState( 'networkidle' );
			await expect( page.locator( '#dashboard_primary' ) ).toHaveCount( 0 );
			expect( await response.text() ).not.toContain( '_wpemojiSettings' );
			await expect( page.locator( '#wp-admin-bar-my-account img.avatar' ).first() ).toHaveAttribute( 'src', /windrose-helm\/assets\/avatar\.svg/ );
		}
		expect( external ).toEqual( [] );
	} );

	test( 'Customizer and Widgets screens lead to the Site Editor', async ( { page } ) => {
		await page.goto( '/wp-admin/customize.php' );
		await expect( page ).toHaveURL( /site-editor\.php/ );
		await page.goto( '/fr/wp-admin/widgets.php' );
		await expect( page ).toHaveURL( /site-editor\.php/ );
		await page.goto( '/wp-admin/themes.php' );
		await expect( page.locator( '#adminmenu a[href*="customize.php"]' ) ).toHaveCount( 0 );
	} );

	test( 'the Google Fonts collection follows its switch', async ( { page } ) => {
		// The test network's own setting (not changed here): on blocks the collection, off leaves WordPress's default.
		let blocked = true;
		try {
			blocked = false !== JSON.parse( wp( 'site', 'option', 'get', 'windrose_helm_privacy', '--format=json' ) ).google_fonts;
		} catch {
			// Not saved yet: Helm's default (blocked).
		}
		await page.goto( '/wp-admin/post-new.php' );
		try {
			const collections = await page.evaluate( () => window.wp.apiFetch( { path: '/wp/v2/font-collections' } ) );
			expect( collections.map( ( c ) => c.slug ).includes( 'google-fonts' ) ).toBe( ! blocked );
		} finally {
			await deleteOpenedPost( page );
		}
	} );
} );

test.describe( 'Editor for someone who never opened it', () => {
	const login = 'windrosee2eeditor';
	const password = `Ed-${ Date.now() }-pass`;

	test.beforeAll( () => {
		wp( 'user', 'create', login, `${ login }@example.com`, '--role=editor', `--user_pass=${ password }` );
	} );

	test.afterEach( ( {}, testInfo ) => {
		// The auto-draft Add New Post created (deleted before its author).
		for ( const { description } of testInfo.annotations.filter( ( { type } ) => type === 'post' ) ) {
			wp( 'post', 'delete', description, '--force' );
		}
	} );

	test.afterAll( () => {
		wp( 'user', 'delete', login, '--yes', '--network' );
	} );

	test( 'no Welcome Guide, no external request, no Classic block in the inserter', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const page = await context.newPage();
		const external = recordExternal( page );
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', login );
		await page.fill( '#user_pass', password );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );

		await page.goto( '/wp-admin/post-new.php' );
		await expect( page.getByRole( 'region', { name: 'Editor top bar' } ) ).toBeVisible();
		test.info().annotations.push( { type: 'post', description: String( await openedPostId( page ) ) } );
		// The editor never goes quiet (it polls), so give late requests a few seconds to show.
		await page.waitForTimeout( 4000 );
		await expect( page.getByRole( 'dialog', { name: /welcome/i } ) ).toHaveCount( 0 );

		await page.getByRole( 'button', { name: /block inserter/i } ).first().click();
		const search = page.getByRole( 'searchbox' ).first();
		await search.fill( 'Classic' );
		await page.waitForTimeout( 1000 );
		await expect( page.getByRole( 'option', { name: 'Classic', exact: true } ) ).toHaveCount( 0 );
		await search.fill( 'Paragraph' );
		await expect( page.getByRole( 'option', { name: 'Paragraph', exact: true } ) ).toBeVisible();
		await page.waitForTimeout( 2000 );

		expect( external ).toEqual( [] );
		await context.close();
	} );
} );

/**
 * Shared helpers for Windrose Helm's Playwright tests, run next to a WordPress multisite with WP-CLI.
 * WINDROSE_E2E_HOST: the network's host; WINDROSE_E2E_USER: a super admin (password in WINDROSE_PASS).
 */
import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

export const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
export const USER = process.env.WINDROSE_E2E_USER ?? 'admin';

/** Runs WP-CLI on the test network. */
export function wp( ...args ) {
	return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=https://${ HOST }` ], {
		encoding: 'utf8',
	} ).trim();
}

/** Records every browser request to another host. */
export function recordExternal( page ) {
	const external = [];
	page.on( 'request', ( request ) => {
		const url = new URL( request.url() );
		if ( url.protocol.startsWith( 'http' ) && url.hostname !== HOST ) {
			external.push( `${ url.hostname }${ url.pathname }` );
		}
	} );
	return external;
}

/**
 * DataViews 22 draws its selection checkboxes (`@wordpress/ui` Checkbox) 16px wide with a 24px `::after` click area
 * that axe cannot see, so axe reports them under target-size although people get 24px (checked with real clicks).
 * Only those checkboxes are left out of target-size; everything else is still checked. Remove once fixed
 * upstream.
 *
 * @param {import('@playwright/test').Page} page       The page.
 * @param {Array}                           violations axe violations.
 * @return {Promise<Array>} The violations to report.
 */
async function withoutDataViewsCheckboxes( page, violations ) {
	const kept = [];
	for ( const violation of violations ) {
		if ( 'target-size' !== violation.id ) {
			kept.push( violation );
			continue;
		}
		const nodes = [];
		for ( const node of violation.nodes ) {
			const skipped = await page.evaluate( ( selector ) => {
				const element = document.querySelector( selector );
				return !! element && 'checkbox' === element.getAttribute( 'role' ) && !! element.closest( '.dataviews-wrapper' );
			}, String( node.target[ 0 ] ) );
			if ( ! skipped ) {
				nodes.push( node );
			}
		}
		if ( nodes.length ) {
			kept.push( { ...violation, nodes } );
		}
	}
	return kept;
}

/** WCAG 2.2 AA checks (axe) on part of the page. */
export async function expectAccessible( page, selector ) {
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	const violations = await withoutDataViewsCheckboxes( page, results.violations );
	expect( violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );
}

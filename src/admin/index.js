/**
 * Windrose Helm network admin screen: tabs Overview, Defaults, Privacy, Updates, Recommended plugins.
 */
import { createRoot, useEffect, useState } from '@wordpress/element';
import { Notice, Spinner, TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';

import './admin.scss';

import Overview from './tabs/overview';
import Defaults from './tabs/defaults';
import Privacy from './tabs/privacy';
import Updates from './tabs/updates';
import Recommended, { RecommendedOff } from './tabs/recommended';

const TABS = [
	{ name: 'overview', title: __( 'Overview', 'windrose-helm' ) },
	{ name: 'defaults', title: __( 'Defaults', 'windrose-helm' ) },
	{ name: 'privacy', title: __( 'Privacy', 'windrose-helm' ) },
	{ name: 'updates', title: __( 'Updates', 'windrose-helm' ) },
	{
		name: 'recommended',
		title: __( 'Recommended plugins', 'windrose-helm' ),
	},
];

function initialTab() {
	const tab = new URLSearchParams( window.location.search ).get( 'tab' );
	return TABS.some( ( { name } ) => name === tab ) ? tab : 'overview';
}

function rememberTab( name ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tab', name );
	window.history.replaceState( null, '', url );
}

function App() {
	const [ settings, setSettings ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const notify = ( status, message ) => {
		setNotice( { status, message } );
		speak( message, 'error' === status ? 'assertive' : 'polite' );
	};

	useEffect( () => {
		apiFetch( { path: '/windrose-helm/v1/settings' } )
			.then( setSettings )
			.catch( ( error ) => notify( 'error', error.message ) );
	}, [] );

	const save = async ( changes ) => {
		try {
			const saved = await apiFetch( {
				path: '/windrose-helm/v1/settings',
				method: 'POST',
				data: changes,
			} );
			setSettings( saved );
			notify(
				'success',
				__(
					'Settings saved. They apply from the next page load.',
					'windrose-helm'
				)
			);
			return true;
		} catch ( error ) {
			notify( 'error', error.message );
			return false;
		}
	};

	if ( ! settings ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const props = { settings, save, notify };
	const panels = {
		overview: Overview,
		defaults: Defaults,
		privacy: Privacy,
		updates: Updates,
		recommended: settings.modules.recommended
			? Recommended
			: RecommendedOff,
	};

	return (
		<>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					className="windrose-helm__notice"
				>
					{ notice.message }
				</Notice>
			) }
			<TabPanel
				className="windrose-helm__tabs"
				tabs={ TABS }
				initialTabName={ initialTab() }
				onSelect={ ( name ) => {
					rememberTab( name );
					setNotice( null );
				} }
			>
				{ ( tab ) => {
					const Panel = panels[ tab.name ];
					return (
						<div className="windrose-helm__panel">
							<Panel { ...props } />
						</div>
					);
				} }
			</TabPanel>
		</>
	);
}

const container = document.getElementById( 'windrose-helm-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}

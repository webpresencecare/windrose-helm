/**
 * Updates: the wordpress.org switch and the Windrose plugins.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Notice,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { dateI18n, getSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import CardTitle from '../card-title';

import data from '../data';
import SwitchForm from '../switch-form';

const VIEW = {
	type: 'table',
	page: 1,
	perPage: 50,
	titleField: 'name',
	fields: [ 'installed', 'latest', 'state_label', 'checked_at' ],
	layout: {},
};

const FIELDS = [
	{
		id: 'name',
		type: 'text',
		label: __( 'Plugin', 'windrose-helm' ),
		enableHiding: false,
	},
	{
		id: 'installed',
		type: 'text',
		label: __( 'Installed', 'windrose-helm' ),
		enableSorting: false,
	},
	{
		id: 'latest',
		type: 'text',
		label: __( 'Latest release', 'windrose-helm' ),
		enableSorting: false,
		render: ( { item } ) =>
			item.latest && item.page ? (
				<ExternalLink href={ item.page }>{ item.latest }</ExternalLink>
			) : (
				item.latest || '–'
			),
	},
	{
		id: 'state_label',
		type: 'text',
		label: __( 'State', 'windrose-helm' ),
		enableSorting: false,
	},
	{
		id: 'checked_at',
		type: 'text',
		label: __( 'Checked', 'windrose-helm' ),
		enableSorting: false,
		render: ( { item } ) =>
			item.checked_at
				? dateI18n( getSettings().formats.datetime, item.checked_at )
				: '–',
	},
];

export default function Updates( { settings, save, notify } ) {
	const [ state, setState ] = useState( null );
	const [ checking, setChecking ] = useState( false );
	const [ view, setView ] = useState( VIEW );

	const load = useCallback( () => {
		apiFetch( { path: '/windrose-helm/v1/updates' } )
			.then( setState )
			.catch( ( error ) => notify( 'error', error.message ) );
	}, [ notify ] );

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const checkNow = async () => {
		setChecking( true );
		try {
			setState(
				await apiFetch( {
					path: '/windrose-helm/v1/updates/check',
					method: 'POST',
				} )
			);
			notify( 'success', __( 'Checked on GitHub.', 'windrose-helm' ) );
		} catch ( error ) {
			notify( 'error', error.message );
		}
		setChecking( false );
	};

	const plugins = state?.plugins ?? [];

	return (
		<>
			<Card>
				<CardHeader>
					<CardTitle>wordpress.org</CardTitle>
				</CardHeader>
				<CardBody>
					{ ! settings.modules.updates && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'The Updates module is off (Overview tab): wordpress.org stays on.',
								'windrose-helm'
							) }
						</Notice>
					) }
					{ settings.modules.updates && ! settings.wporg && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Updates are manual: wordpress.org is switched off. WordPress, plugins, themes and translations from wordpress.org are not checked or updated; upload new versions by hand. Windrose plugins still update from GitHub.',
								'windrose-helm'
							) }
						</Notice>
					) }
					<SwitchForm
						labels={ {
							wporg: [
								__( 'Use wordpress.org', 'windrose-helm' ),
								__(
									'Updates, translations and the plugin and theme directories come from wordpress.org. When off, this network sends nothing to wordpress.org: update checks get an empty answer and every other request is stopped.',
									'windrose-helm'
								),
							],
						} }
						values={ { wporg: settings.wporg } }
						onSave={ ( values ) => save( { wporg: values.wporg } ) }
						disabled={ ! settings.modules.updates }
					/>
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<CardTitle>
						{ __( 'Windrose plugins', 'windrose-helm' ) }
					</CardTitle>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isBusy={ checking }
						disabled={ checking }
						onClick={ checkNow }
					>
						{ __( 'Check now', 'windrose-helm' ) }
					</Button>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Windrose plugins update from their GitHub releases, whether wordpress.org is on or off. A package is installed only if its checksum matches the one GitHub published.',
							'windrose-helm'
						) }{ ' ' }
						<a href={ data.updateCore }>
							{ __( 'WordPress Updates', 'windrose-helm' ) }
						</a>
					</p>
				</CardBody>
				<CardBody className="windrose-helm__table">
					<DataViews
						data={ plugins }
						fields={ FIELDS }
						view={ view }
						onChangeView={ setView }
						paginationInfo={ {
							totalItems: plugins.length,
							totalPages: 1,
						} }
						defaultLayouts={ { table: {} } }
						getItemId={ ( item ) => item.slug }
						isLoading={ ! state }
						search={ false }
						empty={
							<p>
								{ __(
									'No Windrose plugin found.',
									'windrose-helm'
								) }
							</p>
						}
					/>
				</CardBody>
			</Card>
		</>
	);
}

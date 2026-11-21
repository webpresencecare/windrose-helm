/**
 * Privacy: one switch per request type (on means blocked), the blocked hosts list, and the request log.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { dateI18n, getSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import CardTitle from '../card-title';

import data from '../data';
import SwitchForm from '../switch-form';

const OUTCOMES = [
	{ value: 'sent', label: __( 'Sent', 'windrose-helm' ) },
	{ value: 'failed', label: __( 'Failed', 'windrose-helm' ) },
	{ value: 'blocked', label: __( 'Blocked', 'windrose-helm' ) },
	{ value: 'answered', label: __( 'Answered locally', 'windrose-helm' ) },
];

const CONTEXTS = [
	{ value: 'web', label: __( 'Page', 'windrose-helm' ) },
	{ value: 'cron', label: __( 'Cron', 'windrose-helm' ) },
	{ value: 'rest', label: __( 'REST API', 'windrose-helm' ) },
	{ value: 'ajax', label: __( 'AJAX', 'windrose-helm' ) },
	{ value: 'cli', label: __( 'WP-CLI', 'windrose-helm' ) },
];

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'time', direction: 'desc' },
	titleField: 'host',
	fields: [
		'path',
		'time',
		'outcome',
		'code',
		'context',
		'site_name',
		'source',
	],
	layout: { density: 'compact' },
};

const HOSTS_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 100,
	titleField: 'host',
	fields: [],
	layout: { density: 'compact' },
};

function BlockedHosts( { settings, save } ) {
	const hosts = useMemo(
		() => settings.blocked_hosts ?? [],
		[ settings.blocked_hosts ]
	);
	const [ entry, setEntry ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ view, setView ] = useState( HOSTS_VIEW );
	const disabled = ! settings.modules.privacy;

	const change = async ( next ) => {
		setBusy( true );
		const saved = await save( { blocked_hosts: next } );
		setBusy( false );
		return saved;
	};

	const fields = [
		{
			id: 'host',
			type: 'text',
			label: __( 'Host', 'windrose-helm' ),
			enableHiding: false,
			render: ( { item } ) => <code>{ item.host }</code>,
		},
	];
	const actions = [
		{
			id: 'unblock',
			label: __( 'Unblock', 'windrose-helm' ),
			isPrimary: true,
			supportsBulk: true,
			callback: ( items ) =>
				change(
					hosts.filter(
						( host ) =>
							! items.some( ( item ) => item.host === host )
					)
				),
		},
	];
	const items = hosts.map( ( host ) => ( { host } ) );

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Blocked hosts', 'windrose-helm' ) }
				</CardTitle>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'WordPress never contacts these servers: it answers their requests itself, with an error, as if they could not be reached. A host also covers its subdomains: automatorplugin.com would also block llm.automatorplugin.com.',
						'windrose-helm'
					) }
				</p>
				<p>
					{ __(
						'Only requests the server makes through WordPress are stopped, and logged as Blocked below. Files a page loads in the browser are not affected.',
						'windrose-helm'
					) }
				</p>
				<form
					className="windrose-helm__token-form"
					onSubmit={ async ( event ) => {
						event.preventDefault();
						if ( await change( [ ...hosts, entry ] ) ) {
							setEntry( '' );
						}
					} }
				>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Host to block', 'windrose-helm' ) }
						help={ __(
							'A host name such as llm.example.com, or an address copied from the request log.',
							'windrose-helm'
						) }
						value={ entry }
						onChange={ setEntry }
						disabled={ disabled }
						autoComplete="off"
						spellCheck={ false }
					/>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || disabled || ! entry.trim() }
						accessibleWhenDisabled
					>
						{ __( 'Block', 'windrose-helm' ) }
					</Button>
				</form>
			</CardBody>
			<CardBody className="windrose-helm__table">
				<DataViews
					data={ items }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ disabled ? [] : actions }
					paginationInfo={ {
						totalItems: items.length,
						totalPages: 1,
					} }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => item.host }
					search={ false }
					empty={
						<p>{ __( 'No host is blocked.', 'windrose-helm' ) }</p>
					}
				/>
			</CardBody>
		</Card>
	);
}

function RequestLog( { settings, save } ) {
	const [ log, setLog ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ confirming, setConfirming ] = useState( false );

	const load = useCallback( ( request = {} ) => {
		apiFetch( { path: '/windrose-helm/v1/request-log', ...request } )
			.then( ( result ) => {
				setLog( result );
				setError( '' );
			} )
			.catch( ( failure ) => setError( failure.message ) );
	}, [] );

	// Again after the log switch changes, so the "switched off" notice follows it.
	const logging = settings.privacy.request_log;
	useEffect( () => load(), [ load, logging ] );
	const logSwitch = useMemo(
		() => ( { request_log: logging } ),
		[ logging ]
	);

	const fields = useMemo( () => {
		const sites = [
			...new Set(
				( log?.entries ?? [] ).map( ( entry ) => entry.site_name )
			),
		];
		return [
			{
				id: 'host',
				type: 'text',
				label: __( 'Server', 'windrose-helm' ),
				enableHiding: false,
				enableGlobalSearch: true,
			},
			{
				id: 'path',
				type: 'text',
				label: __( 'Path', 'windrose-helm' ),
				enableGlobalSearch: true,
				enableSorting: false,
				render: ( { item } ) => <code>{ item.path }</code>,
			},
			{
				id: 'time',
				type: 'integer',
				label: __( 'Time', 'windrose-helm' ),
				render: ( { item } ) =>
					dateI18n( getSettings().formats.datetime, item.date ),
				filterBy: false,
			},
			{
				id: 'outcome',
				type: 'text',
				label: __( 'Outcome', 'windrose-helm' ),
				elements: OUTCOMES,
				filterBy: { operators: [ 'isAny' ] },
			},
			{
				id: 'code',
				type: 'integer',
				label: __( 'HTTP status', 'windrose-helm' ),
				render: ( { item } ) => ( item.code > 0 ? item.code : '–' ),
				filterBy: false,
			},
			{
				id: 'context',
				type: 'text',
				label: __( 'Context', 'windrose-helm' ),
				elements: CONTEXTS,
				filterBy: { operators: [ 'isAny' ] },
			},
			{
				id: 'site_name',
				type: 'text',
				label: __( 'Site', 'windrose-helm' ),
				elements: sites.map( ( name ) => ( {
					value: name,
					label: name,
				} ) ),
				filterBy: { operators: [ 'isAny' ] },
			},
			{
				id: 'source',
				type: 'text',
				label: __( 'Source', 'windrose-helm' ),
				enableGlobalSearch: true,
			},
		];
	}, [ log ] );

	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( log?.entries ?? [], view, fields ),
		[ log, view, fields ]
	);

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Outgoing requests', 'windrose-helm' ) }
				</CardTitle>
				<div className="windrose-helm__row">
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ () => load() }
					>
						{ __( 'Refresh', 'windrose-helm' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isDestructive
						disabled={ ! log?.entries.length }
						accessibleWhenDisabled
						onClick={ () => setConfirming( true ) }
					>
						{ __( 'Clear log', 'windrose-helm' ) }
					</Button>
				</div>
			</CardHeader>
			<CardBody>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ log && ! log.enabled && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'The request log is switched off: older entries are shown, new requests are not recorded.',
							'windrose-helm'
						) }
					</Notice>
				) }
				<p>
					{ __(
						'The last 200 requests this network made to other servers, newest first: server and path only. Requests stopped or answered by Windrose Helm are listed too.',
						'windrose-helm'
					) }
				</p>
				<SwitchForm
					labels={ { request_log: data.requestLog } }
					values={ logSwitch }
					onSave={ ( { request_log: value } ) =>
						save( { privacy: { request_log: value } } )
					}
					disabled={ ! settings.modules.privacy }
				/>
			</CardBody>
			<CardBody className="windrose-helm__table">
				<DataViews
					data={ shown }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => String( item.id ) }
					isLoading={ ! log && ! error }
					searchLabel={ __(
						'Search servers, paths and sources',
						'windrose-helm'
					) }
					empty={
						<p>
							{ __(
								'No request recorded yet.',
								'windrose-helm'
							) }
						</p>
					}
				/>
				{ confirming && (
					<Modal
						title={ __(
							'Clear the request log?',
							'windrose-helm'
						) }
						onRequestClose={ () => setConfirming( false ) }
						size="small"
					>
						<p>
							{ __(
								'Every recorded request will be removed.',
								'windrose-helm'
							) }
						</p>
						<div className="windrose-helm__row windrose-helm__row--end">
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								onClick={ () => setConfirming( false ) }
							>
								{ __( 'Cancel', 'windrose-helm' ) }
							</Button>
							<Button
								__next40pxDefaultSize
								variant="primary"
								isDestructive
								onClick={ () => {
									setConfirming( false );
									load( { method: 'DELETE' } );
								} }
							>
								{ __( 'Clear log', 'windrose-helm' ) }
							</Button>
						</div>
					</Modal>
				) }
			</CardBody>
		</Card>
	);
}

export default function Privacy( { settings, save } ) {
	return (
		<>
			<p className="windrose-helm__intro">
				{ __(
					'On this tab, a switch turned on blocks what it names; turned off, WordPress does what it does without Windrose Helm. Only the request log switch, under Outgoing requests, records instead of blocking.',
					'windrose-helm'
				) }
			</p>
			<Card>
				<CardHeader>
					<CardTitle>
						{ __( 'Requests to other services', 'windrose-helm' ) }
					</CardTitle>
				</CardHeader>
				<CardBody>
					{ ! settings.modules.privacy && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'The Privacy module is off (Overview tab).',
								'windrose-helm'
							) }
						</Notice>
					) }
					<p>
						{ __(
							'Visitors’ pages make no request to other services. These switches cover what logged-in people and the server still send.',
							'windrose-helm'
						) }
					</p>
					<SwitchForm
						labels={ data.privacy }
						values={ settings.privacy }
						onSave={ ( privacy ) => save( { privacy } ) }
						disabled={ ! settings.modules.privacy }
					/>
				</CardBody>
			</Card>
			<BlockedHosts settings={ settings } save={ save } />
			<RequestLog settings={ settings } save={ save } />
		</>
	);
}

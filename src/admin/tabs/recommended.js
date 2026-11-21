/**
 * Recommended plugins: an editable list. Install and activate go through WordPress's own REST plugins endpoint.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import { DataForm, DataViews } from '@wordpress/dataviews/wp';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import CardTitle from '../card-title';

const PATH = '/windrose-helm/v1/recommended';

const STATUSES = [
	{ value: 'not-installed', label: __( 'Not installed', 'windrose-helm' ) },
	{
		value: 'inactive',
		label: __( 'Installed, not active', 'windrose-helm' ),
	},
	{
		value: 'site-active',
		label: __( 'Active on some sites', 'windrose-helm' ),
	},
	{ value: 'network-active', label: __( 'Network active', 'windrose-helm' ) },
];

/**
 * The fields people can edit, for DataForm.
 *
 * @param {Array} categories Category elements ({ value, label }).
 * @return {Array} Fields.
 */
function editFields( categories ) {
	return [
		{
			id: 'category',
			type: 'text',
			label: __( 'Category', 'windrose-helm' ),
			elements: categories,
			Edit: 'select',
		},
		{
			id: 'why',
			type: 'text',
			label: __( 'Why it is recommended', 'windrose-helm' ),
			Edit: { control: 'textarea', rows: 3 },
		},
		{
			id: 'network',
			type: 'boolean',
			label: __( 'Activate on the whole network', 'windrose-helm' ),
			description: __(
				'Off: activate it on each site that needs it, from that site’s Plugins screen.',
				'windrose-helm'
			),
			Edit: 'toggle',
		},
	];
}

const EDIT_FORM = {
	layout: { type: 'regular' },
	fields: [ 'category', 'why', 'network' ],
};

/**
 * The part of an item that is stored.
 *
 * @param {Object}  item          List item.
 * @param {string}  item.slug     wordpress.org slug.
 * @param {string}  item.name     Name.
 * @param {string}  item.category Category key.
 * @param {string}  item.why      Reason.
 * @param {boolean} item.network  Activate network-wide.
 * @return {Object} Stored entry.
 */
const entry = ( { slug, name, category, why, network } ) => ( {
	slug,
	name,
	category,
	why,
	network,
} );

/**
 * A wordpress.org plugin slug from a slug or a plugin address.
 *
 * @param {string} text What was typed.
 * @return {string} Slug.
 */
function slugFrom( text ) {
	const match = text.trim().match( /wordpress\.org\/plugins\/([^/?#]+)/ );
	return ( match ? match[ 1 ] : text.trim() ).toLowerCase();
}

/**
 * The edit form, shown by DataViews in its own modal.
 *
 * @param {Object}                             props            Component props.
 * @param {Object}                             props.item       List item.
 * @param {Array}                              props.categories Category elements.
 * @param {(item: Object) => Promise<boolean>} props.onSave     Saves the changed item.
 * @param {() => void}                         props.onClose    Closes the modal.
 */
function EditForm( { item, categories, onSave, onClose } ) {
	const [ data, setData ] = useState( item );
	return (
		<form
			onSubmit={ async ( event ) => {
				event.preventDefault();
				await onSave( data );
				onClose();
			} }
		>
			<DataForm
				data={ data }
				fields={ editFields( categories ) }
				form={ EDIT_FORM }
				onChange={ ( edits ) =>
					setData( ( current ) => ( { ...current, ...edits } ) )
				}
			/>
			<div className="windrose-helm__row windrose-helm__row--end windrose-helm__modal-actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
				>
					{ __( 'Cancel', 'windrose-helm' ) }
				</Button>
				<Button __next40pxDefaultSize variant="primary" type="submit">
					{ __( 'Save', 'windrose-helm' ) }
				</Button>
			</div>
		</form>
	);
}

function AddModal( { categories, onAdd, onClose, notify } ) {
	const [ text, setText ] = useState( '' );
	const [ found, setFound ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const lookUp = async () => {
		setBusy( true );
		setError( '' );
		try {
			const info = await apiFetch( {
				path: `${ PATH }/lookup`,
				method: 'POST',
				data: { slug: slugFrom( text ) },
			} );
			setFound( {
				slug: info.slug,
				name: info.name,
				category: 'other',
				why: info.description,
				network: true,
			} );
		} catch ( failure ) {
			setError( failure.message );
		}
		setBusy( false );
	};

	return (
		<Modal
			title={ __( 'Add a plugin', 'windrose-helm' ) }
			onRequestClose={ onClose }
		>
			{ ! found && (
				<form
					className="windrose-helm__token-form"
					onSubmit={ ( event ) => {
						event.preventDefault();
						lookUp();
					} }
				>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Plugin slug or wordpress.org address',
							'windrose-helm'
						) }
						help={ __(
							'For example redirection, or https://wordpress.org/plugins/redirection/',
							'windrose-helm'
						) }
						value={ text }
						onChange={ setText }
					/>
					<Button
						__next40pxDefaultSize
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || ! text.trim() }
						accessibleWhenDisabled
					>
						{ __( 'Look up', 'windrose-helm' ) }
					</Button>
				</form>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ found && (
				<>
					<p>
						<strong>{ found.name }</strong>{ ' ' }
						<ExternalLink
							href={ `https://wordpress.org/plugins/${ found.slug }/` }
						>
							{ __( 'wordpress.org page', 'windrose-helm' ) }
						</ExternalLink>
					</p>
					<DataForm
						data={ found }
						fields={ editFields( categories ) }
						form={ EDIT_FORM }
						onChange={ ( edits ) =>
							setFound( ( current ) => ( {
								...current,
								...edits,
							} ) )
						}
					/>
					<div className="windrose-helm__row windrose-helm__row--end windrose-helm__modal-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'windrose-helm' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ async () => {
								if ( await onAdd( found ) ) {
									notify(
										'success',
										sprintf(
											/* translators: %s: plugin name. */
											__(
												'%s added to the list.',
												'windrose-helm'
											),
											found.name
										)
									);
									onClose();
								}
							} }
						>
							{ __( 'Add to the list', 'windrose-helm' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
}

/**
 * The tab while the module is off: no list, only where to switch it on.
 */
export function RecommendedOff() {
	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Recommended plugins', 'windrose-helm' ) }
				</CardTitle>
			</CardHeader>
			<CardBody>
				<Notice status="info" isDismissible={ false }>
					{ __(
						'The Recommended plugins module is off (Overview tab).',
						'windrose-helm'
					) }
				</Notice>
			</CardBody>
		</Card>
	);
}

export default function Recommended( { notify } ) {
	const [ state, setState ] = useState( null );
	const [ adding, setAdding ] = useState( false );
	const [ resetting, setResetting ] = useState( false );
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 50,
		titleField: 'name',
		descriptionField: 'why',
		fields: [ 'category_label', 'status', 'version' ],
		layout: {},
	} );

	const load = useCallback( () => {
		apiFetch( { path: PATH } )
			.then( setState )
			.catch( ( error ) => notify( 'error', error.message ) );
	}, [ notify ] );

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const items = state?.items ?? [];
	const categories = state?.categories ?? [];

	/**
	 * Stores a new list.
	 *
	 * @param {Array} list Entries.
	 * @return {Promise<boolean>} Whether it was saved.
	 */
	const saveList = async ( list ) => {
		try {
			setState(
				await apiFetch( {
					path: PATH,
					method: 'POST',
					data: { items: list.map( entry ) },
				} )
			);
			return true;
		} catch ( error ) {
			notify( 'error', error.message );
			return false;
		}
	};

	const run = async ( request, message ) => {
		try {
			await apiFetch( request );
			notify( 'success', message );
		} catch ( error ) {
			notify( 'error', error.message );
		}
		load();
	};

	const fields = [
		{
			id: 'name',
			type: 'text',
			label: __( 'Plugin', 'windrose-helm' ),
			enableHiding: false,
			render: ( { item } ) => (
				<ExternalLink href={ item.url }>{ item.name }</ExternalLink>
			),
		},
		{
			id: 'why',
			type: 'text',
			label: __( 'Why', 'windrose-helm' ),
			enableSorting: false,
		},
		{
			id: 'category_label',
			type: 'text',
			label: __( 'Category', 'windrose-helm' ),
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'windrose-helm' ),
			elements: STATUSES,
			filterBy: false,
			render: ( { item } ) =>
				item.status === 'site-active'
					? sprintf(
							/* translators: %d: number of sites. */
							_n(
								'Active on %d site',
								'Active on %d sites',
								item.sites,
								'windrose-helm'
							),
							item.sites
						)
					: STATUSES.find( ( s ) => s.value === item.status )?.label,
		},
		{
			id: 'version',
			type: 'text',
			label: __( 'Version', 'windrose-helm' ),
			enableSorting: false,
		},
	];

	const actions = [
		{
			id: 'install',
			label: __( 'Install', 'windrose-helm' ),
			isPrimary: true,
			isEligible: ( item ) =>
				state?.canInstall && item.status === 'not-installed',
			callback: ( [ item ] ) =>
				run(
					{
						path: '/wp/v2/plugins',
						method: 'POST',
						data: { slug: item.slug, status: 'inactive' },
					},
					/* translators: %s: plugin name. */
					sprintf( __( '%s installed.', 'windrose-helm' ), item.name )
				),
		},
		{
			id: 'activate',
			label: __( 'Network activate', 'windrose-helm' ),
			isPrimary: true,
			isEligible: ( item ) =>
				item.network &&
				[ 'inactive', 'site-active' ].includes( item.status ),
			callback: ( [ item ] ) =>
				run(
					{
						path: `/wp/v2/plugins/${ item.plugin }`,
						method: 'POST',
						data: { status: 'network-active' },
					},
					sprintf(
						/* translators: %s: plugin name. */
						__( '%s activated on the network.', 'windrose-helm' ),
						item.name
					)
				),
		},
		{
			id: 'edit',
			label: __( 'Edit', 'windrose-helm' ),
			modalHeader: ( [ item ] ) =>
				sprintf(
					/* translators: %s: plugin name. */
					__( 'Edit %s', 'windrose-helm' ),
					item.name
				),
			RenderModal: ( { items: [ item ], closeModal } ) => (
				<EditForm
					item={ item }
					categories={ categories }
					onClose={ closeModal }
					onSave={ ( changed ) =>
						saveList(
							items.map( ( i ) =>
								i.slug === changed.slug ? changed : i
							)
						)
					}
				/>
			),
		},
		{
			id: 'remove',
			label: __( 'Remove from the list', 'windrose-helm' ),
			isDestructive: true,
			supportsBulk: true,
			RenderModal: ( { items: removed, closeModal } ) => (
				<>
					<p>
						{ sprintf(
							/* translators: %s: comma-separated plugin names. */
							__(
								'Remove from the recommended list: %s? Installed plugins stay installed.',
								'windrose-helm'
							),
							removed.map( ( i ) => i.name ).join( ', ' )
						) }
					</p>
					<div className="windrose-helm__row windrose-helm__row--end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ closeModal }
						>
							{ __( 'Cancel', 'windrose-helm' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							isDestructive
							onClick={ async () => {
								const slugs = removed.map( ( i ) => i.slug );
								await saveList(
									items.filter(
										( i ) => ! slugs.includes( i.slug )
									)
								);
								closeModal();
							} }
						>
							{ __( 'Remove', 'windrose-helm' ) }
						</Button>
					</div>
				</>
			),
		},
	];

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Recommended plugins', 'windrose-helm' ) }
				</CardTitle>
				<div className="windrose-helm__row">
					{ state?.customized && (
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setResetting( true ) }
						>
							{ __(
								'Restore the default list',
								'windrose-helm'
							) }
						</Button>
					) }
					<Button
						__next40pxDefaultSize
						variant="secondary"
						disabled={ ! state }
						accessibleWhenDisabled
						onClick={ () => setAdding( true ) }
					>
						{ __( 'Add a plugin', 'windrose-helm' ) }
					</Button>
				</div>
			</CardHeader>
			<CardBody>
				{ state && ! state.wporg && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'wordpress.org is switched off (Updates tab), so plugins cannot be installed or looked up from here. Upload them from Plugins, Add Plugin, or switch wordpress.org on.',
							'windrose-helm'
						) }
					</Notice>
				) }
				<p>
					{ __(
						'Actively maintained, open-source plugins that suit the Windrose Suite. Page builders and classic-editing plugins are never recommended. Plugins not activated on the whole network are activated on each site that needs them.',
						'windrose-helm'
					) }
				</p>
			</CardBody>
			<CardBody className="windrose-helm__table">
				<DataViews
					data={ items }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ {
						totalItems: items.length,
						totalPages: 1,
					} }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => item.slug }
					isLoading={ ! state }
					search={ false }
					empty={
						<p>{ __( 'The list is empty.', 'windrose-helm' ) }</p>
					}
				/>
			</CardBody>
			{ adding && (
				<AddModal
					categories={ categories }
					notify={ notify }
					onClose={ () => setAdding( false ) }
					onAdd={ ( added ) => {
						if ( items.some( ( i ) => i.slug === added.slug ) ) {
							notify(
								'error',
								__(
									'This plugin is already in the list.',
									'windrose-helm'
								)
							);
							return false;
						}
						return saveList( [ ...items, added ] );
					} }
				/>
			) }
			{ resetting && (
				<Modal
					title={ __( 'Restore the default list?', 'windrose-helm' ) }
					onRequestClose={ () => setResetting( false ) }
					size="small"
				>
					<p>
						{ __(
							'Your changes to the list are replaced by the list that comes with Windrose Helm. Installed plugins stay installed.',
							'windrose-helm'
						) }
					</p>
					<div className="windrose-helm__row windrose-helm__row--end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setResetting( false ) }
						>
							{ __( 'Cancel', 'windrose-helm' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ async () => {
								setResetting( false );
								try {
									setState(
										await apiFetch( {
											path: PATH,
											method: 'DELETE',
										} )
									);
									notify(
										'success',
										__(
											'Default list restored.',
											'windrose-helm'
										)
									);
								} catch ( error ) {
									notify( 'error', error.message );
								}
							} }
						>
							{ __( 'Restore', 'windrose-helm' ) }
						</Button>
					</div>
				</Modal>
			) }
		</Card>
	);
}

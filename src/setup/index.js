/**
 * Windrose Helm, Set up: the choices the Windrose Suite installer leaves for after installation.
 * Steps: addresses, languages, plugins, privacy and wordpress.org, review. "Set up the network" then runs one task
 * at a time (core's wp/v2/plugins routes for plugins, windrose-helm/v1/setup/* for the rest) and shows each result.
 */
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	ComboboxControl,
	ExternalLink,
	Notice,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';

import './setup.scss';

const DATA = window.windroseHelmSetup || { privacy: {}, urls: {} };
const PATH = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

const STEPS = [
	{ id: 'addresses', title: __( 'Addresses', 'windrose-helm' ) },
	{ id: 'languages', title: __( 'Languages', 'windrose-helm' ) },
	{ id: 'plugins', title: __( 'Plugins', 'windrose-helm' ) },
	{ id: 'privacy', title: __( 'Privacy', 'windrose-helm' ) },
	{ id: 'review', title: __( 'Review', 'windrose-helm' ) },
];

/**
 * The address part proposed for a language: "fr" for fr_FR, the whole code when that is taken ("pt-br").
 *
 * @param {string}   locale Locale code.
 * @param {string[]} taken  Address parts already used.
 * @return {string} Address part.
 */
function proposedPath( locale, taken ) {
	const short = locale.split( '_' )[ 0 ].toLowerCase();
	if ( ! taken.includes( short ) ) {
		return short;
	}
	return locale.toLowerCase().replace( /_/g, '-' );
}

/**
 * Title of a language site, as the server makes it: "<network name> - <language code>", for example "Test - DE".
 *
 * @param {string} name   Network name.
 * @param {string} locale Locale code.
 * @return {string} Title.
 */
function siteTitle( name, locale ) {
	const code = locale.split( '_' )[ 0 ].toUpperCase();
	return name.trim() ? `${ name } - ${ code }` : code;
}

/**
 * The main site's title on a multilingual network: the one typed in, else "<name> - <code>" of its language.
 *
 * @param {Object} state   State from the server.
 * @param {Object} choices Choices made on the screen.
 * @return {string} Title.
 */
function mainTitleOf( state, choices ) {
	return choices.mainTitle ?? siteTitle( state.network.name, choices.main );
}

/**
 * A language site's title: the one typed in, else "<name> - <code>".
 *
 * @param {Object} state State from the server.
 * @param {Object} other The added language.
 * @return {string} Title.
 */
function titleOf( state, other ) {
	return other.title ?? siteTitle( state.network.name, other.locale );
}

function nativeName( languages, locale ) {
	const found = languages.list.find( ( item ) => item.locale === locale );
	return found ? found.native : locale;
}

/**
 * What the choices ask for, as a list of problems (empty when the review can run).
 *
 * @param {Object} state   State from the server.
 * @param {Object} choices Choices made on the screen.
 * @return {string[]} Problems.
 */
function problems( state, choices ) {
	const list = [];
	if ( choices.addresses === 'subdomain' && ! choices.wildcard ) {
		list.push(
			__(
				'Subdomains: confirm that every subdomain reaches this website (wildcard DNS), or keep subdirectories.',
				'windrose-helm'
			)
		);
	}
	if ( choices.multilingual ) {
		if ( choices.others.length === 0 ) {
			list.push(
				__(
					'Multilingual: add at least one other language, or switch multilingual off.',
					'windrose-helm'
				)
			);
		}
		const existing = state.languages.sites.map( ( site ) =>
			site.path.replace( state.addresses.path, '' ).replace( /\/$/, '' )
		);
		if ( ! mainTitleOf( state, choices ).trim() ) {
			list.push( __( 'Give the main site a title.', 'windrose-helm' ) );
		}
		const seen = [];
		choices.others.forEach( ( other ) => {
			const name = nativeName( state.languages, other.locale );
			if ( ! titleOf( state, other ).trim() ) {
				list.push(
					sprintf(
						/* translators: %s: language name. */
						__( '%s: give the site a title.', 'windrose-helm' ),
						name
					)
				);
			}
			if ( ! PATH.test( other.path ) ) {
				list.push(
					sprintf(
						/* translators: %s: language name. */
						__(
							'%s: the address can only use lowercase letters (a-z), numbers and hyphens.',
							'windrose-helm'
						),
						name
					)
				);
			} else if (
				seen.includes( other.path ) ||
				existing.includes( other.path )
			) {
				list.push(
					sprintf(
						/* translators: 1: language name, 2: address part. */
						__(
							'%1$s: the address “%2$s” is already used.',
							'windrose-helm'
						),
						name,
						other.path
					)
				);
			}
			seen.push( other.path );
		} );
	}
	return list;
}

/**
 * The tasks the choices need, in the order they must run (wordpress.org is switched off last, if at all).
 *
 * @param {Object} state   State from the server.
 * @param {Object} choices Choices made on the screen.
 * @return {Object[]} Tasks: label, request( context ), optional after( result, context ).
 */
function tasks( state, choices ) {
	const list = [];
	const { languages, plugins, addresses } = state;
	const name = ( locale ) => nativeName( languages, locale );
	const pluginRoute = ( id ) => ( {
		path: `/wp/v2/plugins/${ id }`,
		method: 'POST',
		data: { status: 'network-active' },
	} );

	if ( choices.addresses === 'subdomain' && addresses.type !== 'subdomain' ) {
		list.push( {
			label: __( 'Use subdomains for new sites', 'windrose-helm' ),
			request: () => ( {
				path: '/windrose-helm/v1/setup/addresses',
				method: 'POST',
				data: { type: 'subdomain' },
			} ),
			after: ( result, context ) => {
				context.rules = result.rules || '';
			},
		} );
	}

	const wanted = [
		choices.main,
		...( choices.multilingual
			? choices.others.map( ( o ) => o.locale )
			: [] ),
	];
	[ ...new Set( wanted ) ].forEach( ( locale ) => {
		const item = languages.list.find( ( l ) => l.locale === locale );
		if ( item && ! item.installed ) {
			list.push( {
				label: sprintf(
					/* translators: %s: language name. */
					__( 'Download %s', 'windrose-helm' ),
					item.native
				),
				request: () => ( {
					path: '/windrose-helm/v1/setup/language',
					method: 'POST',
					data: { locale },
				} ),
			} );
		}
	} );

	const mainTitle = choices.multilingual ? mainTitleOf( state, choices ) : '';
	if (
		choices.main !== languages.main ||
		( mainTitle && mainTitle !== state.network.mainTitle )
	) {
		list.push( {
			label: mainTitle
				? sprintf(
						/* translators: 1: language name, 2: site title. */
						__(
							'Main site in %1$s, titled “%2$s”',
							'windrose-helm'
						),
						name( choices.main ),
						mainTitle
					)
				: sprintf(
						/* translators: %s: language name. */
						__( 'Main site in %s', 'windrose-helm' ),
						name( choices.main )
					),
			request: () => ( {
				path: '/windrose-helm/v1/setup/main-language',
				method: 'POST',
				data: { locale: choices.main, title: mainTitle },
			} ),
		} );
	}

	if ( choices.multilingual ) {
		if ( ! languages.msls.installed ) {
			list.push( {
				label: __(
					'Install Multisite Language Switcher from wordpress.org',
					'windrose-helm'
				),
				request: () => ( {
					path: '/wp/v2/plugins',
					method: 'POST',
					data: {
						slug: 'multisite-language-switcher',
						status: 'network-active',
					},
				} ),
			} );
		} else if ( ! languages.msls.active ) {
			list.push( {
				label: __(
					'Activate Multisite Language Switcher',
					'windrose-helm'
				),
				request: () => pluginRoute( languages.msls.plugin ),
			} );
		}
		if ( ! languages.sabir.active ) {
			list.push( {
				label: __( 'Activate Windrose Sabir', 'windrose-helm' ),
				request: () => pluginRoute( languages.sabir.plugin ),
			} );
		}
		choices.others.forEach( ( other ) => {
			list.push( {
				label: sprintf(
					/* translators: 1: language name, 2: address part. */
					__( 'Create the %1$s site (%2$s)', 'windrose-helm' ),
					name( other.locale ),
					other.path
				),
				request: () => ( {
					path: '/windrose-helm/v1/setup/site',
					method: 'POST',
					data: {
						locale: other.locale,
						path: other.path,
						title: titleOf( state, other ),
					},
				} ),
				after: ( result, context ) => {
					context.sites.push( result );
				},
			} );
		} );
		list.push( {
			label: __(
				'Link the languages (a Windrose Sabir language group), and WordPress’s first post, sample page and privacy page as translations',
				'windrose-helm'
			),
			request: ( context ) => ( {
				path: '/windrose-helm/v1/setup/group',
				method: 'POST',
				data: {
					sites: [
						state.network.mainId,
						...context.sites.map( ( site ) => site.id ),
					],
					main: state.network.mainId,
				},
			} ),
		} );
	}

	if ( choices.anchors && plugins.anchors.installed ) {
		if ( ! plugins.anchors.active ) {
			list.push( {
				label: __( 'Activate Windrose Anchors', 'windrose-helm' ),
				request: () => pluginRoute( plugins.anchors.plugin ),
			} );
		}
		if ( choices.allowAll ) {
			list.push( {
				label: __(
					'Allow every site to use network media',
					'windrose-helm'
				),
				request: ( context ) => ( {
					path: '/windrose-helm/v1/setup/media',
					method: 'POST',
					data: {
						sites: [
							...languages.sites.map( ( site ) => site.id ),
							...context.sites.map( ( site ) => site.id ),
						].filter( ( id ) => id !== state.network.mainId ),
					},
				} ),
			} );
		}
	}

	plugins.recommended
		.filter( ( item ) => choices.recommended.includes( item.slug ) )
		.forEach( ( item ) => {
			const status = item.network ? 'network-active' : 'inactive';
			if ( item.status === 'not-installed' ) {
				list.push( {
					label: item.network
						? sprintf(
								/* translators: %s: plugin name. */
								__(
									'Install and network-activate %s',
									'windrose-helm'
								),
								item.name
							)
						: sprintf(
								/* translators: %s: plugin name. */
								__( 'Install %s', 'windrose-helm' ),
								item.name
							),
					request: () => ( {
						path: '/wp/v2/plugins',
						method: 'POST',
						data: { slug: item.slug, status },
					} ),
				} );
			} else if ( item.network && item.status !== 'network-active' ) {
				list.push( {
					label: sprintf(
						/* translators: %s: plugin name. */
						__( 'Activate %s', 'windrose-helm' ),
						item.name
					),
					request: () => pluginRoute( item.plugin ),
				} );
			}
		} );

	list.push( {
		label: __( 'Save privacy and wordpress.org choices', 'windrose-helm' ),
		request: () => ( {
			path: '/windrose-helm/v1/setup/finish',
			method: 'POST',
			data: { privacy: choices.privacy, wporg: choices.wporg },
		} ),
	} );
	return list;
}

function Addresses( { state, choices, update } ) {
	const { addresses } = state;
	const current = addresses.type === 'subdomain';
	return (
		<>
			<p>
				{ __(
					'Each site of the network gets its own address. Set up can switch to subdomains only while the network has one site; later, a switch means changing wp-config.php, the rewrite rules and the address of every site by hand.',
					'windrose-helm'
				) }
			</p>
			<RadioControl
				label={ __( 'Addresses of new sites', 'windrose-helm' ) }
				help={ __(
					'Subdomains need a wildcard DNS record (*) for your domain pointing to this website, and an HTTPS certificate that covers the subdomains. Set them up with your host before choosing subdomains; subdirectories need nothing more.',
					'windrose-helm'
				) }
				selected={ choices.addresses }
				onChange={ ( value ) => update( { addresses: value } ) }
				options={ [
					{
						value: 'subdirectory',
						label: sprintf(
							/* translators: %s: example address. */
							__(
								'Subdirectories, for example %s',
								'windrose-helm'
							),
							`${ addresses.domain }${ addresses.path }fr/`
						),
						disabled: current,
					},
					{
						value: 'subdomain',
						label: sprintf(
							/* translators: %s: example address. */
							__( 'Subdomains, for example %s', 'windrose-helm' ),
							`fr.${ addresses.domain }${ addresses.path }`
						),
						disabled: ! current && ! addresses.canSwitch,
					},
				] }
			/>
			{ ! current && ! addresses.canSwitch && addresses.problem && (
				<p className="description">{ addresses.problem }</p>
			) }
			{ choices.addresses === 'subdomain' && ! current && (
				<CheckboxControl
					label={ sprintf(
						/* translators: %s: domain. */
						__(
							'Every subdomain of %s reaches this website (a wildcard DNS record, *, set up with my host)',
							'windrose-helm'
						),
						addresses.domain
					) }
					checked={ choices.wildcard }
					onChange={ ( value ) => update( { wildcard: value } ) }
				/>
			) }
		</>
	);
}

function Languages( { state, choices, update } ) {
	const { languages } = state;
	const options = languages.list.map( ( item ) => ( {
		value: item.locale,
		label:
			item.native === item.name
				? item.native
				: `${ item.native } (${ item.name })`,
	} ) );
	const chosen = [ choices.main, ...choices.others.map( ( o ) => o.locale ) ];
	const existingPaths = languages.sites.map( ( site ) =>
		site.path.replace( state.addresses.path, '' ).replace( /\/$/, '' )
	);
	const addLanguage = ( locale ) => {
		if ( ! locale || chosen.includes( locale ) ) {
			return;
		}
		const taken = [
			...existingPaths,
			...choices.others.map( ( o ) => o.path ),
		];
		update( {
			others: [
				...choices.others,
				{ locale, path: proposedPath( locale, taken ) },
			],
		} );
	};
	const setOther = ( locale, changes ) =>
		update( {
			others: choices.others.map( ( o ) =>
				o.locale === locale ? { ...o, ...changes } : o
			),
		} );
	const remove = ( locale ) =>
		update( {
			others: choices.others.filter( ( o ) => o.locale !== locale ),
		} );

	return (
		<>
			{ ! languages.canInstall && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Languages cannot be downloaded here (wordpress.org is switched off, or WordPress may not write files): only the installed languages are offered.',
						'windrose-helm'
					) }
				</Notice>
			) }
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Language of the main site', 'windrose-helm' ) }
				help={ __(
					'Also the default language of new sites.',
					'windrose-helm'
				) }
				value={ choices.main }
				options={ options.filter(
					( option ) =>
						languages.canInstall ||
						languages.list.find(
							( l ) => l.locale === option.value
						)?.installed
				) }
				onChange={ ( value ) =>
					update( {
						main: value,
						others: choices.others.filter(
							( o ) => o.locale !== value
						),
					} )
				}
			/>
			{ ! languages.sabir.installed && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'For a multilingual network, Windrose Sabir must be installed first (it is not on wordpress.org): upload it in Network Admin, Plugins, Add Plugin, without activating it, then come back here.',
						'windrose-helm'
					) }
				</Notice>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				disabled={ ! languages.sabir.installed }
				label={ __(
					'Multilingual: one site per language',
					'windrose-helm'
				) }
				help={ __(
					'Installs Multisite Language Switcher from wordpress.org, activates Windrose Sabir, creates one site per language below and links them as translations of each other (a language group, with the main site as source).',
					'windrose-helm'
				) }
				checked={ choices.multilingual }
				onChange={ ( value ) => update( { multilingual: value } ) }
			/>
			{ choices.multilingual && (
				<div className="windrose-helm-setup__languages">
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'Title of the main site',
							'windrose-helm'
						) }
						value={ mainTitleOf( state, choices ) }
						onChange={ ( value ) => update( { mainTitle: value } ) }
					/>
					<ComboboxControl
						__next40pxDefaultSize
						label={ __( 'Add a language', 'windrose-helm' ) }
						value={ null }
						options={ options.filter(
							( option ) =>
								! chosen.includes( option.value ) &&
								( languages.canInstall ||
									languages.list.find(
										( l ) => l.locale === option.value
									)?.installed )
						) }
						onChange={ addLanguage }
					/>
					{ choices.others.length > 0 && (
						<ul className="windrose-helm-setup__language-list">
							{ choices.others.map( ( other ) => (
								<li key={ other.locale }>
									<TextControl
										__next40pxDefaultSize
										label={ sprintf(
											/* translators: %s: language name. */
											__(
												'Address of the %s site',
												'windrose-helm'
											),
											nativeName(
												languages,
												other.locale
											)
										) }
										help={
											state.addresses.type ===
												'subdomain' ||
											choices.addresses === 'subdomain'
												? `${ other.path }.${ state.addresses.domain }${ state.addresses.path }`
												: `${ state.addresses.domain }${ state.addresses.path }${ other.path }/`
										}
										value={ other.path }
										onChange={ ( value ) =>
											setOther( other.locale, {
												path: value.trim(),
											} )
										}
									/>
									<TextControl
										__next40pxDefaultSize
										label={ sprintf(
											/* translators: %s: language name. */
											__(
												'Title of the %s site',
												'windrose-helm'
											),
											nativeName(
												languages,
												other.locale
											)
										) }
										value={ titleOf( state, other ) }
										onChange={ ( value ) =>
											setOther( other.locale, {
												title: value,
											} )
										}
									/>
									<Button
										variant="secondary"
										isDestructive
										onClick={ () => remove( other.locale ) }
									>
										{ sprintf(
											/* translators: %s: language name. */
											__( 'Remove %s', 'windrose-helm' ),
											nativeName(
												languages,
												other.locale
											)
										) }
									</Button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</>
	);
}

function Plugins( { state, choices, update } ) {
	const { plugins } = state;
	return (
		<>
			{ plugins.anchors.installed && (
				<>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Network media (Windrose Anchors)',
							'windrose-helm'
						) }
						help={ __(
							'Images and files of the main site can be shared with the other sites, each with its own alternative text and caption.',
							'windrose-helm'
						) }
						checked={ choices.anchors }
						onChange={ ( value ) => update( { anchors: value } ) }
					/>
					{ choices.anchors && (
						<CheckboxControl
							label={ __(
								'Allow every site to use network media (sites created later are allowed in Windrose Anchors, Sites)',
								'windrose-helm'
							) }
							checked={ choices.allowAll }
							onChange={ ( value ) =>
								update( { allowAll: value } )
							}
						/>
					) }
				</>
			) }
			<fieldset className="windrose-helm-setup__recommended">
				<legend>
					{ __( 'Recommended plugins', 'windrose-helm' ) }
				</legend>
				{ ! plugins.canInstall && (
					<p className="description">
						{ __(
							'Plugins cannot be installed from wordpress.org here (switched off, or WordPress may not write files).',
							'windrose-helm'
						) }
					</p>
				) }
				{ plugins.recommended.map( ( item ) => (
					<CheckboxControl
						key={ item.slug }
						label={
							item.network
								? item.name
								: sprintf(
										/* translators: %s: plugin name. */
										__(
											'%s (installed only: activate it on the sites that need it)',
											'windrose-helm'
										),
										item.name
									)
						}
						help={ item.why }
						disabled={
							item.status === 'network-active' ||
							( ! plugins.canInstall &&
								item.status === 'not-installed' )
						}
						checked={
							choices.recommended.includes( item.slug ) ||
							item.status === 'network-active'
						}
						onChange={ ( value ) =>
							update( {
								recommended: value
									? [ ...choices.recommended, item.slug ]
									: choices.recommended.filter(
											( slug ) => slug !== item.slug
										),
							} )
						}
					/>
				) ) }
			</fieldset>
		</>
	);
}

function Privacy( { choices, update } ) {
	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Use wordpress.org (updates, plugin and theme directories, languages)',
					'windrose-helm'
				) }
				help={
					choices.wporg
						? __(
								'WordPress checks wordpress.org for updates and can install plugins, themes and languages from it. Windrose components update from GitHub either way.',
								'windrose-helm'
							)
						: __(
								'No request goes to wordpress.org: updates of WordPress, plugins and themes are manual, and the directories and language downloads are not available. Windrose components still update from GitHub.',
								'windrose-helm'
							)
				}
				checked={ choices.wporg }
				onChange={ ( value ) => update( { wporg: value } ) }
			/>
			<fieldset className="windrose-helm-setup__privacy">
				<legend>
					{ __(
						'Privacy: each switch turned on blocks what it names. All on is recommended; each can be changed later in Windrose Helm, Privacy',
						'windrose-helm'
					) }
				</legend>
				{ Object.entries( DATA.privacy ).map(
					( [ id, [ label, help ] ] ) => (
						<ToggleControl
							__nextHasNoMarginBottom
							key={ id }
							label={ label }
							help={ help }
							checked={ !! choices.privacy[ id ] }
							onChange={ ( value ) =>
								update( {
									privacy: {
										...choices.privacy,
										[ id ]: value,
									},
								} )
							}
						/>
					)
				) }
			</fieldset>
			{ DATA.requestLog && (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ DATA.requestLog[ 0 ] }
					help={ DATA.requestLog[ 1 ] }
					checked={ !! choices.privacy.request_log }
					onChange={ ( value ) =>
						update( {
							privacy: {
								...choices.privacy,
								request_log: value,
							},
						} )
					}
				/>
			) }
		</>
	);
}

function Review( { state, choices, issues } ) {
	const list = tasks( state, choices );
	return (
		<>
			<p>
				<strong>
					{ __(
						'Afterwards this screen closes for good. Everything stays changeable in Network Admin: Sites, Plugins, Settings and the Windrose screens.',
						'windrose-helm'
					) }
				</strong>
			</p>
			{ issues.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<ul>
						{ issues.map( ( issue ) => (
							<li key={ issue }>{ issue }</li>
						) ) }
					</ul>
				</Notice>
			) }
			<p>
				{ __(
					'“Set up the network” does the following, one after the other:',
					'windrose-helm'
				) }
			</p>
			<ol className="windrose-helm-setup__review">
				{ list.map( ( task ) => (
					<li key={ task.label }>{ task.label }</li>
				) ) }
			</ol>
		</>
	);
}

function Progress( { list, results, context } ) {
	return (
		<ol className="windrose-helm-setup__progress">
			{ list.map( ( task, index ) => {
				const result = results[ index ];
				return (
					<li
						key={ task.label }
						className={ result?.status || 'waiting' }
					>
						<span className="windrose-helm-setup__task">
							{ task.label }
						</span>{ ' ' }
						{ result?.status === 'running' && <Spinner /> }
						{ result?.status === 'done' && (
							<strong>{ __( 'Done', 'windrose-helm' ) }</strong>
						) }
						{ result?.status === 'failed' && (
							<strong>
								{ sprintf(
									/* translators: %s: error message. */
									__( 'Failed: %s', 'windrose-helm' ),
									result.message
								) }
							</strong>
						) }
						{ result?.status === 'skipped' && (
							<strong>
								{ __( 'Skipped', 'windrose-helm' ) }
							</strong>
						) }
					</li>
				);
			} ) }
			{ context.rules && (
				<li className="done">
					<p>
						{ __(
							'WordPress could not write the rewrite rules for subdomains into .htaccess. Replace WordPress’s rules there with these (or give them to your host):',
							'windrose-helm'
						) }
					</p>
					<textarea
						className="code"
						readOnly
						rows="9"
						value={ context.rules }
						aria-label={ __( 'Rewrite rules', 'windrose-helm' ) }
					/>
				</li>
			) }
		</ol>
	);
}

function App() {
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ step, setStep ] = useState( 0 );
	const [ choices, setChoices ] = useState( null );
	const [ running, setRunning ] = useState( null );
	const [ results, setResults ] = useState( [] );
	const [ finished, setFinished ] = useState( null );
	const context = useRef( { sites: [], rules: '' } );
	const heading = useRef( null );

	useEffect( () => {
		apiFetch( { path: '/windrose-helm/v1/setup' } )
			.then( ( loaded ) => {
				setState( loaded );
				setChoices( {
					addresses: loaded.addresses.type,
					wildcard: false,
					main: loaded.languages.main,
					multilingual: false,
					others: [],
					mainTitle: null,
					anchors: loaded.plugins.anchors.installed,
					allowAll: true,
					recommended: [],
					privacy: loaded.privacy,
					wporg: loaded.wporg,
				} );
			} )
			.catch( ( caught ) => setError( caught.message ) );
	}, [] );

	useEffect( () => {
		heading.current?.focus();
	}, [ step ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! state || ! choices ) {
		return <Spinner />;
	}

	const update = ( changes ) => setChoices( { ...choices, ...changes } );
	const issues = problems( state, choices );

	const run = async ( list, from ) => {
		setRunning( list );
		const next = [ ...results ];
		for ( let index = from; index < list.length; index++ ) {
			if ( next[ index ]?.status === 'done' ) {
				continue;
			}
			next[ index ] = { status: 'running' };
			setResults( [ ...next ] );
			try {
				const result = await apiFetch(
					list[ index ].request( context.current )
				);
				if ( list[ index ].after ) {
					list[ index ].after( result, context.current );
				}
				next[ index ] = { status: 'done' };
				setResults( [ ...next ] );
				if ( index === list.length - 1 ) {
					setFinished( result );
					speak(
						__( 'The network is set up.', 'windrose-helm' ),
						'polite'
					);
				}
			} catch ( caught ) {
				next[ index ] = { status: 'failed', message: caught.message };
				setResults( [ ...next ] );
				speak(
					sprintf(
						/* translators: 1: task, 2: error message. */
						__( '%1$s failed: %2$s', 'windrose-helm' ),
						list[ index ].label,
						caught.message
					),
					'assertive'
				);
				return;
			}
		}
	};

	const failedAt = results.findIndex( ( r ) => r?.status === 'failed' );
	const skip = () => {
		const next = [ ...results ];
		next[ failedAt ] = { status: 'skipped' };
		setResults( next );
		run( running, failedAt + 1 );
	};

	if ( running ) {
		return (
			<Card className="windrose-helm-setup__card">
				<CardBody>
					<h2 tabIndex="-1" ref={ heading }>
						{ finished
							? __( 'The network is set up', 'windrose-helm' )
							: __( 'Setting up the network', 'windrose-helm' ) }
					</h2>
					<Progress
						list={ running }
						results={ results }
						context={ context.current }
					/>
					{ failedAt >= 0 && (
						<div className="windrose-helm-setup__buttons">
							<Button
								variant="primary"
								onClick={ () => run( running, failedAt ) }
							>
								{ __( 'Try again', 'windrose-helm' ) }
							</Button>
							{ failedAt < running.length - 1 && (
								<Button variant="secondary" onClick={ skip }>
									{ __(
										'Skip this one and go on',
										'windrose-helm'
									) }
								</Button>
							) }
						</div>
					) }
					{ finished && (
						<>
							{ context.current.sites.length > 0 && (
								<ul className="windrose-helm-setup__sites">
									{ context.current.sites.map( ( site ) => (
										<li key={ site.id }>
											<ExternalLink href={ site.url }>
												{ site.url }
											</ExternalLink>
										</li>
									) ) }
								</ul>
							) }
							<div className="windrose-helm-setup__buttons">
								<Button variant="primary" href={ finished.url }>
									{ __(
										'Go to Windrose Helm',
										'windrose-helm'
									) }
								</Button>
								<Button
									variant="secondary"
									href={ DATA.urls.sites }
								>
									{ __( 'Sites', 'windrose-helm' ) }
								</Button>
							</div>
						</>
					) }
				</CardBody>
			</Card>
		);
	}

	const panels = {
		addresses: Addresses,
		languages: Languages,
		plugins: Plugins,
		privacy: Privacy,
		review: Review,
	};
	const Panel = panels[ STEPS[ step ].id ];
	const last = step === STEPS.length - 1;

	return (
		<>
			{ state.manual.rules && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ state.manual.server === 'nginx'
							? __(
									'This server runs Nginx: until these rules are in the site’s server configuration, the other sites of the network cannot be reached.',
									'windrose-helm'
								)
							: __(
									'The installer could not write the network rules into .htaccess: until WordPress’s rules there are replaced with these, the other sites of the network cannot be reached.',
									'windrose-helm'
								) }
					</p>
					<textarea
						className="code"
						readOnly
						rows="9"
						value={ state.manual.rules }
						aria-label={ __( 'Rewrite rules', 'windrose-helm' ) }
					/>
				</Notice>
			) }
			<nav
				aria-label={ __( 'Set up steps', 'windrose-helm' ) }
				className="windrose-helm-setup__steps"
			>
				<ol>
					{ STEPS.map( ( item, index ) => (
						<li key={ item.id }>
							<Button
								variant="link"
								aria-current={
									index === step ? 'step' : undefined
								}
								onClick={ () => setStep( index ) }
							>
								{ sprintf(
									/* translators: 1: step number, 2: step name. */
									__( '%1$d. %2$s', 'windrose-helm' ),
									index + 1,
									item.title
								) }
							</Button>
						</li>
					) ) }
				</ol>
			</nav>
			<Card className="windrose-helm-setup__card">
				<CardBody>
					<h2 tabIndex="-1" ref={ heading }>
						{ STEPS[ step ].title }
					</h2>
					<Panel
						state={ state }
						choices={ choices }
						update={ update }
						issues={ issues }
					/>
					<div className="windrose-helm-setup__buttons">
						{ step > 0 && (
							<Button
								variant="secondary"
								onClick={ () => setStep( step - 1 ) }
							>
								{ __( 'Back', 'windrose-helm' ) }
							</Button>
						) }
						{ ! last && (
							<Button
								variant="primary"
								onClick={ () => setStep( step + 1 ) }
							>
								{ __( 'Next', 'windrose-helm' ) }
							</Button>
						) }
						{ last && (
							<Button
								variant="primary"
								accessibleWhenDisabled
								disabled={ issues.length > 0 }
								onClick={ () => {
									setResults( [] );
									run( tasks( state, choices ), 0 );
								} }
							>
								{ __( 'Set up the network', 'windrose-helm' ) }
							</Button>
						) }
					</div>
				</CardBody>
			</Card>
		</>
	);
}

const container = document.getElementById( 'windrose-helm-setup-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}

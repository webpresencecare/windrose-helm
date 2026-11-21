/**
 * Overview: one switch per module, and where things stand.
 */
import { useEffect, useState } from '@wordpress/element';
import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import CardTitle from '../card-title';

import data from '../data';
import SwitchForm from '../switch-form';

export default function Overview( { settings, save } ) {
	const [ log, setLog ] = useState( null );

	useEffect( () => {
		if ( settings.modules.privacy ) {
			apiFetch( { path: '/windrose-helm/v1/request-log' } )
				.then( setLog )
				.catch( () => setLog( null ) );
		}
	}, [ settings.modules.privacy ] );

	const privacyOn = Object.values( settings.privacy ).filter(
		Boolean
	).length;
	const privacyAll = Object.keys( settings.privacy ).length;

	return (
		<>
			<Card>
				<CardHeader>
					<CardTitle>{ __( 'Status', 'windrose-helm' ) }</CardTitle>
				</CardHeader>
				<CardBody>
					<ul className="windrose-helm__status">
						<li>
							{ /* With the Updates module off, Helm leaves wordpress.org on (Settings::wporg_on()). */ }
							{ settings.wporg || ! settings.modules.updates
								? __(
										'wordpress.org is on: updates and the plugin and theme directories work as usual.',
										'windrose-helm'
									)
								: __(
										'wordpress.org is off: updates are manual.',
										'windrose-helm'
									) }
						</li>
						<li>
							{ settings.modules.privacy
								? sprintf(
										/* translators: 1: number of privacy switches on, 2: number of privacy switches. */
										__(
											'Privacy: %1$d of %2$d switches on.',
											'windrose-helm'
										),
										privacyOn,
										privacyAll
									)
								: __( 'Privacy module off.', 'windrose-helm' ) }
						</li>
						{ log && log.enabled && (
							<li>
								{ sprintf(
									/* translators: %d: number of logged requests. */
									__(
										'Request log: %d requests recorded.',
										'windrose-helm'
									),
									log.entries.length
								) }
							</li>
						) }
						<li>
							<a href={ data.siteHealth }>
								{ __(
									'Site Health (main site)',
									'windrose-helm'
								) }
							</a>
						</li>
					</ul>
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<CardTitle>{ __( 'Modules', 'windrose-helm' ) }</CardTitle>
				</CardHeader>
				<CardBody>
					<SwitchForm
						labels={ data.modules }
						values={ settings.modules }
						onSave={ ( modules ) => save( { modules } ) }
					/>
				</CardBody>
			</Card>
		</>
	);
}

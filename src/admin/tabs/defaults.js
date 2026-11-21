/**
 * Defaults: block-only switches.
 */
import { Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import CardTitle from '../card-title';
import data from '../data';
import SwitchForm from '../switch-form';

export default function Defaults( { settings, save } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __(
						'Block themes and the Site Editor',
						'windrose-helm'
					) }
				</CardTitle>
			</CardHeader>
			<CardBody>
				{ ! settings.modules.defaults && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'The Defaults module is off (Overview tab).',
							'windrose-helm'
						) }
					</Notice>
				) }
				<p>
					{ __(
						'On a network that already used classic themes or the Classic Editor, the first three start switched off; Site Health reports them on each site.',
						'windrose-helm'
					) }
				</p>
				<SwitchForm
					labels={ data.defaults }
					values={ settings.defaults }
					onSave={ ( defaults ) => save( { defaults } ) }
					disabled={ ! settings.modules.defaults }
				/>
			</CardBody>
		</Card>
	);
}

/**
 * A list of switches (DataForm toggles) with a Save button.
 */
import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews/wp';
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}                            props
 * @param {Object}                            props.labels   Switch ID => [ label, description ].
 * @param {Object}                            props.values   Switch ID => boolean.
 * @param {(values: Object) => Promise<void>} props.onSave   Called with the edited values.
 * @param {boolean}                           props.disabled Whether the switches are read-only.
 */
export default function SwitchForm( {
	labels,
	values,
	onSave,
	disabled = false,
} ) {
	const [ data, setData ] = useState( values );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => setData( values ), [ values ] );

	const ids = Object.keys( labels );
	const fields = ids.map( ( id ) => ( {
		id,
		type: 'boolean',
		label: labels[ id ][ 0 ],
		description: labels[ id ][ 1 ],
		Edit: 'toggle',
		isDisabled: () => disabled,
	} ) );
	const form = { layout: { type: 'regular' }, fields: ids };
	const changed = ids.some( ( id ) => data[ id ] !== values[ id ] );

	return (
		<div className="windrose-helm__switches">
			<DataForm
				data={ data }
				fields={ fields }
				form={ form }
				onChange={ ( edits ) =>
					setData( ( current ) => ( { ...current, ...edits } ) )
				}
			/>
			<div className="windrose-helm__actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ saving }
					disabled={ ! changed || saving || disabled }
					accessibleWhenDisabled
					onClick={ async () => {
						setSaving( true );
						await onSave( data );
						setSaving( false );
					} }
				>
					{ __( 'Save changes', 'windrose-helm' ) }
				</Button>
			</div>
		</div>
	);
}

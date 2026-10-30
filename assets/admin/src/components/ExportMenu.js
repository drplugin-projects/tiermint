/**
 * Button menu for CSV exports.
 *
 * Keep the menu open on failure so the error remains visible.
 */

/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Dropdown } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { get, saveBlob } from '../api/client';

/**
 * The message for a failed export. Prefer the endpoint's validation details.
 *
 * @param {*} failure The rejected value from `get()`.
 * @return {string} The message to show.
 */
function failureMessage( failure ) {
	const details =
		failure && Array.isArray( failure.errors )
			? failure.errors.filter(
					( entry ) => typeof entry === 'string' && entry !== ''
			  )
			: [];

	if ( details.length > 0 ) {
		return details.join( ' ' );
	}

	return failure && failure.message
		? failure.message
		: __( 'The export could not be downloaded.', 'tiermint' );
}

/**
 * Renders an export menu.
 *
 * @param {Object}                                                                              props       Component props.
 * @param {Array<{id: string, label: string, path: string, params?: Object, filename: string}>} props.items
 *                                                                                                          Export definitions.
 * @return {?Element} The menu, or null when no item is offered.
 */
export default function ExportMenu( { items = [] } ) {
	const [ busyId, setBusyId ] = useState( null );
	const [ error, setError ] = useState( '' );

	if ( items.length === 0 ) {
		return null;
	}

	/**
	 * Fetch one export and hand it to the browser, keeping the menu open on error.
	 *
	 * @param {Object}   item    The export item.
	 * @param {Function} onClose Closes the dropdown on success.
	 * @return {Promise<void>}
	 */
	async function download( item, onClose ) {
		setBusyId( item.id );
		setError( '' );

		try {
			// The route returns { filename, content }. Save under the item's own name.
			const { content } = await get( item.path, item.params );
			saveBlob(
				new window.Blob( [ content ], {
					type: 'text/csv;charset=utf-8',
				} ),
				item.filename
			);
			onClose();
		} catch ( failure ) {
			setError( failureMessage( failure ) );
		} finally {
			setBusyId( null );
		}
	}

	return (
		<Dropdown
			className="tiermint-export-menu"
			contentClassName="tiermint-export-menu__content"
			popoverProps={ { placement: 'bottom-end' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ onToggle }
					aria-expanded={ isOpen }
					className="tiermint-export-menu__toggle"
				>
					{ __( 'Export CSV', 'tiermint' ) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div
					role="menu"
					aria-label={ __( 'Export CSV', 'tiermint' ) }
					className="tiermint-export-menu__items"
				>
					{ items.map( ( item ) => (
						<Button
							key={ item.id }
							role="menuitem"
							variant="tertiary"
							isBusy={ busyId === item.id }
							aria-disabled={ busyId !== null }
							onClick={ () => {
								if ( busyId === null ) {
									download( item, onClose );
								}
							} }
							className="tiermint-export-menu__item"
						>
							{ item.label }
						</Button>
					) ) }

					{ error && (
						<p role="alert" className="tiermint-export-menu__error">
							{ error }
						</p>
					) }
				</div>
			) }
		/>
	);
}

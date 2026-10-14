/**
 * Single-image picker that stores one attachment id.
 *
 * Use an id chip when the media library or preview is unavailable.
 */

/**
 * External dependencies
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { openMediaLibrary, getAttachment } from '../products/mediaFrame';

/**
 * Render the single-image field.
 *
 * @param {Object}                  props          Component props.
 * @param {Object}                  props.field    The field definition (id, label, help).
 * @param {(number|string)}         props.value    The current attachment id (0/absent for none).
 * @param {(value: number) => void} props.onChange Called with the new attachment id (0 to clear).
 * @return {Element} The control element.
 */
export default function MediaField( { field, value, onChange } ) {
	const id = Number( value ) || 0;
	const [ attachment, setAttachment ] = useState( null );
	// The id the current `attachment` (or a resolution failure) belongs to, so a
	// re-render never re-fetches an id already resolved, and a failed resolve
	// does not loop.
	const resolvedId = useRef( 0 );

	useEffect( () => {
		let active = true;

		if ( id <= 0 ) {
			resolvedId.current = 0;
			setAttachment( null );
			return undefined;
		}

		if ( resolvedId.current === id ) {
			return undefined;
		}

		getAttachment( id ).then(
			( resolved ) => {
				if ( active ) {
					resolvedId.current = id;
					setAttachment( resolved );
				}
			},
			() => {
				if ( active ) {
					// Mark resolved so the effect does not retry; the id chip
					// shows instead of a preview.
					resolvedId.current = id;
					setAttachment( null );
				}
			}
		);

		return () => {
			active = false;
		};
	}, [ id ] );

	/**
	 * Open the library to pick (or replace) the image.
	 *
	 * @return {void}
	 */
	const pick = () => {
		openMediaLibrary( {
			title: field.label,
			multiple: false,
			onSelect: ( items ) => {
				const first = items && items[ 0 ];
				if ( first && first.id ) {
					resolvedId.current = first.id;
					setAttachment( first );
					onChange( first.id );
				}
			},
		} );
	};

	/**
	 * Clear the image.
	 *
	 * @return {void}
	 */
	const remove = () => {
		resolvedId.current = 0;
		setAttachment( null );
		onChange( 0 );
	};

	let body;
	if ( attachment && attachment.url ) {
		body = (
			<div className="tiermint-media-field__preview">
				<img
					className="tiermint-media-field__image"
					src={ attachment.url }
					alt={ attachment.alt || '' }
				/>
				<div className="tiermint-media-field__actions">
					<Button variant="secondary" onClick={ pick }>
						{ __( 'Replace image', 'tiermint' ) }
					</Button>
					<Button
						variant="secondary"
						isDestructive
						onClick={ remove }
					>
						{ __( 'Remove image', 'tiermint' ) }
					</Button>
				</div>
			</div>
		);
	} else if ( id > 0 ) {
		body = (
			<div className="tiermint-media-field__preview tiermint-media-field__preview--unresolved">
				<span className="tiermint-media-field__chip">
					{ sprintf(
						/* translators: %d: the image attachment id. */
						__( 'Image #%d', 'tiermint' ),
						id
					) }
				</span>
				<div className="tiermint-media-field__actions">
					<Button variant="secondary" onClick={ pick }>
						{ __( 'Replace image', 'tiermint' ) }
					</Button>
					<Button
						variant="secondary"
						isDestructive
						onClick={ remove }
					>
						{ __( 'Remove image', 'tiermint' ) }
					</Button>
				</div>
			</div>
		);
	} else {
		body = (
			<Button variant="secondary" onClick={ pick }>
				{ __( 'Select image', 'tiermint' ) }
			</Button>
		);
	}

	return (
		<div className="tiermint-media-field">
			<span className="tiermint-media-field__label">{ field.label }</span>
			{ body }
			{ field.help && (
				<p className="tiermint-media-field__help">{ field.help }</p>
			) }
		</div>
	);
}

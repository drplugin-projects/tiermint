/**
 * Ordered gallery picker that stores attachment ids.
 *
 * Use an id chip when a preview is unavailable.
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
 * Normalize the value to a list of positive integer ids.
 *
 * @param {*} value The raw field value.
 * @return {Array<number>} The id list.
 */
function toIds( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	return value
		.map( ( item ) => Number( item ) || 0 )
		.filter( ( id ) => id > 0 );
}

/**
 * Render the gallery field.
 *
 * @param {Object}                         props          Component props.
 * @param {Object}                         props.field    The field definition (id, label, help).
 * @param {Array<number>}                  props.value    The current attachment ids.
 * @param {(value: Array<number>) => void} props.onChange Called with the new id list.
 * @return {Element} The control element.
 */
export default function MediaGalleryField( { field, value, onChange } ) {
	const ids = toIds( value );
	// Resolved previews keyed by id. Kept across renders so reordering or
	// removing does not refetch, and a fresh selection seeds it directly.
	const [ previews, setPreviews ] = useState( {} );
	// Ids already resolved or attempted, so the effect fetches each id once.
	const attempted = useRef( new Set() );
	// Whether the control is still mounted. The resolve guard keys on this, not
	// on the effect run, so a preview whose fetch is still in flight when the id
	// list changes (a reorder or a remove) still lands when it resolves, instead
	// of being cancelled and never retried (the `attempted` set never forgets it).
	const mounted = useRef( true );
	useEffect(
		() => () => {
			mounted.current = false;
		},
		[]
	);

	useEffect( () => {
		const pending = ids.filter( ( id ) => ! attempted.current.has( id ) );

		pending.forEach( ( id ) => {
			attempted.current.add( id );
			getAttachment( id ).then(
				( resolved ) => {
					// Merge unconditionally: an id since removed is harmless, as
					// the list only reads previews for ids it currently shows.
					if ( mounted.current ) {
						setPreviews( ( prev ) => ( {
							...prev,
							[ id ]: resolved,
						} ) );
					}
				},
				() => {
					// Leave the id out of `previews`; the chip renders instead.
				}
			);
		} );
		// Re-run whenever the set of ids changes.
	}, [ ids.join( ',' ) ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Open the library to add images, appending new ids and skipping duplicates.
	 *
	 * @return {void}
	 */
	const add = () => {
		openMediaLibrary( {
			title: field.label,
			multiple: true,
			onSelect: ( items ) => {
				const additions = ( items || [] ).filter(
					( item ) => item && item.id
				);
				if ( additions.length === 0 ) {
					return;
				}
				// Seed the previews for what we just picked.
				setPreviews( ( prev ) => {
					const next = { ...prev };
					additions.forEach( ( item ) => {
						attempted.current.add( item.id );
						next[ item.id ] = item;
					} );
					return next;
				} );
				const existing = new Set( ids );
				const merged = ids.concat(
					additions
						.map( ( item ) => item.id )
						.filter( ( addId ) => ! existing.has( addId ) )
				);
				onChange( merged );
			},
		} );
	};

	/**
	 * Remove one image from the gallery.
	 *
	 * @param {number} id The attachment id to remove.
	 * @return {void}
	 */
	const remove = ( id ) => {
		onChange( ids.filter( ( entry ) => entry !== id ) );
	};

	/**
	 * Move the image at an index one step earlier or later.
	 *
	 * @param {number} index The current index.
	 * @param {number} delta -1 to move earlier, +1 to move later.
	 * @return {void}
	 */
	const move = ( index, delta ) => {
		const target = index + delta;
		if ( target < 0 || target >= ids.length ) {
			return;
		}
		const next = ids.slice();
		const [ moved ] = next.splice( index, 1 );
		next.splice( target, 0, moved );
		onChange( next );
	};

	return (
		<div className="tiermint-media-gallery">
			<span className="tiermint-media-gallery__label">
				{ field.label }
			</span>

			{ ids.length === 0 ? (
				<p className="tiermint-media-gallery__empty">
					{ __( 'No images yet.', 'tiermint' ) }
				</p>
			) : (
				<ul className="tiermint-media-gallery__items">
					{ ids.map( ( id, index ) => {
						const preview = previews[ id ];
						return (
							<li
								key={ id }
								className="tiermint-media-gallery__item"
							>
								{ preview && preview.url ? (
									<img
										className="tiermint-media-gallery__image"
										src={ preview.url }
										alt={ preview.alt || '' }
									/>
								) : (
									<span className="tiermint-media-gallery__chip">
										{ sprintf(
											/* translators: %d: the image attachment id. */
											__( 'Image #%d', 'tiermint' ),
											id
										) }
									</span>
								) }
								<div className="tiermint-media-gallery__actions">
									<Button
										variant="tertiary"
										disabled={ index === 0 }
										onClick={ () => move( index, -1 ) }
										aria-label={ sprintf(
											/* translators: %d: image position, starting at 1. */
											__(
												'Move image %d earlier',
												'tiermint'
											),
											index + 1
										) }
									>
										{ __( 'Move up', 'tiermint' ) }
									</Button>
									<Button
										variant="tertiary"
										disabled={ index === ids.length - 1 }
										onClick={ () => move( index, 1 ) }
										aria-label={ sprintf(
											/* translators: %d: image position, starting at 1. */
											__(
												'Move image %d later',
												'tiermint'
											),
											index + 1
										) }
									>
										{ __( 'Move down', 'tiermint' ) }
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () => remove( id ) }
										aria-label={ sprintf(
											/* translators: %d: attachment id of the image to remove. */
											__(
												'Remove image #%d',
												'tiermint'
											),
											id
										) }
									>
										{ __( 'Remove', 'tiermint' ) }
									</Button>
								</div>
							</li>
						);
					} ) }
				</ul>
			) }

			<Button variant="secondary" onClick={ add }>
				{ __( 'Add images', 'tiermint' ) }
			</Button>
			{ field.help && (
				<p className="tiermint-media-gallery__help">{ field.help }</p>
			) }
		</div>
	);
}

/**
 * Adapter for the WordPress media library.
 *
 * Keep `wp.media` access in one module for testability.
 */

/**
 * Read the `wp.media` factory, or undefined when the library is not loaded.
 *
 * @return {(Function|undefined)} The `wp.media` factory, if present.
 */
function mediaFactory() {
	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.media ) {
		return undefined;
	}
	return window.wp.media;
}

/**
 * Convert a media attachment to an image control value.
 *
 * @param {Object} json The attachment model as JSON.
 * @return {{id: number, url: string, alt: string}} The projected attachment.
 */
function toAttachment( json ) {
	const sizes = json && json.sizes ? json.sizes : {};
	const preview = sizes.medium || sizes.thumbnail || null;
	return {
		id: Number( json && json.id ) || 0,
		url: preview && preview.url ? preview.url : ( json && json.url ) || '',
		alt: ( json && json.alt ) || '',
	};
}

/**
 * Open the media library to pick one or more images.
 *
 * @param {Object}                         options            Open options.
 * @param {string}                         [options.title]    The modal title.
 * @param {boolean}                        [options.multiple] Whether to allow a multi-select.
 * @param {(items: Array<Object>) => void} options.onSelect   Called with the projected selection.
 * @return {(Object|undefined)} The opened frame, or undefined when the library is absent.
 */
export function openMediaLibrary( { title, multiple = false, onSelect } ) {
	const media = mediaFactory();
	if ( typeof media !== 'function' ) {
		return undefined;
	}

	const frame = media( {
		title,
		multiple,
		library: { type: 'image' },
	} );

	frame.on( 'select', () => {
		const selection = frame.state().get( 'selection' );
		const items = selection.map( ( model ) =>
			toAttachment( model.toJSON() )
		);
		onSelect( items );
	} );

	frame.open();
	return frame;
}

/**
 * Resolve an attachment ID to a preview value.
 *
 * Wrap the media Deferred in a native Promise.
 *
 * @param {number} id The attachment id.
 * @return {Promise<{id: number, url: string, alt: string}>} The projected attachment.
 */
export function getAttachment( id ) {
	return new Promise( ( resolve, reject ) => {
		const media = mediaFactory();
		if (
			typeof media !== 'function' ||
			typeof media.attachment !== 'function'
		) {
			reject( new Error( 'The media library is not available.' ) );
			return;
		}

		const model = media.attachment( id );
		const resolveModel = () => resolve( toAttachment( model.toJSON() ) );
		const fetched = model.fetch();

		if ( fetched && typeof fetched.then === 'function' ) {
			fetched.then( resolveModel, () =>
				reject( new Error( 'The attachment could not be loaded.' ) )
			);
			return;
		}

		// Read cached attachments immediately.
		resolveModel();
	} );
}

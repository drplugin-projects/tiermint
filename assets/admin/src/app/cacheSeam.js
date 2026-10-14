/**
 * The cache seam another bundle writes through.
 *
 * Lets extension bundles invalidate this app's queries. They cannot share the
 * React Query provider context directly.
 */

/**
 * External dependencies
 */
import { useEffect } from '@wordpress/element';
import { useQueryClient } from '@tanstack/react-query';

/**
 * Publish `invalidateResources` on the shared admin object for as long as the
 * app is mounted.
 *
 * @param {Object} props       Component props.
 * @param {Object} props.admin The `window.tierMint.admin` object, by reference.
 * @return {null} Nothing is rendered.
 */
export default function CacheSeam( { admin } ) {
	const queryClient = useQueryClient();

	useEffect( () => {
		if ( ! admin || typeof admin !== 'object' ) {
			return undefined;
		}

		/**
		 * Mark every cached read of the named resources stale.
		 *
		 * Each resource name invalidates its pages, filters and sorts.
		 *
		 * @param {Array<string>} resources The resource nouns that changed.
		 * @return {void}
		 */
		admin.invalidateResources = ( resources ) => {
			if ( ! Array.isArray( resources ) ) {
				return;
			}

			resources
				.filter(
					( resource ) =>
						'string' === typeof resource && '' !== resource
				)
				.forEach( ( resource ) =>
					queryClient.invalidateQueries( { queryKey: [ resource ] } )
				);
		};

		return () => {
			delete admin.invalidateResources;
		};
	}, [ admin, queryClient ] );

	return null;
}

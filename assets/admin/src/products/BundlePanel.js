/**
 * Bundle panel for the product editor.
 *
 * Retain saved item IDs when the product picker has no matching item.
 */

/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, SelectControl, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import EmptyState from '../components/EmptyState';
import { SkeletonPanel } from '../components/Skeleton';
import { firstReadPending } from '../utils/listState';
import { toInt } from './productForm';

/**
 * Render the bundle panel.
 *
 * @param {Object}               props               Component props.
 * @param {Object}               props.form          The current product form state.
 * @param {Function}             props.setForm       The form state setter.
 * @param {(number|string|null)} props.productId     The product being edited (excluded from its own bundle).
 * @param {Object}               props.productsQuery The product picker query.
 * @return {Element} The bundle panel element.
 */
export default function BundlePanel( {
	form,
	setForm,
	productId,
	productsQuery,
} ) {
	const pickerInHand = Boolean(
		productsQuery.data && Array.isArray( productsQuery.data.items )
	);
	const pickerItems =
		productsQuery.data && Array.isArray( productsQuery.data.items )
			? productsQuery.data.items
			: [];

	// Disable the picker without data so saved IDs cannot be changed accidentally.
	const pickerUnusable =
		! pickerInHand &&
		( firstReadPending( productsQuery ) || productsQuery.isError );

	const bundleOptions = [
		{ label: __( 'Select a product', 'tiermint' ), value: '' },
		...pickerItems
			.filter( ( item ) => String( item.id ) !== String( productId ) )
			.map( ( item ) => ( {
				label: item.name,
				value: String( item.id ),
			} ) ),
	];

	/**
	 * Get row options, including a saved item absent from the picker.
	 *
	 * @param {number} chosen The product id the row currently holds.
	 * @return {Array<{label: string, value: string}>} The row's option list.
	 */
	const bundleOptionsFor = ( chosen ) => {
		if (
			! chosen ||
			bundleOptions.some(
				( option ) => option.value === String( chosen )
			)
		) {
			return bundleOptions;
		}

		return [
			...bundleOptions,
			{
				/* translators: %d: product id. */
				label: sprintf( __( 'Product #%d', 'tiermint' ), chosen ),
				value: String( chosen ),
			},
		];
	};

	const addBundleItemButton = (
		<Button
			variant="secondary"
			// Do not add an item until its picker has products.
			disabled={ pickerUnusable }
			onClick={ () =>
				setForm( ( prev ) => ( {
					...prev,
					bundle_items: [ ...prev.bundle_items, { product_id: 0 } ],
				} ) )
			}
		>
			{ __( 'Add bundle item', 'tiermint' ) }
		</Button>
	);

	return (
		<div className="tiermint-panel">
			{ ! pickerInHand && productsQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ productsQuery.error.message }
				</Notice>
			) }
			{ /* Show a loading state while the picker is unavailable. */ }
			{ ! pickerInHand && firstReadPending( productsQuery ) && (
				<SkeletonPanel />
			) }
			{ /* Do not show an empty state while the picker is loading. */ }
			{ form.bundle_items.length === 0 &&
				! ( ! pickerInHand && firstReadPending( productsQuery ) ) && (
					<EmptyState
						className="tiermint-empty-state--panel"
						message={ __(
							'No bundled products yet. Add the products a buyer gets with this bundle.',
							'tiermint'
						) }
						action={ addBundleItemButton }
					/>
				) }
			{ form.bundle_items.map( ( item, index ) => (
				<div key={ index } className="tiermint-bundle-row">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						disabled={ pickerUnusable }
						label={ __( 'Bundled product', 'tiermint' ) }
						value={ String( item.product_id || '' ) }
						options={ bundleOptionsFor( item.product_id ) }
						onChange={ ( value ) =>
							setForm( ( prev ) => {
								const nextItems = prev.bundle_items.slice();
								nextItems[ index ] = {
									...nextItems[ index ],
									product_id: toInt( value ),
								};
								return { ...prev, bundle_items: nextItems };
							} )
						}
					/>
					{ /* Match the adjacent select height. */ }
					<Button
						variant="secondary"
						isDestructive
						__next40pxDefaultSize
						onClick={ () =>
							setForm( ( prev ) => ( {
								...prev,
								bundle_items: prev.bundle_items.filter(
									( entry, position ) => position !== index
								),
							} ) )
						}
					>
						{ __( 'Remove item', 'tiermint' ) }
					</Button>
				</div>
			) ) }
			{ form.bundle_items.length > 0 && (
				<div className="tiermint-form-actions">
					{ addBundleItemButton }
				</div>
			) }
		</div>
	);
}

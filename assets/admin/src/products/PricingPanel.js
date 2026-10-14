/**
 * Pricing panel for the product editor.
 *
 * Product saves include the complete price-tier list.
 */

/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextControl,
	SelectControl,
	CheckboxControl,
	ToggleControl,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import EmptyState from '../components/EmptyState';
import { toInt, blankPrice } from './productForm';
import {
	LICENSE_LENGTH_BILLING,
	parseLicenseLength,
	formatLicenseLength,
} from './licenseLength';

/**
 * Render the pricing panel.
 *
 * @param {Object}   props                       Component props.
 * @param {Object}   props.form                  The current product form state.
 * @param {Function} props.setForm               The form state setter.
 * @param {string}   props.currency              The store currency code.
 * @param {boolean}  props.recurringTiersEnabled Whether recurring controls are editable.
 * @return {Element} The pricing panel element.
 */
export default function PricingPanel( {
	form,
	setForm,
	currency,
	recurringTiersEnabled,
} ) {
	/**
	 * Update one field of the price row at a given index.
	 *
	 * @param {number} index The price row index.
	 * @param {string} key   The price field.
	 * @param {*}      value The new value.
	 * @return {void}
	 */
	const updatePrice = ( index, key, value ) =>
		setForm( ( prev ) => {
			const prices = prev.prices.slice();
			prices[ index ] = { ...prices[ index ], [ key ]: value };
			return { ...prev, prices };
		} );

	/**
	 * Toggle subscription status and clear its billing-period sentinel.
	 *
	 * @param {number}  index   The price row index.
	 * @param {boolean} checked Whether the tier is now a subscription.
	 * @return {void}
	 */
	const setSubscription = ( index, checked ) =>
		setForm( ( prev ) => {
			const prices = prev.prices.slice();
			const current = prices[ index ];
			const next = { ...current, is_subscription: checked };
			if (
				! checked &&
				current.license_length === LICENSE_LENGTH_BILLING
			) {
				next.license_length = '';
			}
			prices[ index ] = next;
			return { ...prev, prices };
		} );

	// Store singular duration units but show plural labels.
	const licenseUnitOptions = [
		{ label: __( 'Days', 'tiermint' ), value: 'day' },
		{ label: __( 'Weeks', 'tiermint' ), value: 'week' },
		{ label: __( 'Months', 'tiermint' ), value: 'month' },
		{ label: __( 'Years', 'tiermint' ), value: 'year' },
	];

	/**
	 * Render the license-length control for one tier.
	 *
	 * @param {Object} price The price row.
	 * @param {number} index The price row index.
	 * @return {Element} The control element.
	 */
	const renderLicenseLength = ( price, index ) => {
		const raw = price.license_length;
		const parsed = parseLicenseLength( raw );
		// Ask for a length after clearing the subscription sentinel.
		const isPrompt =
			raw === '' ||
			( parsed.mode === 'billing' && ! price.is_subscription );

		// Preserve an unrecognised value until the merchant replaces it.
		if ( parsed.mode === 'custom' && ! isPrompt ) {
			return (
				<div className="tiermint-license-length">
					<span className="tiermint-license-length__label">
						{ __( 'License length', 'tiermint' ) }
					</span>
					<p className="tiermint-license-length__custom">
						{ sprintf(
							/* translators: %s: the stored license-length value. */
							__( 'Custom: %s', 'tiermint' ),
							parsed.raw
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ () =>
							updatePrice(
								index,
								'license_length',
								formatLicenseLength( 1, 'month' )
							)
						}
					>
						{ __( 'Replace', 'tiermint' ) }
					</Button>
				</div>
			);
		}

		const mode = isPrompt ? 'prompt' : parsed.mode;
		const modeOptions = [
			...( isPrompt
				? [
						{
							label: __( 'Select a length', 'tiermint' ),
							value: 'prompt',
						},
				  ]
				: [] ),
			{ label: __( 'Lifetime', 'tiermint' ), value: 'lifetime' },
			{ label: __( 'Duration', 'tiermint' ), value: 'duration' },
			...( price.is_subscription
				? [
						{
							label: __( 'Same as billing interval', 'tiermint' ),
							value: 'billing',
						},
				  ]
				: [] ),
		];

		/**
		 * Set the selected license-length mode.
		 *
		 * @param {string} value The selected mode.
		 * @return {void}
		 */
		const onModeChange = ( value ) => {
			if ( value === 'lifetime' ) {
				updatePrice( index, 'license_length', null );
			} else if ( value === 'billing' ) {
				updatePrice( index, 'license_length', LICENSE_LENGTH_BILLING );
			} else if ( value === 'duration' ) {
				updatePrice(
					index,
					'license_length',
					formatLicenseLength( 1, 'month' )
				);
			}
		};

		return (
			<div className="tiermint-license-length">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'License length', 'tiermint' ) }
					value={ mode }
					options={ modeOptions }
					onChange={ onModeChange }
				/>
				{ mode === 'duration' && (
					<div className="tiermint-license-length__duration">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							label={ __( 'Length', 'tiermint' ) }
							value={ String( parsed.count ) }
							onChange={ ( value ) =>
								updatePrice(
									index,
									'license_length',
									formatLicenseLength(
										Math.max( 1, toInt( value ) ),
										parsed.unit
									)
								)
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Unit', 'tiermint' ) }
							value={ parsed.unit }
							options={ licenseUnitOptions }
							onChange={ ( value ) =>
								updatePrice(
									index,
									'license_length',
									formatLicenseLength( parsed.count, value )
								)
							}
						/>
					</div>
				) }
			</div>
		);
	};

	/**
	 * Get selected switch-target IDs for a source tier.
	 *
	 * @param {number} sourceId The source tier's price id.
	 * @return {number[]} The selected target price ids.
	 */
	const upgradeTargetsFor = ( sourceId ) => {
		const paths =
			form.settings && form.settings.upgrade_paths
				? form.settings.upgrade_paths
				: {};
		const list = paths[ String( sourceId ) ] || paths[ sourceId ] || [];
		return Array.isArray( list ) ? list.map( Number ) : [];
	};

	/**
	 * Toggle an allowed switch target for a source tier.
	 *
	 * @param {number}  sourceId The source tier's price id.
	 * @param {number}  targetId The target tier's price id.
	 * @param {boolean} checked  Whether the target is now allowed.
	 * @return {void}
	 */
	const toggleUpgradeTarget = ( sourceId, targetId, checked ) =>
		setForm( ( prev ) => {
			const paths = {
				...( ( prev.settings && prev.settings.upgrade_paths ) || {} ),
			};
			const key = String( sourceId );
			const current = new Set(
				( paths[ key ] || paths[ sourceId ] || [] ).map( Number )
			);
			if ( checked ) {
				current.add( targetId );
			} else {
				current.delete( targetId );
			}
			// Keep only the canonical string key.
			delete paths[ sourceId ];
			delete paths[ key ];
			if ( current.size > 0 ) {
				paths[ key ] = Array.from( current );
			}
			const settings = { ...prev.settings };
			if ( Object.keys( paths ).length > 0 ) {
				settings.upgrade_paths = paths;
			} else {
				delete settings.upgrade_paths;
			}
			return { ...prev, settings };
		} );

	const addPriceButton = (
		<Button
			variant="secondary"
			onClick={ () =>
				setForm( ( prev ) => ( {
					...prev,
					prices: [ ...prev.prices, blankPrice() ],
				} ) )
			}
		>
			{ __( 'Add price', 'tiermint' ) }
		</Button>
	);

	return (
		<div className="tiermint-panel">
			{ form.prices.length === 0 && (
				<EmptyState
					className="tiermint-empty-state--panel"
					message={ __(
						'No prices yet. A product needs at least one price before it can be bought.',
						'tiermint'
					) }
					action={ addPriceButton }
				/>
			) }
			{ form.prices.map( ( price, index ) => (
				<fieldset key={ index } className="tiermint-price-row">
					<legend className="tiermint-price-row__legend">
						{ sprintf(
							/* translators: %d: the position of this price in the list, starting at 1. */
							__( 'Price %d', 'tiermint' ),
							index + 1
						) }
					</legend>
					<div className="tiermint-form-stack">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Price name', 'tiermint' ) }
							value={ price.name }
							onChange={ ( value ) =>
								updatePrice( index, 'name', value )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							label={ sprintf(
								/* translators: %s: the store's currency code, for example USD. */
								__( 'Amount (%s)', 'tiermint' ),
								currency
							) }
							value={ price.amount }
							onChange={ ( value ) =>
								updatePrice( index, 'amount', value )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							label={ __( 'Activation limit', 'tiermint' ) }
							value={ String( price.activation_limit ) }
							onChange={ ( value ) =>
								updatePrice(
									index,
									'activation_limit',
									toInt( value )
								)
							}
						/>
						{ renderLicenseLength( price, index ) }
						{ /* Subscriptions always have a quantity of one. */ }
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							label={ __( 'Minimum quantity', 'tiermint' ) }
							value={ String(
								price.is_subscription ? 1 : price.min_quantity
							) }
							disabled={ price.is_subscription }
							onChange={ ( value ) =>
								updatePrice(
									index,
									'min_quantity',
									toInt( value )
								)
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							label={ __(
								'Maximum quantity (0 = unlimited)',
								'tiermint'
							) }
							value={ String(
								price.is_subscription ? 1 : price.max_quantity
							) }
							disabled={ price.is_subscription }
							onChange={ ( value ) =>
								updatePrice(
									index,
									'max_quantity',
									toInt( value )
								)
							}
						/>
						{ /* Show existing recurring data when Pro controls are unavailable. */ }
						{ ( recurringTiersEnabled ||
							price.is_subscription ) && (
							<>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __( 'Subscription', 'tiermint' ) }
									checked={ price.is_subscription }
									disabled={ ! recurringTiersEnabled }
									onChange={ ( value ) =>
										setSubscription( index, value )
									}
								/>
								{ ! recurringTiersEnabled &&
									price.is_subscription && (
										<p className="tiermint-recurring-pro-hint">
											{ __(
												'Recurring billing requires TierMint Pro.',
												'tiermint'
											) }
										</p>
									) }
								{ price.is_subscription && (
									<>
										<SelectControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											label={ __(
												'Billing period',
												'tiermint'
											) }
											value={ price.billing_period }
											disabled={ ! recurringTiersEnabled }
											options={ [
												{
													label: __(
														'Day',
														'tiermint'
													),
													value: 'day',
												},
												{
													label: __(
														'Week',
														'tiermint'
													),
													value: 'week',
												},
												{
													label: __(
														'Month',
														'tiermint'
													),
													value: 'month',
												},
												{
													label: __(
														'Year',
														'tiermint'
													),
													value: 'year',
												},
											] }
											onChange={ ( value ) =>
												updatePrice(
													index,
													'billing_period',
													value
												)
											}
										/>
										<TextControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											type="number"
											label={ __(
												'Billing interval',
												'tiermint'
											) }
											value={ String(
												price.billing_interval
											) }
											disabled={ ! recurringTiersEnabled }
											onChange={ ( value ) =>
												updatePrice(
													index,
													'billing_interval',
													toInt( value )
												)
											}
										/>
										<TextControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											type="number"
											label={ __(
												'Trial days',
												'tiermint'
											) }
											value={ String( price.trial_days ) }
											disabled={ ! recurringTiersEnabled }
											onChange={ ( value ) =>
												updatePrice(
													index,
													'trial_days',
													toInt( value )
												)
											}
										/>
										{ /* Only saved recurring tiers can be switch targets. */ }
										{ recurringTiersEnabled &&
											!! price.id &&
											form.prices.some(
												( other ) =>
													!! other.id &&
													other.is_subscription &&
													other.id !== price.id
											) && (
												<fieldset className="tiermint-switch-targets">
													<legend>
														{ __(
															'Allowed switch targets',
															'tiermint'
														) }
													</legend>
													<p className="tiermint-switch-targets__help">
														{ __(
															'Subscribers on this tier can switch to the tiers selected here.',
															'tiermint'
														) }
													</p>
													{ form.prices
														.filter(
															( other ) =>
																!! other.id &&
																other.is_subscription &&
																other.id !==
																	price.id
														)
														.map( ( other ) => (
															<CheckboxControl
																__nextHasNoMarginBottom
																key={ other.id }
																label={ sprintf(
																	/* translators: %s: the name of the target price tier. */
																	__(
																		'Allow switching to %s',
																		'tiermint'
																	),
																	other.name ||
																		sprintf(
																			/* translators: %d: the target price tier id, used when the tier has no name. */
																			__(
																				'Price #%d',
																				'tiermint'
																			),
																			other.id
																		)
																) }
																checked={ upgradeTargetsFor(
																	price.id
																).includes(
																	other.id
																) }
																onChange={ (
																	checked
																) =>
																	toggleUpgradeTarget(
																		price.id,
																		other.id,
																		checked
																	)
																}
															/>
														) ) }
												</fieldset>
											) }
									</>
								) }
							</>
						) }
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Price status', 'tiermint' ) }
							value={ price.status }
							options={ [
								{
									label: __( 'Active', 'tiermint' ),
									value: 'active',
								},
								{
									label: __( 'Archived', 'tiermint' ),
									value: 'archived',
								},
							] }
							onChange={ ( value ) =>
								updatePrice( index, 'status', value )
							}
						/>
					</div>
					<div className="tiermint-form-actions">
						<Button
							variant="secondary"
							isDestructive
							onClick={ () =>
								setForm( ( prev ) => ( {
									...prev,
									prices: prev.prices.filter(
										( item, position ) => position !== index
									),
								} ) )
							}
						>
							{ __( 'Remove price', 'tiermint' ) }
						</Button>
					</div>
				</fieldset>
			) ) }
			{ form.prices.length > 0 && (
				<div className="tiermint-form-actions">{ addPriceButton }</div>
			) }
		</div>
	);
}

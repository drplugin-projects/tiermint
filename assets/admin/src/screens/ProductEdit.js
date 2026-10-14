/**
 * Product editor screen.
 *
 * Creates and edits products at `#/products/:id`.
 */

/**
 * External dependencies
 */
import {
	useState,
	useEffect,
	useRef,
	useContext,
	useSyncExternalStore,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
	TextControl,
	TextareaControl,
	TabPanel,
	Notice,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { get, getList, post, put } from '../api/client';
import ScreenHeader from '../components/ScreenHeader';
import RichTextField from '../components/RichTextField';
import MediaField from '../components/MediaField';
import MediaGalleryField from '../components/MediaGalleryField';
import { SkeletonForm } from '../components/Skeleton';
import BundlePanel from '../products/BundlePanel';
import DetailsPanel from '../products/DetailsPanel';
import PricingPanel from '../products/PricingPanel';
import PublishSidebar from '../products/PublishSidebar';
import ReleasesPanel from '../products/ReleasesPanel';
import { ProductFieldRendererContext } from '../products/fieldRenderers';
import { reconcileLoadedProduct } from '../products/productForm';
import { useUnsavedChanges } from '../app/dirtyState';
import { setHash } from '../app/navigate';
import { minorToDecimal, decimalToMinor } from '../utils/currency';
import { firstReadPending } from '../utils/listState';

/**
 * Normalize a price row loaded from the server into the form's shape.
 *
 * @param {Object} price    The server price row.
 * @param {string} currency The store currency, so the minor-unit amount is
 *                          rendered with the right number of decimal places.
 * @return {Object} The form price row.
 */
function normalizePrice( price, currency ) {
	return {
		id: price.id,
		name: price.name || '',
		amount: minorToDecimal( price.amount, currency ),
		activation_limit: Number( price.activation_limit ) || 0,
		// Keep the license-length grammar and sentinels as strings.
		license_length:
			price.license_length === undefined || price.license_length === null
				? null
				: String( price.license_length ),
		is_subscription: !! price.is_subscription,
		billing_period: price.billing_period || 'month',
		billing_interval: Number( price.billing_interval ) || 1,
		trial_days: Number( price.trial_days ) || 0,
		// Form value 0 means no quantity limit.
		min_quantity: Number( price.min_quantity ) || 1,
		max_quantity: Number( price.max_quantity ) || 0,
		status: price.status || 'active',
	};
}

/**
 * Creates blank product form state.
 *
 * @return {Object} A fresh product form.
 */
function blankForm() {
	return {
		name: '',
		slug: '',
		status: 'draft',
		type: 'digital',
		overview: '',
		description: '',
		is_licensed: false,
		update_slug: '',
		files: [],
		settings: {},
		prices: [],
		bundle_items: [],
		categories: [],
		tags: [],
	};
}

/**
 * Creates form state from a loaded product.
 *
 * @param {Object} data     The product payload.
 * @param {string} currency The store currency, threaded to price normalization.
 * @return {Object} The form state.
 */
function formFromProduct( data, currency ) {
	return {
		name: data.name || '',
		slug: data.slug || '',
		status: data.status || 'draft',
		type: data.type || 'digital',
		overview: data.overview || '',
		description: data.description || '',
		is_licensed: !! data.is_licensed,
		update_slug: data.update_slug || '',
		files: Array.isArray( data.files ) ? data.files : [],
		settings:
			data.settings && typeof data.settings === 'object'
				? data.settings
				: {},
		prices: Array.isArray( data.prices )
			? data.prices.map( ( price ) => normalizePrice( price, currency ) )
			: [],
		bundle_items: Array.isArray( data.bundle_items )
			? data.bundle_items
			: [],
		categories: Array.isArray( data.categories )
			? data.categories.map( ( term ) => term.id )
			: [],
		tags: Array.isArray( data.tags )
			? data.tags.map( ( term ) => term.id )
			: [],
	};
}

/**
 * Builds the create or update request body.
 *
 * @param {Object} form     The current form state.
 * @param {string} currency The store currency, so each price's decimal amount
 *                          converts to the correct minor-unit integer.
 * @return {Object} The request body.
 */
function buildBody( form, currency ) {
	return {
		name: form.name,
		slug: form.slug,
		status: form.status,
		type: form.type,
		// Rich-text columns bind directly to form state.
		overview: form.overview,
		description: form.description,
		is_licensed: form.is_licensed,
		update_slug: form.update_slug,
		files: form.files,
		settings: form.settings,
		// The API replaces all prices in their displayed order.
		prices: form.prices.map( ( price, index ) => {
			// Subscriptions allow one item. Null maximum means unlimited.
			const enteredMax = Number( price.max_quantity );
			let minQuantity = Number( price.min_quantity ) || 1;
			let maxQuantity = enteredMax > 0 ? enteredMax : null;
			if ( price.is_subscription ) {
				minQuantity = 1;
				maxQuantity = 1;
			}

			const out = {
				name: price.name,
				amount: decimalToMinor( price.amount, currency ),
				activation_limit: Number( price.activation_limit ) || 0,
				// Send the raw length value, or null for lifetime.
				license_length:
					price.license_length === '' ||
					price.license_length === undefined ||
					price.license_length === null
						? null
						: price.license_length,
				is_subscription: !! price.is_subscription,
				billing_period: price.billing_period,
				billing_interval: Number( price.billing_interval ) || 1,
				trial_days: Number( price.trial_days ) || 0,
				min_quantity: minQuantity,
				max_quantity: maxQuantity,
				sort_order: index,
				status: price.status,
			};
			if ( price.id ) {
				out.id = price.id;
			}
			return out;
		} ),
		bundle_items:
			form.type === 'bundle'
				? form.bundle_items.map( ( item, index ) => ( {
						child_product_id: Number( item.child_product_id ) || 0,
						// Keep a tier set through the API. This tab cannot set one.
						child_price_id: item.child_price_id ?? null,
						sort_order: index,
				  } ) )
				: [],
		categories: form.categories,
		tags: form.tags,
	};
}

/**
 * Renders one registered product field.
 *
 * @param {Object}                   props           Component props.
 * @param {Object}                   props.field     The field definition (id, type, label, help).
 * @param {*}                        props.value     The current value from the product settings.
 * @param {(value: *) => void}       props.onChange  Store a new value for the field.
 * @param {Object<string, Function>} props.renderers Extension renderers keyed by field type.
 * @return {Element} The field control element.
 */
function ProductFieldControl( { field, value, onChange, renderers } ) {
	const Renderer = renderers[ field.type ];
	if ( Renderer ) {
		return (
			<Renderer field={ field } value={ value } onChange={ onChange } />
		);
	}

	switch ( field.type ) {
		case 'rich_text':
			return (
				<RichTextField
					field={ field }
					value={ typeof value === 'string' ? value : '' }
					onChange={ onChange }
				/>
			);
		case 'media':
			return (
				<MediaField
					field={ field }
					value={ Number( value ) || 0 }
					onChange={ onChange }
				/>
			);
		case 'media_gallery':
			return (
				<MediaGalleryField
					field={ field }
					value={ Array.isArray( value ) ? value : [] }
					onChange={ onChange }
				/>
			);
		case 'textarea':
			return (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ field.label }
					help={ field.help || undefined }
					value={ typeof value === 'string' ? value : '' }
					onChange={ onChange }
				/>
			);
		case 'text':
			return (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ field.label }
					help={ field.help || undefined }
					value={ typeof value === 'string' ? value : '' }
					onChange={ onChange }
				/>
			);
		default:
			return (
				<p className="tiermint-product-field__unsupported">
					{ sprintf(
						/* translators: %s: the field label. */
						__(
							'The "%s" field needs an extension that is not active.',
							'tiermint'
						),
						field.label
					) }
				</p>
			);
	}
}

/**
 * Product editor.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.params Route params.
 * @param {Object} props.boot   Boot data.
 * @return {Element} Element.
 */
export default function ProductEdit( { params, boot } ) {
	const isNew = params.id === 'new';
	const productId = isNew ? null : params.id;
	const currency =
		( boot && boot.settings && boot.settings.currency ) || 'USD';
	// Pro alone exposes recurring controls. The server enforces this boundary.
	const recurringTiersEnabled = Boolean(
		boot && boot.features && boot.features.recurring_tiers
	);
	// Pro alone exposes release channels and staged rollouts.
	const releaseChannelsEnabled = Boolean(
		boot && boot.features && boot.features.release_channels
	);
	const queryClient = useQueryClient();

	// Subscribe so late extension renderers update the editor.
	const productFieldRenderers = useContext( ProductFieldRendererContext );
	const renderers = useSyncExternalStore(
		productFieldRenderers.subscribe,
		productFieldRenderers.getSnapshot
	);
	const productFieldDefs =
		boot && boot.productFields && Array.isArray( boot.productFields.fields )
			? boot.productFields.fields
			: [];
	const productPanels =
		boot && boot.productFields && Array.isArray( boot.productFields.panels )
			? boot.productFields.panels
			: [];

	const [ form, setForm ] = useState( blankForm );
	const [ saveError, setSaveError ] = useState( null );

	/* Snapshot the saved form. Shared builders keep JSON key order stable. */
	const [ savedSnapshot, setSavedSnapshot ] = useState( () =>
		JSON.stringify( blankForm() )
	);
	useUnsavedChanges( JSON.stringify( form ) !== savedSnapshot );

	/* Keep the submitted form so later edits remain dirty after the response. */
	const submitted = useRef( null );

	const productQuery = useQuery( {
		queryKey: [ 'product', productId ],
		queryFn: () => get( `/products/${ productId }` ),
		enabled: ! isNew,
	} );

	// The product picker for the bundle tab only matters for bundles.
	const productsQuery = useQuery( {
		queryKey: [ 'products', 'picker' ],
		queryFn: () => getList( '/products', { per_page: 100 } ),
		enabled: form.type === 'bundle',
	} );

	/* Keep current state for the load effect without re-running it. */
	const formRef = useRef( form );
	formRef.current = form;
	const savedSnapshotRef = useRef( savedSnapshot );
	savedSnapshotRef.current = savedSnapshot;

	/* Track the loaded product to prevent state from crossing route changes. */
	const heldProductId = useRef( null );

	/* Reset when this persistent route component moves to the create screen. */
	useEffect( () => {
		if ( ! isNew || heldProductId.current === null ) {
			return;
		}

		heldProductId.current = null;
		const blank = blankForm();
		setForm( blank );
		setSavedSnapshot( JSON.stringify( blank ) );
	}, [ isNew ] );

	/* Load the form and reconcile refreshes without replacing later edits. */
	useEffect( () => {
		if ( ! productQuery.data ) {
			return;
		}

		const loaded = formFromProduct( productQuery.data, currency );
		const held = heldProductId.current;
		heldProductId.current = productQuery.data.id;

		if ( held !== null && held !== productQuery.data.id ) {
			setForm( loaded );
			setSavedSnapshot( JSON.stringify( loaded ) );
			return;
		}

		const { form: next, snapshot } = reconcileLoadedProduct(
			loaded,
			formRef.current,
			JSON.parse( savedSnapshotRef.current || '{}' )
		);

		setForm( next );
		setSavedSnapshot( JSON.stringify( snapshot ) );
	}, [ productQuery.data, currency ] );

	const saveMutation = useMutation( {
		mutationFn: ( body ) =>
			isNew
				? post( '/products', body )
				: put( `/products/${ productId }`, body ),
		onSuccess: ( saved ) => {
			setSaveError( null );
			// Keep edits made after the request as unsaved.
			setSavedSnapshot( submitted.current );
			queryClient.invalidateQueries( { queryKey: [ 'products' ] } );
			if ( ! isNew ) {
				queryClient.invalidateQueries( {
					queryKey: [ 'product', productId ],
				} );
				// License rows embed product names, so a rename invalidates them.
				queryClient.invalidateQueries( { queryKey: [ 'licenses' ] } );
			}
			// Open the saved product after creation.
			if ( isNew && saved && saved.id ) {
				// Use setHash so the unsaved-changes guard accepts this redirect.
				setHash( `#/products/${ saved.id }` );
			}
		},
		onError: ( error ) => setSaveError( error ),
	} );

	/**
	 * Update a single top-level form field.
	 *
	 * @param {string} key   The field name.
	 * @param {*}      value The new value.
	 * @return {void}
	 */
	const update = ( key, value ) =>
		setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );

	/**
	 * Stores a registered field. Empty values remove their settings key.
	 *
	 * @param {string} fieldId The field id (a top-level settings key).
	 * @param {*}      value   The new value.
	 * @return {void}
	 */
	const setSettingField = ( fieldId, value ) =>
		setForm( ( prev ) => {
			const settings = { ...prev.settings };
			const isEmpty =
				value === '' ||
				value === 0 ||
				value === null ||
				value === undefined ||
				( Array.isArray( value ) && value.length === 0 );
			if ( isEmpty ) {
				delete settings[ fieldId ];
			} else {
				settings[ fieldId ] = value;
			}
			return { ...prev, settings };
		} );

	/**
	 * Toggle a term id in the categories or tags selection.
	 *
	 * @param {string}  key     Either `categories` or `tags`.
	 * @param {number}  id      The term id.
	 * @param {boolean} checked Whether the term is now selected.
	 * @return {void}
	 */
	const toggleTerm = ( key, id, checked ) =>
		setForm( ( prev ) => {
			const next = new Set( prev[ key ] );
			if ( checked ) {
				next.add( id );
			} else {
				next.delete( id );
			}
			return { ...prev, [ key ]: Array.from( next ) };
		} );

	/**
	 * Submit the current form as a create or update.
	 *
	 * @return {void}
	 */
	const onSave = () => {
		submitted.current = JSON.stringify( form );
		saveMutation.mutate( buildBody( form, currency ) );
	};

	// Details, categories and tags sit outside these tabs.
	const tabs = [
		{ name: 'pricing', title: __( 'Pricing', 'tiermint' ) },
		...( form.type === 'bundle'
			? [ { name: 'bundle', title: __( 'Bundle', 'tiermint' ) } ]
			: [] ),
		{ name: 'releases', title: __( 'Releases', 'tiermint' ) },
	];

	// Omit registered panels with no visible fields.
	const productFieldGroups = productPanels
		.map( ( panel ) => ( {
			...panel,
			fields: productFieldDefs.filter(
				( field ) => field.panel === panel.id
			),
		} ) )
		.filter( ( group ) => group.fields.length > 0 );

	/**
	 * Render a set of registered field groups as bordered panels.
	 *
	 * @param {Array} groups The grouped field definitions to render.
	 * @return {Element|boolean} The panels node, or false when there are none.
	 */
	const renderFieldPanels = ( groups ) =>
		groups.length > 0 && (
			<div className="tiermint-product-fields">
				{ groups.map( ( group ) => (
					<fieldset
						key={ group.id }
						className="tiermint-product-fields__panel"
					>
						<legend className="tiermint-product-fields__legend">
							{ group.label }
						</legend>
						<div className="tiermint-form-stack">
							{ group.fields.map( ( field ) => {
								// Column fields use form state, settings fields use the settings blob.
								const isColumn = field.storage === 'column';
								return (
									<div
										key={ field.id }
										className="tiermint-product-field"
									>
										<ProductFieldControl
											field={ field }
											value={
												isColumn
													? form[ field.id ]
													: form.settings[ field.id ]
											}
											onChange={ ( value ) =>
												isColumn
													? update( field.id, value )
													: setSettingField(
															field.id,
															value
													  )
											}
											renderers={ renderers }
										/>
									</div>
								);
							} ) }
						</div>
					</fieldset>
				) ) }
			</div>
		);

	// Media stays in the sidebar. Other field panels stay in the main column.
	const mediaFieldPanels = renderFieldPanels(
		productFieldGroups.filter( ( group ) => group.id === 'media' )
	);
	const mainFieldPanels = renderFieldPanels(
		productFieldGroups.filter( ( group ) => group.id !== 'media' )
	);

	// Use the saved permalink while a slug edit is unsaved.
	const detailsPanel = (
		<DetailsPanel
			form={ form }
			update={ update }
			fieldPanels={ mainFieldPanels }
			isNew={ isNew }
			permalink={ productQuery.data ? productQuery.data.permalink : null }
		/>
	);

	const pricingPanel = (
		<PricingPanel
			form={ form }
			setForm={ setForm }
			currency={ currency }
			recurringTiersEnabled={ recurringTiersEnabled }
		/>
	);

	const bundlePanel = (
		<BundlePanel
			form={ form }
			setForm={ setForm }
			productId={ productId }
			productsQuery={ productsQuery }
		/>
	);

	const releasesPanel = isNew ? (
		<Notice status="warning" isDismissible={ false }>
			{ __(
				'Save the product before you can manage releases.',
				'tiermint'
			) }
		</Notice>
	) : (
		<ReleasesPanel
			productId={ productId }
			channelsEnabled={ releaseChannelsEnabled }
		/>
	);

	return (
		<section className="tiermint-admin-screen tiermint-product-edit">
			<ScreenHeader
				title={
					isNew
						? __( 'Add product', 'tiermint' )
						: __( 'Edit product', 'tiermint' )
				}
				backLink={ {
					href: '#/products',
					label: __( 'Back to products', 'tiermint' ),
				} }
			/>

			{ ! isNew && firstReadPending( productQuery ) && (
				<SkeletonForm fields={ 4 } />
			) }

			{ /* Wait for a successful read so a blank form cannot overwrite a product. */ }
			{ ! isNew && productQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ productQuery.error.message }
				</Notice>
			) }

			{ saveError && (
				<Notice status="error" isDismissible={ false }>
					<p>{ __( 'Please fix the errors below.', 'tiermint' ) }</p>
					{ saveError.errors ? (
						<ul>
							{ Object.keys( saveError.errors ).map(
								( field ) => (
									<li key={ field }>
										{ []
											.concat( saveError.errors[ field ] )
											.join( ' ' ) }
									</li>
								)
							) }
						</ul>
					) : (
						saveError.message && <p>{ saveError.message }</p>
					) }
				</Notice>
			) }

			{ ( isNew || productQuery.isSuccess ) && (
				<div className="tiermint-product-edit__layout">
					<div className="tiermint-product-edit__main">
						{ detailsPanel }
						<TabPanel className="tiermint-tabs" tabs={ tabs }>
							{ ( tab ) => {
								if ( tab.name === 'bundle' ) {
									return bundlePanel;
								}
								if ( tab.name === 'releases' ) {
									return releasesPanel;
								}
								return pricingPanel;
							} }
						</TabPanel>
					</div>

					<PublishSidebar
						form={ form }
						update={ update }
						onToggleTerm={ toggleTerm }
						isSaving={ saveMutation.isPending }
						onSave={ onSave }
						mediaPanels={ mediaFieldPanels }
					/>
				</div>
			) }
		</section>
	);
}

/**
 * Product editor route.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/products/:id',
	title: __( 'Edit product', 'tiermint' ),
	capability: 'manage_tiermint',
	component: ProductEdit,
};

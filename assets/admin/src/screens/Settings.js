/**
 * Settings screen.
 *
 * Plugin settings screen.
 *
 * Loads settings from REST and saves staged field changes across tabs.
 */

/**
 * External dependencies
 */
import {
	useContext,
	useMemo,
	useRef,
	useState,
	useSyncExternalStore,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
	TextControl,
	ToggleControl,
	SelectControl,
	ComboboxControl,
	CheckboxControl,
	Button,
	Notice,
	Modal,
	Tooltip,
	Dropdown,
	ColorPicker,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { get, put, post, del } from '../api/client';
import { useUnsavedChanges } from '../app/dirtyState';
import { replaceHashAnnounced } from '../app/navigate';
import { SkeletonForm, SkeletonPanel } from '../components/Skeleton';
import { firstReadPending } from '../utils/listState';
import { FieldRendererContext } from '../settings/fieldRenderers';
import UpsellFeatures from '../upsell/UpsellFeatures';
import {
	groupFieldsBySection,
	partitionByGateway,
	gatewayLabel,
	runsByGroup,
	groupLabel,
	buildPutValues,
	webhookEventsLabel,
	toMessages,
	countryOptions,
	currencyOptions,
	pageOptions,
} from './settings/values';

/**
 * Settings query key.
 *
 * @type {Array<string>}
 */
const SETTINGS_KEY = [ 'settings' ];

/**
 * Webhooks query key.
 *
 * @type {Array<string>}
 */
const WEBHOOKS_KEY = [ 'settings', 'webhooks' ];

/**
 * Converts a page-picker value to an integer.
 *
 * @param {(string|null)} raw The selected option value.
 * @return {number} The parsed page id, or 0.
 */
function coercePage( raw ) {
	const parsed = parseInt( raw, 10 );
	return Number.isNaN( parsed ) ? 0 : parsed;
}

/**
 * Renders one secret field.
 *
 * @param {Object}                                            props             Component props.
 * @param {{id: string, label: string, description?: string}} props.field       The field definition.
 * @param {{set: boolean, mask: (string|null)}}               props.served      The served secret metadata.
 * @param {(string|null|undefined)}                           props.stagedValue The staged value, if any.
 * @param {(id: string, value: (string|null)) => void}        props.onChange    Stage a value.
 * @param {(id: string) => void}                              props.onRestore   Undo a staged clear.
 * @return {Element} The secret field element.
 */
function SecretField( { field, served, stagedValue, onChange, onRestore } ) {
	const isSet = !! ( served && served.set );
	const mask = served && served.mask ? served.mask : '';
	const stagedForRemoval = stagedValue === null;
	const typed = typeof stagedValue === 'string' ? stagedValue : '';

	if ( stagedForRemoval ) {
		return (
			<div className="tiermint-secret-field tiermint-secret-field--removing">
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						// translators: %s is the human-readable field label.
						__(
							'The "%s" secret will be removed when you save.',
							'tiermint'
						),
						field.label
					) }
				</Notice>
				<Button variant="link" onClick={ () => onRestore( field.id ) }>
					{ __( 'Undo', 'tiermint' ) }
				</Button>
			</div>
		);
	}

	const label = isSet
		? sprintf(
				// translators: %s is the human-readable field label.
				__( '%s (enter a new value to replace)', 'tiermint' ),
				field.label
		  )
		: field.label;

	return (
		<div className="tiermint-secret-field">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="password"
				autoComplete="off"
				label={ label }
				help={ field.description || undefined }
				value={ typed }
				placeholder={ isSet ? mask : undefined }
				onChange={ ( value ) => onChange( field.id, value ) }
			/>
			{ isSet && (
				<Button
					variant="secondary"
					onClick={ () => onChange( field.id, null ) }
				>
					{ __( 'Clear', 'tiermint' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Renders a colour control. Clear stages the server's default value.
 *
 * @param {Object}                         props              Component props.
 * @param {{id: string, label: string}}    props.field        The field definition.
 * @param {*}                              props.currentValue The current value (staged when dirty, else served).
 * @param {(string|undefined)}             props.help         The field's help text.
 * @param {(id: string, value: *) => void} props.onChange     Stage a value.
 * @return {Element} The colour field element.
 */
function ColorField( { field, currentValue, help, onChange } ) {
	const value = typeof currentValue === 'string' ? currentValue : '';
	const shown = value || __( 'Default', 'tiermint' );

	return (
		<div className="tiermint-field tiermint-color-field">
			<span className="tiermint-color-field__label">{ field.label }</span>
			<Dropdown
				className="tiermint-color-field__dropdown"
				contentClassName="tiermint-color-field__popover"
				popoverProps={ { placement: 'bottom-start' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						variant="secondary"
						className="tiermint-color-field__toggle"
						aria-expanded={ isOpen }
						aria-label={ sprintf(
							// translators: 1: a label, 2: its value.
							__( '%1$s: %2$s', 'tiermint' ),
							field.label,
							shown
						) }
						onClick={ onToggle }
					>
						<span
							className="tiermint-color-field__swatch"
							style={ {
								backgroundColor: value || 'transparent',
							} }
							aria-hidden="true"
						/>
						<span className="tiermint-color-field__value">
							{ shown }
						</span>
					</Button>
				) }
				renderContent={ () => (
					<div className="tiermint-color-field__content">
						<ColorPicker
							color={ value || undefined }
							enableAlpha={ false }
							copyFormat="hex"
							onChange={ ( next ) => onChange( field.id, next ) }
						/>
						<Button
							variant="tertiary"
							className="tiermint-color-field__clear"
							onClick={ () => onChange( field.id, '' ) }
						>
							{ __( 'Clear', 'tiermint' ) }
						</Button>
					</div>
				) }
			/>
			{ help && <p className="tiermint-color-field__help">{ help }</p> }
		</div>
	);
}

/**
 * Renders one settings control. Missing external renderers show a disabled placeholder.
 *
 * @param {Object}                         props                 Component props.
 * @param {Object}                         props.field           The field definition (id, type, label, external, ...).
 * @param {*}                              props.currentValue    The current value (staged when dirty, else served).
 * @param {(string|null|undefined)}        props.stagedValue     The staged value for secrets.
 * @param {*}                              props.served          The served value (the {set, mask} object for secrets).
 * @param {(id: string, value: *) => void} props.onChange        Stage a value for a field.
 * @param {(id: string) => void}           props.onRestoreSecret Undo a staged secret clear.
 * @param {(Function|undefined)}           props.renderer        An extension-registered renderer for this field type, if any.
 * @param {Object}                         props.catalog         The boot reference catalogs (countries, currencies, pages).
 * @param {Object}                         props.upsell          The boot upsell block (url, intro, features, linkLabel).
 * @return {Element} The field control element.
 */
function FieldControl( {
	field,
	currentValue,
	stagedValue,
	served,
	onChange,
	onRestoreSecret,
	renderer,
	catalog,
	upsell,
} ) {
	// The built-in Pro teaser: the intro line and the feature cards. It is
	// render-only, so it is caught before the external-field placeholder below.
	if ( field.type === 'pro_upsell' ) {
		return (
			<div className="tiermint-field tiermint-upsell tiermint-upsell--settings">
				{ upsell.intro && (
					<p className="tiermint-upsell__intro">{ upsell.intro }</p>
				) }
				<UpsellFeatures
					features={ upsell.features }
					url={ upsell.url }
					linkLabel={
						upsell.linkLabel || __( 'See TierMint Pro', 'tiermint' )
					}
				/>
			</div>
		);
	}

	// An extension owns this field type: hand it the full props contract.
	if ( renderer ) {
		const Renderer = renderer;
		return (
			<Renderer
				field={ field }
				currentValue={ currentValue }
				stagedValue={ stagedValue }
				served={ served }
				onChange={ onChange }
				onRestoreSecret={ onRestoreSecret }
			/>
		);
	}

	// An extension-owned field whose renderer never loaded: show a read-only
	// placeholder so the field is accounted for but cannot be edited or saved.
	if ( field.external ) {
		return (
			<div className="tiermint-field tiermint-field--external">
				<span className="tiermint-field__label">{ field.label }</span>
				<p className="tiermint-field__external-note">
					{ __(
						'This setting is provided by an extension that is not loaded.',
						'tiermint'
					) }
				</p>
			</div>
		);
	}

	if ( field.type === 'secret' ) {
		return (
			<SecretField
				field={ field }
				served={ served }
				stagedValue={ stagedValue }
				onChange={ onChange }
				onRestore={ onRestoreSecret }
			/>
		);
	}

	const help = field.description || undefined;

	switch ( field.type ) {
		case 'color':
			return (
				<ColorField
					field={ field }
					currentValue={ currentValue }
					help={ help }
					onChange={ onChange }
				/>
			);
		case 'toggle':
			return (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ field.label }
					help={ help }
					checked={ !! currentValue }
					onChange={ ( value ) => onChange( field.id, value ) }
				/>
			);
		case 'select':
			return (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ field.label }
					help={ help }
					value={ currentValue ?? '' }
					options={ field.options || [] }
					onChange={ ( value ) => onChange( field.id, value ) }
				/>
			);
		case 'currency':
			return (
				<ComboboxControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ field.label }
					help={ help }
					value={ currentValue ?? '' }
					options={ currencyOptions(
						catalog.currencies,
						currentValue
					) }
					onChange={ ( value ) => onChange( field.id, value ?? '' ) }
				/>
			);
		case 'country':
			return (
				<ComboboxControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ field.label }
					help={ help }
					value={ currentValue ?? '' }
					options={ countryOptions(
						catalog.countries,
						currentValue
					) }
					onChange={ ( value ) => onChange( field.id, value ?? '' ) }
				/>
			);
		case 'page':
			return (
				<ComboboxControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ field.label }
					help={ help }
					value={ String( currentValue ?? 0 ) }
					options={ pageOptions( catalog.pages, currentValue ) }
					onChange={ ( value ) =>
						onChange( field.id, coercePage( value ) )
					}
				/>
			);
		case 'email':
			return (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="email"
					label={ field.label }
					help={ help }
					value={ currentValue ?? '' }
					onChange={ ( value ) => onChange( field.id, value ) }
				/>
			);
		default:
			return (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="text"
					label={ field.label }
					help={ help }
					value={ currentValue ?? '' }
					onChange={ ( value ) => onChange( field.id, value ) }
				/>
			);
	}
}

/**
 * Renders the webhook table and toolbar. Deletes require confirmation.
 *
 * @param {Object}                                             props            Component props.
 * @param {{catalog: Array<string>, endpoints: Array<Object>}} props.data       The webhooks payload.
 * @param {(id: string) => boolean}                            props.isToggling Whether that endpoint's active write is in flight.
 * @param {(id: string) => boolean}                            props.isDeleting Whether that endpoint's delete is in flight.
 * @param {(id: string, active: boolean) => void}              props.onToggle   Toggle an endpoint's active flag.
 * @param {(id: string) => void}                               props.onDelete   Delete an endpoint.
 * @param {() => void}                                         props.onAdd      Open the add-endpoint modal.
 * @return {Element} The webhooks panel element.
 */
function WebhooksPanel( {
	data,
	isToggling,
	isDeleting,
	onToggle,
	onDelete,
	onAdd,
} ) {
	const [ confirmingId, setConfirmingId ] = useState( null );
	const endpoints = Array.isArray( data.endpoints ) ? data.endpoints : [];

	return (
		<div className="tiermint-webhooks">
			<div className="tiermint-toolbar tiermint-webhooks__toolbar">
				<Button variant="secondary" onClick={ onAdd }>
					{ __( 'Add endpoint', 'tiermint' ) }
				</Button>
			</div>

			{ endpoints.length === 0 ? (
				<p>{ __( 'No webhook endpoints yet.', 'tiermint' ) }</p>
			) : (
				<table className="tiermint-webhooks__table wp-list-table widefat striped">
					<thead>
						<tr>
							<th>{ __( 'URL', 'tiermint' ) }</th>
							<th>{ __( 'Events', 'tiermint' ) }</th>
							<th>{ __( 'Active', 'tiermint' ) }</th>
							<th>{ __( 'Status', 'tiermint' ) }</th>
							<th>{ __( 'Actions', 'tiermint' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ endpoints.map( ( endpoint ) => (
							<tr key={ endpoint.id }>
								<td>{ endpoint.url }</td>
								<td>
									{ webhookEventsLabel( endpoint.events ) }
								</td>
								<td>
									{ /* ToggleControl needs native disabled while saving. */ }
									{ /* CSS hides this unique label without an unsupported prop. */ }
									<ToggleControl
										__nextHasNoMarginBottom
										label={ sprintf(
											// translators: %s is the endpoint URL.
											__( 'Active for %s', 'tiermint' ),
											endpoint.url
										) }
										checked={ !! endpoint.active }
										disabled={ isToggling( endpoint.id ) }
										onChange={ ( value ) =>
											onToggle( endpoint.id, value )
										}
									/>
								</td>
								<td>
									{ endpoint.failing && (
										<Tooltip
											text={ __(
												'Every retry to this endpoint failed, so TierMint stopped sending to it. Check that the URL is reachable and returns a 2xx response, then switch the endpoint back to active.',
												'tiermint'
											) }
										>
											<span className="tiermint-badge tiermint-badge--failing">
												{ __( 'Failing', 'tiermint' ) }
											</span>
										</Tooltip>
									) }
								</td>
								<td>
									{ confirmingId === endpoint.id ? (
										<>
											<Button
												variant="link"
												isDestructive
												onClick={ () => {
													onDelete( endpoint.id );
													setConfirmingId( null );
												} }
											>
												{ __(
													'Confirm delete',
													'tiermint'
												) }
											</Button>
											<Button
												variant="tertiary"
												onClick={ () =>
													setConfirmingId( null )
												}
											>
												{ __( 'Cancel', 'tiermint' ) }
											</Button>
										</>
									) : (
										/* Keep delete unavailable until its mutation settles. */
										<Button
											variant="link"
											isDestructive
											isBusy={ isDeleting( endpoint.id ) }
											disabled={ isDeleting(
												endpoint.id
											) }
											__experimentalIsFocusable
											onClick={ () =>
												setConfirmingId( endpoint.id )
											}
										>
											{ __( 'Delete', 'tiermint' ) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

/**
 * Renders the add-endpoint modal and its one-time secret view.
 *
 * @param {Object}                                                  props          Component props.
 * @param {Array<string>}                                           props.catalog  Available event names.
 * @param {boolean}                                                 props.busy     Whether a create request is in flight.
 * @param {(Array<string>|null)}                                    props.errors   Validation messages to display.
 * @param {(string|null)}                                           props.secret   The one-time signing secret, once created.
 * @param {() => void}                                              props.onClose  Close and reset the modal.
 * @param {(payload: {url: string, events: Array<string>}) => void} props.onCreate Create the endpoint.
 * @return {Element} The modal element.
 */
function AddEndpointModal( {
	catalog,
	busy,
	errors,
	secret,
	onClose,
	onCreate,
} ) {
	const [ url, setUrl ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );
	const [ copied, setCopied ] = useState( false );
	const events = Array.isArray( catalog ) ? catalog : [];

	if ( secret ) {
		/**
		 * Copy the signing secret to the clipboard, tolerating denial.
		 *
		 * @return {Promise<void>} Resolves once the copy attempt settles.
		 */
		const copy = async () => {
			try {
				if (
					typeof navigator !== 'undefined' &&
					navigator.clipboard &&
					navigator.clipboard.writeText
				) {
					await navigator.clipboard.writeText( secret );
					setCopied( true );
				}
			} catch {
				// A clipboard denial is non-fatal: the field stays selectable
				// so the user can copy manually.
				setCopied( false );
			}
		};

		return (
			<Modal
				className="tiermint-modal tiermint-modal--confirm"
				title={ __( 'Endpoint created', 'tiermint' ) }
				onRequestClose={ onClose }
			>
				<div className="tiermint-form-stack">
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Copy this signing secret now. For your security it is shown once and can never be retrieved again.',
							'tiermint'
						) }
					</Notice>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						readOnly
						label={ __( 'Signing secret', 'tiermint' ) }
						value={ secret }
						onChange={ () => undefined }
					/>
				</div>
				<div className="tiermint-modal-actions">
					<Button variant="secondary" onClick={ copy }>
						{ __( 'Copy secret', 'tiermint' ) }
					</Button>
					{ copied && (
						<span role="status">
							{ __( 'Copied.', 'tiermint' ) }
						</span>
					) }
					<Button variant="primary" onClick={ onClose }>
						{ __( 'Done', 'tiermint' ) }
					</Button>
				</div>
			</Modal>
		);
	}

	/**
	 * Toggle an event in the pending selection.
	 *
	 * @param {string}  name    The event name.
	 * @param {boolean} checked Whether it is now selected.
	 * @return {void}
	 */
	const toggleEvent = ( name, checked ) => {
		setSelected( ( prev ) =>
			checked ? [ ...prev, name ] : prev.filter( ( n ) => n !== name )
		);
	};

	return (
		<Modal
			className="tiermint-modal"
			title={ __( 'Add endpoint', 'tiermint' ) }
			onRequestClose={ onClose }
		>
			<div className="tiermint-form-stack">
				{ errors && (
					<Notice status="error" isDismissible={ false }>
						<ul>
							{ errors.map( ( message ) => (
								<li key={ message }>{ message }</li>
							) ) }
						</ul>
					</Notice>
				) }
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="url"
					label={ __( 'Endpoint URL', 'tiermint' ) }
					value={ url }
					onChange={ setUrl }
				/>
				<fieldset className="tiermint-modal__events">
					<legend>{ __( 'Events', 'tiermint' ) }</legend>
					{ events.map( ( name ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ name }
							label={ name }
							checked={ selected.includes( name ) }
							onChange={ ( checked ) =>
								toggleEvent( name, checked )
							}
						/>
					) ) }
				</fieldset>
			</div>
			<div className="tiermint-modal-actions">
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => onCreate( { url, events: selected } ) }
				>
					{ __( 'Create endpoint', 'tiermint' ) }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'tiermint' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Renders the active settings tab with one staged-edit map.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.boot     Normalized boot data and reference catalogs.
 * @param {Object} [props.params] Optional active tab and payment subtab.
 * @return {Element} The settings screen element.
 */
export default function Settings( { boot, params } ) {
	const queryClient = useQueryClient();

	// The catalog-backed field controls (currency, country, page) read their
	// options from here. Defaulted defensively so the screen renders even when a
	// caller passes a partial boot (the isolation tests do).
	const catalog =
		boot && boot.catalog
			? boot.catalog
			: { countries: [], currencies: [], pages: [] };

	// The Pro teaser tab reads its copy from here. Defaulted so a partial boot
	// (the isolation tests) still renders the screen.
	const upsell =
		boot && boot.upsell
			? boot.upsell
			: { url: '', intro: '', features: [], linkLabel: '' };

	// Extension-provided field renderers. Subscribing to the registry re-renders
	// this screen when a renderer registers late (for example a bundle that
	// finishes loading after a deep link mounts the screen).
	const fieldRenderers = useContext( FieldRendererContext );
	const renderers = useSyncExternalStore(
		fieldRenderers.subscribe,
		fieldRenderers.getSnapshot
	);

	const settingsQuery = useQuery( {
		queryKey: SETTINGS_KEY,
		queryFn: () => get( '/settings' ),
	} );
	const webhooksQuery = useQuery( {
		queryKey: WEBHOOKS_KEY,
		queryFn: () => get( '/settings/webhooks' ),
	} );

	// The staged-edit map holds only fields the user has touched. Success
	// notice and save errors are UI-only state cleared on the next edit/save.
	const [ staged, setStaged ] = useState( {} );
	// The staged map as it was at submit time. A ref, not state, because nothing
	// renders from it: it exists so a successful save can tell the fields it sent
	// from the ones typed while it was out.
	const submittedStaged = useRef( {} );
	const [ saved, setSaved ] = useState( false );
	const [ saveErrors, setSaveErrors ] = useState( null );
	const [ modalOpen, setModalOpen ] = useState( false );
	const [ createdSecret, setCreatedSecret ] = useState( null );
	const [ modalErrors, setModalErrors ] = useState( null );

	// Track each endpoint action locally so pending writes stay guarded.
	const [ busyKeys, setBusyKeys ] = useState( { toggle: [], delete: [] } );

	/**
	 * Mark one endpoint as having a write of the given kind in flight.
	 *
	 * @param {string} action One of `toggle` or `delete`.
	 * @param {string} id     The endpoint the write was issued for.
	 * @return {void}
	 */
	const markBusy = ( action, id ) => {
		setBusyKeys( ( prev ) => ( {
			...prev,
			[ action ]: prev[ action ].concat( id ),
		} ) );
	};

	/**
	 * Removes one settled endpoint key without freeing other busy controls.
	 *
	 * @param {string} action One of `toggle` or `delete`.
	 * @param {string} id     The endpoint the write was issued for.
	 * @return {void}
	 */
	const clearBusy = ( action, id ) => {
		setBusyKeys( ( prev ) => ( {
			...prev,
			[ action ]: prev[ action ].filter( ( entry ) => entry !== id ),
		} ) );
	};

	/**
	 * Whether an endpoint's active flag is being written, so its toggle must be
	 * refused.
	 *
	 * @param {string} id The endpoint id.
	 * @return {boolean} True while that endpoint's PUT is in flight.
	 */
	const isToggling = ( id ) => busyKeys.toggle.includes( id );

	/**
	 * Whether an endpoint is being deleted, so its row's Delete must be refused.
	 *
	 * @param {string} id The endpoint id.
	 * @return {boolean} True while that endpoint's DELETE is in flight.
	 */
	const isDeleting = ( id ) => busyKeys.delete.includes( id );

	const data = settingsQuery.data;
	const values = data ? data.values : {};

	// Derive from the stable react-query `data` reference so these memos do not
	// re-run every render (a fresh `data ? data.fields : []` array otherwise
	// would change identity each time).
	const fieldsById = useMemo( () => {
		const map = {};
		( data ? data.fields : [] ).forEach( ( field ) => {
			map[ field.id ] = field;
		} );
		return map;
	}, [ data ] );

	const grouped = useMemo(
		() =>
			groupFieldsBySection(
				data ? data.sections : [],
				data ? data.fields : []
			),
		[ data ]
	);

	// Show tabs only for populated sections, apart from the Webhooks panel.
	const tabSections = useMemo(
		() =>
			grouped.filter(
				( section ) =>
					'webhooks' === section.id ||
					( Array.isArray( section.fields ) &&
						section.fields.length > 0 )
			),
		[ grouped ]
	);

	// The active tab is the one named in the hash, or the first tab when the hash
	// names none or names one that no longer exists (a hand-typed or stale link).
	const requestedTab = params && params.tab;
	const firstTabId = tabSections[ 0 ] ? tabSections[ 0 ].id : null;
	const activeTabId = tabSections.some(
		( section ) => section.id === requestedTab
	)
		? requestedTab
		: firstTabId;
	const activeSection =
		tabSections.find( ( section ) => section.id === activeTabId ) || null;

	const pendingValues = useMemo(
		() => buildPutValues( staged, fieldsById ),
		[ staged, fieldsById ]
	);
	const hasPending = Object.keys( pendingValues ).length > 0;

	// Core field edits live here until a successful save clears them.
	useUnsavedChanges( hasPending );

	const saveMutation = useMutation( {
		mutationFn: ( body ) => put( '/settings', { values: body } ),
		onSuccess: ( fresh ) => {
			// The response is the authoritative fresh payload; reset from it.
			queryClient.setQueryData( SETTINGS_KEY, fresh );
			const invalidations = [];
			if ( 'currency' in submittedStaged.current ) {
				// Both report reads format minor units in the store currency. A
				// currency save makes their cached payloads stale immediately.
				invalidations.push(
					queryClient.invalidateQueries( {
						queryKey: [ 'reports' ],
					} ),
					queryClient.invalidateQueries( {
						queryKey: [ 'dashboard' ],
					} )
				);
			}
			// Clear only saved values that did not change during the request.
			setStaged( ( prev ) => {
				const sent = submittedStaged.current;
				const next = {};

				Object.keys( prev ).forEach( ( id ) => {
					if ( ! ( id in sent ) || prev[ id ] !== sent[ id ] ) {
						next[ id ] = prev[ id ];
					}
				} );

				return next;
			} );
			setSaveErrors( null );
			setSaved( true );
			return Promise.all( invalidations );
		},
		onError: ( error ) => {
			setSaved( false );
			setSaveErrors( toMessages( error ) );
		},
	} );

	// Return row refreshes so controls stay guarded until current data arrives.
	const toggleMutation = useMutation( {
		mutationFn: ( { id, active } ) =>
			put( `/settings/webhooks/${ id }`, { active } ),
		onMutate: ( { id } ) => {
			markBusy( 'toggle', id );
		},
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: WEBHOOKS_KEY } ),
		// `result` rather than `data`, which is the settings payload in this
		// scope; the settled response is not used either way.
		onSettled: ( result, error, { id } ) => {
			clearBusy( 'toggle', id );
		},
	} );

	const deleteMutation = useMutation( {
		mutationFn: ( id ) => del( `/settings/webhooks/${ id }` ),
		onMutate: ( id ) => {
			markBusy( 'delete', id );
		},
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: WEBHOOKS_KEY } ),
		onSettled: ( result, error, id ) => {
			clearBusy( 'delete', id );
		},
	} );

	const createMutation = useMutation( {
		mutationFn: ( { url, events } ) =>
			post( '/settings/webhooks', { url, events } ),
		onSuccess: ( result ) => {
			setModalErrors( null );
			setCreatedSecret( result && result.secret ? result.secret : '' );
			queryClient.invalidateQueries( { queryKey: WEBHOOKS_KEY } );
		},
		onError: ( error ) => setModalErrors( toMessages( error ) ),
	} );

	/**
	 * Stage a value for a field and drop any lingering saved notice.
	 *
	 * @param {string} id    The field id.
	 * @param {*}      value The staged value (plaintext or null for secrets).
	 * @return {void}
	 */
	const stageField = ( id, value ) => {
		setSaved( false );
		setStaged( ( prev ) => ( { ...prev, [ id ]: value } ) );
	};

	/**
	 * Undo a staged secret clear by removing it from the staged map.
	 *
	 * @param {string} id The field id.
	 * @return {void}
	 */
	const restoreSecret = ( id ) => {
		setSaved( false );
		setStaged( ( prev ) => {
			const next = { ...prev };
			delete next[ id ];
			return next;
		} );
	};

	/**
	 * Close the add-endpoint modal and reset its transient state.
	 *
	 * @return {void}
	 */
	const closeModal = () => {
		setModalOpen( false );
		setCreatedSecret( null );
		setModalErrors( null );
	};

	/**
	 * Render one field's control, wired to the shared staged/served value maps.
	 *
	 * @param {Object} field The field definition.
	 * @return {Element} The control.
	 */
	const renderField = ( field ) => (
		<FieldControl
			key={ field.id }
			field={ field }
			currentValue={
				field.id in staged ? staged[ field.id ] : values[ field.id ]
			}
			stagedValue={ field.id in staged ? staged[ field.id ] : undefined }
			served={ values[ field.id ] }
			onChange={ stageField }
			onRestoreSecret={ restoreSecret }
			renderer={ renderers[ field.type ] }
			catalog={ catalog }
			upsell={ upsell }
		/>
	);

	/**
	 * Render a field list, boxing any field that names a group into a fieldset
	 * with the rest of its group.
	 *
	 * @param {Array<Object>} fields The fields to render, in served order.
	 * @return {Array<Element>} The controls and boxes.
	 */
	const renderFields = ( fields ) =>
		runsByGroup( fields ).map( ( run ) =>
			null === run.group ? (
				renderField( run.fields[ 0 ] )
			) : (
				<fieldset
					key={ `group:${ run.group }` }
					className={ `tiermint-settings__group tiermint-settings__group--${ run.group }` }
				>
					<legend>{ groupLabel( run.group ) }</legend>
					{ run.fields.map( renderField ) }
				</fieldset>
			)
		);

	// The Webhooks tab's content: the endpoints panel with its own load gate. It
	// only mounts when that tab is active, so it is built here and placed below.
	const webhooksContent = (
		<>
			{ firstReadPending( webhooksQuery ) && <SkeletonPanel /> }
			{ webhooksQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ webhooksQuery.error.message }
				</Notice>
			) }
			{ webhooksQuery.data && (
				<WebhooksPanel
					data={ webhooksQuery.data }
					isToggling={ isToggling }
					isDeleting={ isDeleting }
					onToggle={ ( id, active ) =>
						toggleMutation.mutate( { id, active } )
					}
					onDelete={ ( id ) => deleteMutation.mutate( id ) }
					onAdd={ () => {
						setModalErrors( null );
						setCreatedSecret( null );
						setModalOpen( true );
					} }
				/>
			) }
		</>
	);

	// Build the active tab's body and the list of fields it shows. The Payments
	// tab splits into gateway sub-tabs; the Webhooks tab is the endpoints panel;
	// every other tab is a plain field list.
	let panelBody = null;
	let visibleFields = [];
	if ( activeSection ) {
		if ( 'webhooks' === activeSection.id ) {
			panelBody = webhooksContent;
		} else if ( 'payments' === activeSection.id ) {
			const groups = partitionByGateway( activeSection.fields );
			const requestedSubtab = params && params.subtab;
			const firstGroupId = groups[ 0 ] ? groups[ 0 ].id : null;
			const activeSubtabId = groups.some(
				( group ) => group.id === requestedSubtab
			)
				? requestedSubtab
				: firstGroupId;
			const activeGroup =
				groups.find( ( group ) => group.id === activeSubtabId ) ||
				groups[ 0 ] ||
				null;
			visibleFields = activeGroup ? activeGroup.fields : [];

			panelBody = (
				<>
					{ groups.length > 1 && (
						<nav
							className="tiermint-settings__subtabs"
							aria-label={ __( 'Payment gateways', 'tiermint' ) }
						>
							<ul className="tiermint-settings__subtab-list">
								{ groups.map( ( group ) => {
									const isActive =
										group.id === activeSubtabId;
									const target = `#/settings/payments/${ group.id }`;
									return (
										<li key={ group.id }>
											<a
												href={ target }
												className={
													isActive
														? 'tiermint-settings__subtab is-active'
														: 'tiermint-settings__subtab'
												}
												aria-current={
													isActive
														? 'page'
														: undefined
												}
												onClick={ ( event ) => {
													event.preventDefault();
													replaceHashAnnounced(
														target
													);
												} }
											>
												{ gatewayLabel( group ) }
											</a>
										</li>
									);
								} ) }
							</ul>
						</nav>
					) }
					{ renderFields( visibleFields ) }
				</>
			);
		} else {
			visibleFields = activeSection.fields;
			panelBody = renderFields( visibleFields );
		}
	}

	// Save submits all staged core fields from any editable tab.
	const showSave = visibleFields.some( ( field ) => ! field.external );

	return (
		<section className="tiermint-admin-screen tiermint-settings">
			<h1>{ __( 'Settings', 'tiermint' ) }</h1>

			{ firstReadPending( settingsQuery ) && (
				<SkeletonForm fields={ 5 } />
			) }
			{ settingsQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ settingsQuery.error.message }
				</Notice>
			) }

			{ saved && (
				<Notice status="success" onRemove={ () => setSaved( false ) }>
					{ __( 'Settings saved.', 'tiermint' ) }
				</Notice>
			) }
			{ saveErrors && (
				<Notice status="error" onRemove={ () => setSaveErrors( null ) }>
					<ul>
						{ saveErrors.map( ( message ) => (
							<li key={ message }>{ message }</li>
						) ) }
					</ul>
				</Notice>
			) }

			{ data && tabSections.length > 0 && (
				<>
					<nav
						className="tiermint-settings__tabs"
						aria-label={ __( 'Settings sections', 'tiermint' ) }
					>
						<ul className="tiermint-settings__tab-list">
							{ tabSections.map( ( section ) => {
								const isActive = section.id === activeTabId;
								const target = `#/settings/${ section.id }`;
								return (
									<li key={ section.id }>
										<a
											href={ target }
											className={
												isActive
													? 'tiermint-settings__tab is-active'
													: 'tiermint-settings__tab'
											}
											aria-current={
												isActive ? 'page' : undefined
											}
											onClick={ ( event ) => {
												event.preventDefault();
												replaceHashAnnounced( target );
											} }
										>
											{ section.title }
										</a>
									</li>
								);
							} ) }
						</ul>
					</nav>

					{ activeSection && (
						<div className="tiermint-settings__panel">
							<h2 className="tiermint-settings__panel-title">
								{ activeSection.title }
							</h2>
							{ panelBody }
							{ showSave && (
								<div className="tiermint-form-actions">
									<Button
										variant="primary"
										isBusy={ saveMutation.isPending }
										disabled={
											! hasPending ||
											saveMutation.isPending
										}
										onClick={ () => {
											submittedStaged.current = staged;
											saveMutation.mutate(
												pendingValues
											);
										} }
									>
										{ __( 'Save changes', 'tiermint' ) }
									</Button>
								</div>
							) }
						</div>
					) }
				</>
			) }

			{ modalOpen && (
				<AddEndpointModal
					catalog={
						webhooksQuery.data ? webhooksQuery.data.catalog : []
					}
					busy={ createMutation.isPending }
					errors={ modalErrors }
					secret={ createdSecret }
					onClose={ closeModal }
					onCreate={ ( payload ) => createMutation.mutate( payload ) }
				/>
			) }
		</section>
	);
}

/**
 * Route registry entry for the settings screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/settings',
	title: __( 'Settings', 'tiermint' ),
	capability: 'manage_tiermint_settings',
	component: Settings,
};

/**
 * Route entry for a settings tab. It preserves staged edits between tabs.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const tabRoute = {
	path: '#/settings/:tab',
	title: __( 'Settings', 'tiermint' ),
	capability: 'manage_tiermint_settings',
	component: Settings,
};

/**
 * Route entry for a settings sub-tab (`#/settings/:tab/:subtab`), which the
 * Payments gateway sub-tabs deep-link into.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const subtabRoute = {
	path: '#/settings/:tab/:subtab',
	title: __( 'Settings', 'tiermint' ),
	capability: 'manage_tiermint_settings',
	component: Settings,
};

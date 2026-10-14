/**
 * Discount codes screen.
 *
 * Lists, creates, edits and deletes discount codes.
 */

/**
 * External dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState, useRef } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	RadioControl,
	SearchControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { del, getList, post, put } from '../api/client';
import BulkActionsBar, {
	BulkResultNotice,
	SelectAllHeader,
	SelectRowCell,
	useRowSelection,
} from '../components/BulkActionsBar';
import CategoryTokenField from '../components/CategoryTokenField';
import EmptyState from '../components/EmptyState';
import ProductTokenField from '../components/ProductTokenField';
import { clampPage, useHashQuery } from '../app/useHashQuery';
import Pagination from '../components/Pagination';
import { listState } from '../utils/listState';
import ScreenHeader from '../components/ScreenHeader';
import { SkeletonTable } from '../components/Skeleton';
import SortableHeader, {
	useSortableColumns,
} from '../components/SortableHeader';
import StatusBadge from '../components/StatusBadge';
import { useUnsavedChanges } from '../app/dirtyState';
import { decimalToMinor, formatMinor, minorToDecimal } from '../utils/currency';

/**
 * Rows requested per page.
 *
 * @type {number}
 */
const PER_PAGE = 20;

/**
 * Discount list query key.
 *
 * @type {string}
 */
const LIST_QUERY_KEY = 'discounts';

/**
 * Maps REST errors to form fields.
 *
 * @type {Object<string, string>}
 */
const API_FIELD_TO_FORM_FIELD = {
	code: 'code',
	name: 'name',
	type: 'type',
	amount_percent: 'amount',
	amount_fixed: 'amount',
	auto: 'auto',
	scope_type: 'scopeType',
	product_ids: 'productIds',
	category_ids: 'categoryIds',
	min_total: 'minTotal',
	max_uses: 'maxUses',
	once_per_customer: 'oncePerCustomer',
	starts_at: 'startsAt',
	expires_at: 'expiresAt',
	status: 'status',
};

/**
 * Formats a list amount.
 *
 * @param {Object} discount The discount record.
 * @param {string} currency The three-letter ISO currency code.
 * @return {string} The rendered amount.
 */
function formatAmount( discount, currency ) {
	if ( discount.type === 'fixed' ) {
		return formatMinor( discount.amount_fixed, currency );
	}
	return `${ discount.amount_percent }%`;
}

/**
 * Formats usage without an unlimited `/0` suffix.
 *
 * @param {Object} discount The discount record.
 * @return {string} The rendered usage summary.
 */
function formatUses( discount ) {
	if ( ! discount.max_uses ) {
		return String( discount.use_count || 0 );
	}
	return `${ discount.use_count || 0 }/${ discount.max_uses }`;
}

/**
 * Formats the UTC availability window.
 *
 * @param {Object} discount The discount record.
 * @return {string} The rendered window summary.
 */
function formatWindow( discount ) {
	const starts = discount.starts_at;
	const expires = discount.expires_at;

	if ( ! starts && ! expires ) {
		return __( 'Always available', 'tiermint' );
	}
	if ( starts && ! expires ) {
		/* translators: %s: the UTC date and time the discount becomes available. */
		return sprintf( __( 'From %s UTC', 'tiermint' ), starts );
	}
	if ( ! starts && expires ) {
		/* translators: %s: the UTC date and time the discount stops being available. */
		return sprintf( __( 'Until %s UTC', 'tiermint' ), expires );
	}
	/* translators: 1: the UTC start date and time. 2: the UTC end date and time. */
	return sprintf( __( '%1$s to %2$s UTC', 'tiermint' ), starts, expires );
}

/**
 * Fixed-amount label.
 *
 * Keeps the translator note above `sprintf` for the i18n linter.
 *
 * @param {string} currency The three-letter ISO currency code.
 * @return {string} The radio option label.
 */
function fixedAmountRadioLabel( currency ) {
	/* translators: %s: the store's currency code, for example USD. */
	return sprintf( __( 'Fixed amount (%s)', 'tiermint' ), currency );
}

/**
 * Fixed-amount field label.
 *
 * @param {string} currency The three-letter ISO currency code.
 * @return {string} The field label.
 */
function amountFixedFieldLabel( currency ) {
	/* translators: %s: the store's currency code, for example USD. */
	return sprintf( __( 'Amount (%s)', 'tiermint' ), currency );
}

/**
 * Minimum order total label.
 *
 * @param {string} currency The three-letter ISO currency code.
 * @return {string} The field label.
 */
function minTotalFieldLabel( currency ) {
	/* translators: %s: the store's currency code, for example USD. */
	return sprintf( __( 'Minimum order total (%s)', 'tiermint' ), currency );
}

/**
 * Read-only usage text.
 *
 * @param {number} useCount The discount's current use count.
 * @return {string} The rendered text.
 */
function timesUsedText( useCount ) {
	/* translators: %d: the number of times the discount has been used. */
	return sprintf( __( 'Times used: %d', 'tiermint' ), useCount || 0 );
}

/**
 * Accessible Edit button name.
 *
 * @param {string} code The discount code.
 * @return {string} The accessible name.
 */
function editButtonLabel( code ) {
	/* translators: %s: the discount code. */
	return sprintf( __( 'Edit %s', 'tiermint' ), code );
}

/**
 * Accessible Delete button name.
 *
 * @param {string} code The discount code.
 * @return {string} The accessible name.
 */
function deleteButtonLabel( code ) {
	/* translators: %s: the discount code. */
	return sprintf( __( 'Delete %s', 'tiermint' ), code );
}

/**
 * Status error text that names the discount.
 *
 * @param {string} code    The discount code.
 * @param {string} message The error message from the API.
 * @return {string} The notice text.
 */
function statusErrorText( code, message ) {
	return sprintf(
		/* translators: 1: the discount code. 2: the error message. */
		__( 'Could not update %1$s. %2$s', 'tiermint' ),
		code,
		message
	);
}

/**
 * Accessible Deactivate button name.
 *
 * @param {string} code The discount code.
 * @return {string} The accessible name.
 */
function deactivateButtonLabel( code ) {
	/* translators: %s: the discount code. */
	return sprintf( __( 'Deactivate %s', 'tiermint' ), code );
}

/**
 * Accessible Activate button name.
 *
 * @param {string} code The discount code.
 * @return {string} The accessible name.
 */
function activateButtonLabel( code ) {
	/* translators: %s: the discount code. */
	return sprintf( __( 'Activate %s', 'tiermint' ), code );
}

/**
 * Bulk-delete confirmation text.
 *
 * @param {number} count How many discounts are selected.
 * @return {string} The question.
 */
function deleteConfirmText( count ) {
	return sprintf(
		/* translators: %d: how many discounts are about to be deleted. */
		_n(
			'Delete %d discount permanently? This cannot be undone.',
			'Delete %d discounts permanently? This cannot be undone.',
			count,
			'tiermint'
		),
		count
	);
}

/**
 * Single-delete confirmation text.
 *
 * @param {string} [code] The discount code, when known.
 * @return {string} The confirmation question.
 */
function singleDeleteQuestion( code ) {
	if ( ! code ) {
		return __(
			'Delete this discount permanently? This cannot be undone.',
			'tiermint'
		);
	}
	return sprintf(
		/* translators: %s: the discount code. */
		__( 'Delete %s permanently? This cannot be undone.', 'tiermint' ),
		code
	);
}

/**
 * Bulk actions allowed by the batch route.
 *
 * @return {Array<Object>} The entries for the bulk-actions bar.
 */
function bulkActions() {
	return [
		{ value: 'activate', label: __( 'Activate', 'tiermint' ) },
		{ value: 'deactivate', label: __( 'Deactivate', 'tiermint' ) },
		{
			value: 'delete',
			label: __( 'Delete', 'tiermint' ),
			isDestructive: true,
			confirmText: deleteConfirmText,
		},
	];
}

/**
 * Batch result summary.
 *
 * @param {string} action The action that ran.
 * @param {number} count  How many discounts it got through.
 * @return {string} The sentence, empty when none of them landed.
 */
function bulkSummary( action, count ) {
	if ( count === 0 ) {
		return '';
	}

	if ( action === 'activate' ) {
		return sprintf(
			/* translators: %d: how many discounts were activated. */
			_n(
				'%d discount activated.',
				'%d discounts activated.',
				count,
				'tiermint'
			),
			count
		);
	}

	if ( action === 'deactivate' ) {
		return sprintf(
			/* translators: %d: how many discounts were deactivated. */
			_n(
				'%d discount deactivated.',
				'%d discounts deactivated.',
				count,
				'tiermint'
			),
			count
		);
	}

	return sprintf(
		/* translators: %d: how many discounts were deleted. */
		_n(
			'%d discount deleted.',
			'%d discounts deleted.',
			count,
			'tiermint'
		),
		count
	);
}

/**
 * Converts a `datetime-local` value to the API UTC string.
 *
 * Do not use `Date`, the input is already UTC.
 *
 * @param {string} value The `datetime-local` input value, or an empty string.
 * @return {?string} The UTC API string, or null when the input was empty.
 */
function toApiUtcString( value ) {
	if ( ! value ) {
		return null;
	}
	const [ datePart, timePart ] = value.split( 'T' );
	if ( ! datePart || ! timePart ) {
		return null;
	}
	const withSeconds = timePart.length === 5 ? `${ timePart }:00` : timePart;
	return `${ datePart } ${ withSeconds }`;
}

/**
 * Converts an API UTC string to a `datetime-local` value.
 *
 * @param {?string} value The UTC API string, or null/empty.
 * @return {string} The `datetime-local` input value, or an empty string.
 */
function fromApiUtcString( value ) {
	if ( ! value ) {
		return '';
	}
	const [ datePart, timePart ] = value.split( ' ' );
	if ( ! datePart || ! timePart ) {
		return '';
	}
	const [ hours, minutes ] = timePart.split( ':' );
	if ( hours === undefined || minutes === undefined ) {
		return '';
	}
	return `${ datePart }T${ hours }:${ minutes }`;
}

/**
 * Creates empty form state.
 *
 * @return {Object} The initial form state.
 */
function emptyForm() {
	return {
		code: '',
		name: '',
		type: 'percent',
		amountPercent: '',
		amountFixed: '',
		auto: false,
		scopeType: 'all',
		productIds: [],
		categoryIds: [],
		minTotal: '',
		maxUses: '0',
		oncePerCustomer: false,
		startsAt: '',
		expiresAt: '',
		status: 'active',
	};
}

/**
 * Creates form state from a discount.
 *
 * @param {Object} discount The discount record to edit.
 * @param {string} currency The store currency, so minor-unit amounts render
 *                          with the right number of decimal places.
 * @return {Object} The form state seeded from the discount.
 */
function formFromDiscount( discount, currency ) {
	return {
		code: discount.code || '',
		name: discount.name || '',
		type: discount.type === 'fixed' ? 'fixed' : 'percent',
		amountPercent:
			discount.type === 'percent' &&
			discount.amount_percent !== undefined &&
			discount.amount_percent !== null
				? String( discount.amount_percent )
				: '',
		amountFixed:
			discount.type === 'fixed' &&
			discount.amount_fixed !== undefined &&
			discount.amount_fixed !== null
				? minorToDecimal( discount.amount_fixed, currency )
				: '',
		auto: Boolean( discount.auto ),
		scopeType:
			discount.scope_type === 'products' ||
			discount.scope_type === 'categories'
				? discount.scope_type
				: 'all',
		productIds: Array.isArray( discount.product_ids )
			? discount.product_ids
			: [],
		categoryIds: Array.isArray( discount.category_ids )
			? discount.category_ids
			: [],
		minTotal:
			discount.min_total !== undefined && discount.min_total !== null
				? minorToDecimal( discount.min_total, currency )
				: '',
		maxUses:
			discount.max_uses !== undefined && discount.max_uses !== null
				? String( discount.max_uses )
				: '0',
		oncePerCustomer: Boolean( discount.once_per_customer ),
		startsAt: fromApiUtcString( discount.starts_at ),
		expiresAt: fromApiUtcString( discount.expires_at ),
		status: discount.status === 'inactive' ? 'inactive' : 'active',
	};
}

/**
 * Builds the REST payload from form state.
 *
 * @param {Object} form     The current form state.
 * @param {string} currency The store currency, so decimal amounts convert to
 *                          the correct minor-unit integers (zero-decimal aware).
 * @return {Object} The payload for `POST /discounts` or `PUT /discounts/{id}`.
 */
function buildPayload( form, currency ) {
	const scopesProducts = ! form.auto || form.scopeType === 'products';
	const scopesCategories = form.auto && form.scopeType === 'categories';

	const payload = {
		code: form.code.trim(),
		name: form.name.trim(),
		type: form.type,
		auto: Boolean( form.auto ),
		product_ids: scopesProducts ? form.productIds : [],
		category_ids: scopesCategories ? form.categoryIds : [],
		min_total: decimalToMinor( form.minTotal, currency ),
		max_uses: form.maxUses === '' ? 0 : parseInt( form.maxUses, 10 ) || 0,
		once_per_customer: Boolean( form.oncePerCustomer ),
		starts_at: toApiUtcString( form.startsAt ),
		expires_at: toApiUtcString( form.expiresAt ),
		status: form.status,
	};

	// Code discounts derive scope from product IDs.
	if ( form.auto ) {
		payload.scope_type = form.scopeType;
	}

	if ( form.type === 'percent' ) {
		payload.amount_percent = parseFloat( form.amountPercent );
	} else {
		payload.amount_fixed = decimalToMinor( form.amountFixed, currency );
	}

	return payload;
}

/**
 * Validates form state before the API request.
 *
 * @param {Object} form The current form state.
 * @return {Object<string, string>} A map of form field to error message,
 *                                  empty when the form is valid.
 */
function validateForm( form ) {
	const errors = {};

	// The service creates a code for automatic discounts when needed.
	if ( ! form.auto && ! form.code.trim() ) {
		errors.code = __( 'A code is required.', 'tiermint' );
	}
	if ( ! form.name.trim() ) {
		errors.name = __( 'A name is required.', 'tiermint' );
	}

	if ( form.type === 'percent' ) {
		if (
			form.amountPercent === '' ||
			Number.isNaN( parseFloat( form.amountPercent ) )
		) {
			errors.amount = __( 'Enter a percent amount.', 'tiermint' );
		}
	} else if (
		form.amountFixed === '' ||
		Number.isNaN( parseFloat( form.amountFixed ) )
	) {
		errors.amount = __( 'Enter an amount.', 'tiermint' );
	}

	if (
		form.auto &&
		form.scopeType === 'products' &&
		form.productIds.length === 0
	) {
		errors.productIds = __( 'Choose at least one product.', 'tiermint' );
	}
	if (
		form.auto &&
		form.scopeType === 'categories' &&
		form.categoryIds.length === 0
	) {
		errors.categoryIds = __( 'Choose at least one category.', 'tiermint' );
	}

	return errors;
}

/**
 * Maps API errors to form fields. Duplicate codes always use the code field.
 *
 * @param {{code: string, message: string, errors: (Object|null)}} error The
 *                                                                       normalized API error.
 * @return {Object<string, string>} A map of form field to error message.
 */
function mapServerErrors( error ) {
	const fieldErrors = {};
	const serverErrors = error.errors || {};

	Object.keys( serverErrors ).forEach( ( apiField ) => {
		const formField = API_FIELD_TO_FORM_FIELD[ apiField ] || apiField;
		const messages = serverErrors[ apiField ];
		fieldErrors[ formField ] = Array.isArray( messages )
			? messages.join( ' ' )
			: String( messages );
	} );

	if ( error.code === 'tiermint_duplicate_code' && ! fieldErrors.code ) {
		fieldErrors.code = error.message;
	}

	return fieldErrors;
}

/**
 * Creates or edits a discount. Remounting resets form state for a new target.
 *
 * @param {Object}   props          Component props.
 * @param {?Object}  props.discount The discount to edit, or null to create one.
 * @param {string}   props.currency Three-letter ISO currency code.
 * @param {Function} props.onClose  Dismisses without saving.
 * @param {Function} props.onSaved  Called after a successful save.
 * @return {Element} The modal element.
 */
function DiscountModal( { discount, currency, onClose, onSaved } ) {
	const queryClient = useQueryClient();
	const [ form, setForm ] = useState( () =>
		discount ? formFromDiscount( discount, currency ) : emptyForm()
	);

	/* Snapshot the opening form. Shared builders keep JSON key order stable. */
	// Seeded once, lazily: `useRef( expr )` evaluates `expr` on every render
	// and throws all but the first result away, so the eager form would be
	// rebuilt and re-stringified on every keystroke for a value taken at mount.
	const openedAs = useRef( null );
	if ( openedAs.current === null ) {
		openedAs.current = JSON.stringify(
			discount ? formFromDiscount( discount, currency ) : emptyForm()
		);
	}
	useUnsavedChanges( JSON.stringify( form ) !== openedAs.current );

	const [ fieldErrors, setFieldErrors ] = useState( {} );
	const [ generalError, setGeneralError ] = useState( '' );

	const saveMutation = useMutation( {
		mutationFn: ( payload ) =>
			discount
				? put( `/discounts/${ discount.id }`, payload )
				: post( '/discounts', payload ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ LIST_QUERY_KEY ] } );
			onSaved();
		},
		onError: ( error ) => {
			setFieldErrors( mapServerErrors( error ) );
			setGeneralError( error.message );
		},
	} );

	/**
	 * Update a single form field.
	 *
	 * @param {string} field The form field key.
	 * @param {*}      value The new value.
	 * @return {void}
	 */
	function setField( field, value ) {
		setForm( ( previous ) => ( { ...previous, [ field ]: value } ) );
	}

	/**
	 * Validate and, if valid, submit the form.
	 *
	 * @param {Event} event The form submit event.
	 * @return {void}
	 */
	function handleSubmit( event ) {
		event.preventDefault();

		const clientErrors = validateForm( form );
		if ( Object.keys( clientErrors ).length > 0 ) {
			setFieldErrors( clientErrors );
			setGeneralError( '' );
			return;
		}

		setFieldErrors( {} );
		setGeneralError( '' );
		saveMutation.mutate( buildPayload( form, currency ) );
	}

	const codeHelp =
		fieldErrors.code ||
		__(
			'Customers enter this at checkout. Letters and numbers only.',
			'tiermint'
		);

	// A code discount always offers the product picker (it replaces the old raw id
	// field). An automatic discount shows a picker only for the scope it is set to.
	const showProductPicker = ! form.auto || form.scopeType === 'products';
	const showCategoryPicker = form.auto && form.scopeType === 'categories';

	/**
	 * The wrapper modifier for a field the form or the server flagged, so its message
	 * and input render in the error style rather than as muted help text.
	 *
	 * @param {string} key The form-field key.
	 * @return {(string|undefined)} The modifier class, or undefined when the field is valid.
	 */
	const invalidClass = ( key ) =>
		fieldErrors[ key ] ? 'tiermint-field--invalid' : undefined;

	return (
		<Modal
			className="tiermint-modal"
			title={
				discount
					? __( 'Edit discount', 'tiermint' )
					: __( 'Add discount', 'tiermint' )
			}
			onRequestClose={ onClose }
		>
			{ /* This class supplies the modal field spacing. */ }
			<form className="tiermint-form-stack" onSubmit={ handleSubmit }>
				{ generalError && (
					<Notice status="error" isDismissible={ false }>
						{ generalError }
					</Notice>
				) }

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					className={ invalidClass( 'name' ) }
					label={ __( 'Name', 'tiermint' ) }
					value={ form.name }
					help={
						fieldErrors.name ||
						__(
							'A short label so you can find this discount.',
							'tiermint'
						)
					}
					onChange={ ( value ) => setField( 'name', value ) }
				/>

				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Automatic (no code)', 'tiermint' ) }
					help={ __(
						'Apply this discount automatically to every matching cart, with no code to type.',
						'tiermint'
					) }
					checked={ form.auto }
					onChange={ ( value ) => setField( 'auto', value ) }
				/>

				{ /* Automatic discounts keep their generated code, but do not show it. */ }
				{ ! form.auto && (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						className={ invalidClass( 'code' ) }
						label={ __( 'Code', 'tiermint' ) }
						value={ form.code }
						help={ codeHelp }
						onChange={ ( value ) => setField( 'code', value ) }
					/>
				) }

				<RadioControl
					__nextHasNoMarginBottom
					label={ __( 'Discount type', 'tiermint' ) }
					selected={ form.type }
					options={ [
						{
							label: __( 'Percent off', 'tiermint' ),
							value: 'percent',
						},
						{
							label: fixedAmountRadioLabel( currency ),
							value: 'fixed',
						},
					] }
					onChange={ ( value ) => setField( 'type', value ) }
				/>

				{ form.type === 'percent' ? (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min="0"
						step="0.01"
						className={ invalidClass( 'amount' ) }
						label={ __( 'Amount (%)', 'tiermint' ) }
						value={ form.amountPercent }
						help={ fieldErrors.amount }
						onChange={ ( value ) =>
							setField( 'amountPercent', value )
						}
					/>
				) : (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min="0"
						step="0.01"
						className={ invalidClass( 'amount' ) }
						label={ amountFixedFieldLabel( currency ) }
						value={ form.amountFixed }
						help={ fieldErrors.amount }
						onChange={ ( value ) =>
							setField( 'amountFixed', value )
						}
					/>
				) }

				{ form.auto && (
					<RadioControl
						__nextHasNoMarginBottom
						label={ __( 'Applies to', 'tiermint' ) }
						selected={ form.scopeType }
						options={ [
							{
								label: __( 'All products', 'tiermint' ),
								value: 'all',
							},
							{
								label: __( 'Chosen products', 'tiermint' ),
								value: 'products',
							},
							{
								label: __( 'Chosen categories', 'tiermint' ),
								value: 'categories',
							},
						] }
						onChange={ ( value ) => setField( 'scopeType', value ) }
					/>
				) }

				{ showProductPicker && (
					<ProductTokenField
						label={ __( 'Products', 'tiermint' ) }
						value={ form.productIds }
						help={
							fieldErrors.productIds ||
							( form.auto
								? __(
										'The products this automatic discount applies to.',
										'tiermint'
								  )
								: __(
										'The products this discount applies to. Leave empty to apply it to all products.',
										'tiermint'
								  ) )
						}
						onChange={ ( ids ) => setField( 'productIds', ids ) }
					/>
				) }

				{ showCategoryPicker && (
					<CategoryTokenField
						label={ __( 'Categories', 'tiermint' ) }
						value={ form.categoryIds }
						help={
							fieldErrors.categoryIds ||
							__(
								'Products in these categories get the discount.',
								'tiermint'
							)
						}
						onChange={ ( ids ) => setField( 'categoryIds', ids ) }
					/>
				) }

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min="0"
					step="0.01"
					className={ invalidClass( 'minTotal' ) }
					label={ minTotalFieldLabel( currency ) }
					value={ form.minTotal }
					help={ fieldErrors.minTotal }
					onChange={ ( value ) => setField( 'minTotal', value ) }
				/>

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min="0"
					step="1"
					className={ invalidClass( 'maxUses' ) }
					label={ __( 'Maximum uses', 'tiermint' ) }
					value={ form.maxUses }
					help={
						fieldErrors.maxUses ||
						__( 'Set to 0 for unlimited uses.', 'tiermint' )
					}
					onChange={ ( value ) => setField( 'maxUses', value ) }
				/>

				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Limit to one use per customer', 'tiermint' ) }
					checked={ form.oncePerCustomer }
					onChange={ ( value ) =>
						setField( 'oncePerCustomer', value )
					}
				/>

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="datetime-local"
					className={ invalidClass( 'startsAt' ) }
					label={ __( 'Starts at', 'tiermint' ) }
					value={ form.startsAt }
					help={
						fieldErrors.startsAt ||
						__(
							'Times are in UTC, not your local time zone. Leave blank to start immediately.',
							'tiermint'
						)
					}
					onChange={ ( value ) => setField( 'startsAt', value ) }
				/>

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="datetime-local"
					className={ invalidClass( 'expiresAt' ) }
					label={ __( 'Expires at', 'tiermint' ) }
					value={ form.expiresAt }
					help={
						fieldErrors.expiresAt ||
						__(
							'Times are in UTC, not your local time zone. Leave blank for no expiry.',
							'tiermint'
						)
					}
					onChange={ ( value ) => setField( 'expiresAt', value ) }
				/>

				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Active', 'tiermint' ) }
					checked={ form.status === 'active' }
					onChange={ ( checked ) =>
						setField( 'status', checked ? 'active' : 'inactive' )
					}
				/>

				{ discount && <p>{ timesUsedText( discount.use_count ) }</p> }

				<div className="tiermint-modal-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'tiermint' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ saveMutation.isPending }
					>
						{ __( 'Save discount', 'tiermint' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

/**
 * Renders the discounts screen.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object.
 * @return {Element} The discounts screen element.
 */
export default function Discounts( { boot } ) {
	const currency =
		( boot && boot.settings && boot.settings.currency ) || 'USD';

	const { page, params, setPage, setFilter, setFilters, replacePage } =
		useHashQuery( {
			status: '',
			search: '',
			orderby: 'created_at',
			order: 'desc',
		} );
	const { status, search, orderby, order } = params;
	const sort = useSortableColumns( { orderby, order, setFilters } );
	const [ modalTarget, setModalTarget ] = useState( null );
	const [ confirmingDeleteId, setConfirmingDeleteId ] = useState( null );
	// A failed delete's message, shown inside the confirm dialog so it explains
	// itself rather than looking like a click that did nothing.
	const [ deleteError, setDeleteError ] = useState( '' );
	const [ flippingIds, setFlippingIds ] = useState( [] );
	// One `{ id, message }` per row that failed, in the order they failed, so a
	// failure stays attached to the row it belongs to instead of being wiped by
	// whatever the merchant does next.
	const [ statusErrors, setStatusErrors ] = useState( [] );
	const [ bulkResult, setBulkResult ] = useState( null );
	const [ bulkError, setBulkError ] = useState( '' );

	const queryClient = useQueryClient();

	const listQuery = useQuery( {
		queryKey: [ LIST_QUERY_KEY, { page, status, search, orderby, order } ],
		queryFn: () =>
			getList( '/discounts', {
				page,
				per_page: PER_PAGE,
				status: status || undefined,
				search: search || undefined,
				orderby,
				order,
			} ),
	} );

	const { items, total, totalPages, waiting, inHand } =
		listState( listQuery );

	// The rows a selection and a result are about: change any of it and they are
	// other rows, whatever their ids.
	const rowsKey = JSON.stringify( [ page, status, search, orderby, order ] );

	const selection = useRowSelection( {
		ids: items.map( ( row ) => row.id ),
		resetKey: rowsKey,
	} );

	// A discount is read on this screen and nowhere else, so the list key is the
	// whole of what a batch leaves stale.
	const bulkMutation = useMutation( {
		mutationFn: ( { action, ids } ) =>
			post( '/discounts/batch', { ids, action } ),
		// The key the batch was fired against. A read of `rowsKey` in the
		// callbacks below would be the one in force when the answer lands, and
		// the merchant is free to change the view while a batch is out.
		onMutate: () => {
			setBulkResult( null );
			setBulkError( '' );

			return { rowsKey };
		},
		onSuccess: ( result, { action }, context ) => {
			const failed = Array.isArray( result.failed ) ? result.failed : [];
			const succeeded = Array.isArray( result.succeeded )
				? result.succeeded
				: [];

			queryClient.invalidateQueries( { queryKey: [ LIST_QUERY_KEY ] } );

			// Skip stale batch notices after the list view changes.
			if ( context?.rowsKey !== rowsKey ) {
				return;
			}

			setBulkResult( {
				action,
				failed,
				succeeded,
			} );
			selection.drop( succeeded );

			// A row that just went active or inactive in bulk should not still
			// carry the "could not update" banner from an earlier single click on
			// it. The failures keep theirs, since those rows did not move.
			const changed = new Set( succeeded );
			setStatusErrors( ( previous ) =>
				previous.filter( ( entry ) => ! changed.has( entry.id ) )
			);
		},
		onError: ( error, variables, context ) => {
			if ( context?.rowsKey !== rowsKey ) {
				return;
			}

			setBulkError( error.message );
		},
	} );

	const deleteMutation = useMutation( {
		mutationFn: ( id ) => del( `/discounts/${ id }` ),
		onMutate: () => {
			setDeleteError( '' );
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ LIST_QUERY_KEY ] } );
			setConfirmingDeleteId( null );
		},
		// A rejected delete keeps the dialog open with its reason, so the merchant
		// can read it and retry or cancel rather than facing a silent no-op.
		onError: ( error ) => {
			setDeleteError( error.message );
		},
	} );

	// Track each row locally because one mutation object serves every toggle.
	const statusMutation = useMutation( {
		mutationFn: ( { id, status: next } ) =>
			put( `/discounts/${ id }`, { status: next } ),
		onMutate: ( { id } ) => {
			setFlippingIds( ( previous ) => previous.concat( id ) );
		},
		// Return this promise so the row stays guarded through its refresh.
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: [ LIST_QUERY_KEY ] } ),
		onError: ( error, { id, code } ) => {
			// Nothing to say if the merchant paged or filtered away while the
			// write was out: the banner would name a row that is not there.
			if ( ! items.some( ( item ) => item.id === id ) ) {
				return;
			}

			setStatusErrors( ( previous ) =>
				previous
					.filter( ( entry ) => entry.id !== id )
					.concat( {
						id,
						message: statusErrorText( code, error.message ),
					} )
			);
		},
		onSettled: ( data, error, { id } ) => {
			setFlippingIds( ( previous ) =>
				previous.filter( ( entry ) => entry !== id )
			);
		},
	} );

	/**
	 * Whether a discount is currently usable at checkout.
	 *
	 * @param {Object} discount The discount record.
	 * @return {boolean} True when its status is active.
	 */
	function isActive( discount ) {
		return discount.status === 'active';
	}

	/**
	 * Whether a status change for a discount is still in flight, so its own
	 * button stays refused until the refreshed list arrives.
	 *
	 * @param {number} id The discount id.
	 * @return {boolean} True while that discount's write is unanswered.
	 */
	function isFlipping( id ) {
		return flippingIds.includes( id );
	}

	/**
	 * Flip a discount between active and inactive.
	 *
	 * @param {Object} discount The discount to change.
	 * @return {void}
	 */
	function flipStatus( discount ) {
		dismissStatusError( discount.id );
		statusMutation.mutate( {
			id: discount.id,
			code: discount.code,
			status: isActive( discount ) ? 'inactive' : 'active',
		} );
	}

	/**
	 * Drop the reported failure for one discount.
	 *
	 * @param {number} id The discount id.
	 * @return {void}
	 */
	function dismissStatusError( id ) {
		setStatusErrors( ( previous ) =>
			previous.filter( ( entry ) => entry.id !== id )
		);
	}

	useEffect( () => {
		clampPage( { page, totalPages, ready: inHand, onClamp: replacePage } );
	}, [ page, totalPages, inHand, replacePage ] );

	// The result and the error belong to the rows they ran on, so they go when
	// those rows do. Hiding them instead would only bring them back the moment
	// the merchant returned to the same view.
	useEffect( () => {
		setBulkResult( null );
		setBulkError( '' );
	}, [ rowsKey ] );

	// A status failure names a row, so it goes when the rows do. Left alone it
	// would still be on screen two pages away from the discount it is about.
	useEffect( () => {
		setStatusErrors( ( previous ) =>
			previous.length === 0 ? previous : []
		);
	}, [ page, status, search, orderby, order ] );

	const addDiscountButton = (
		<Button variant="primary" onClick={ () => setModalTarget( 'create' ) }>
			{ __( 'Add discount', 'tiermint' ) }
		</Button>
	);

	/**
	 * Apply the typed search term and jump back to the first page.
	 *
	 * @param {string} value The search text.
	 * @return {void}
	 */
	function handleSearchChange( value ) {
		setFilter( 'search', value );
	}

	/**
	 * Apply a new status filter and jump back to the first page.
	 *
	 * @param {string} value The selected status filter value.
	 * @return {void}
	 */
	function handleStatusChange( value ) {
		setFilter( 'status', value );
	}

	return (
		<section className="tiermint-admin-screen">
			<ScreenHeader
				title={ __( 'Discounts', 'tiermint' ) }
				actions={ addDiscountButton }
			/>

			<div className="tiermint-toolbar tiermint-discounts-toolbar">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search discounts', 'tiermint' ) }
					placeholder={ __( 'Search by code or name', 'tiermint' ) }
					value={ search }
					onChange={ handleSearchChange }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					hideLabelFromVision
					label={ __( 'Status', 'tiermint' ) }
					value={ status }
					options={ [
						{ label: __( 'All statuses', 'tiermint' ), value: '' },
						{ label: __( 'Active', 'tiermint' ), value: 'active' },
						{
							label: __( 'Inactive', 'tiermint' ),
							value: 'inactive',
						},
					] }
					onChange={ handleStatusChange }
				/>
			</div>

			{ waiting && <SkeletonTable columns={ 8 } /> }

			{ listQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ listQuery.error.message }
				</Notice>
			) }

			{ /* A batch the server refused as a whole, which is a different thing
			     from the per-id failures the notice below reports. */ }
			{ bulkError && (
				<Notice status="error" onRemove={ () => setBulkError( '' ) }>
					{ bulkError }
				</Notice>
			) }

			{ bulkResult && (
				<BulkResultNotice
					summary={ bulkSummary(
						bulkResult.action,
						bulkResult.succeeded.length
					) }
					failed={ bulkResult.failed }
					labelFor={ ( id ) =>
						items.find( ( row ) => row.id === id )?.code
					}
					onDismiss={ () => setBulkResult( null ) }
				/>
			) }

			{ /* Dismissible, unlike the read errors above it: this one reports a
			     single action that is over, and the merchant may well fix the
			     discount another way and want the banner gone. */ }
			{ statusErrors.map( ( { id, message } ) => (
				<Notice
					key={ id }
					status="error"
					onRemove={ () => dismissStatusError( id ) }
				>
					{ message }
				</Notice>
			) ) }

			{ inHand && items.length === 0 && (
				<EmptyState
					message={
						search || status
							? __(
									'No discounts match these filters.',
									'tiermint'
							  )
							: __(
									'No discounts yet. Discount codes you create appear here.',
									'tiermint'
							  )
					}
					action={ search || status ? undefined : addDiscountButton }
				/>
			) }

			{ items.length > 0 && (
				<BulkActionsBar
					label={ __( 'Bulk actions for discounts', 'tiermint' ) }
					actions={ bulkActions() }
					count={ selection.count }
					pending={ bulkMutation.isPending }
					onApply={ ( action ) =>
						bulkMutation.mutate( {
							action,
							ids: selection.selected,
						} )
					}
				/>
			) }

			{ items.length > 0 && (
				<table
					className="wp-list-table widefat striped"
					aria-label={ __( 'Discounts', 'tiermint' ) }
				>
					<thead>
						<tr>
							<SelectAllHeader selection={ selection } />
							<th scope="col">{ __( 'Name', 'tiermint' ) }</th>
							<SortableHeader
								column="code"
								label={ __( 'Code', 'tiermint' ) }
								sort={ sort }
							/>
							<th scope="col">{ __( 'Amount', 'tiermint' ) }</th>
							<th scope="col">{ __( 'Uses', 'tiermint' ) }</th>
							<th scope="col">{ __( 'Window', 'tiermint' ) }</th>
							<th scope="col">{ __( 'Status', 'tiermint' ) }</th>
							<th scope="col">{ __( 'Actions', 'tiermint' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( discount ) => (
							<tr key={ discount.id }>
								<SelectRowCell
									selection={ selection }
									id={ discount.id }
									label={ discount.code }
								/>
								<td>{ discount.name }</td>
								<td>
									<code>{ discount.code }</code>
								</td>
								<td>{ formatAmount( discount, currency ) }</td>
								<td>{ formatUses( discount ) }</td>
								<td>{ formatWindow( discount ) }</td>
								<td>
									<StatusBadge
										status={
											discount.status === 'active'
												? 'active'
												: 'inactive'
										}
										label={
											discount.status === 'active'
												? __( 'Active', 'tiermint' )
												: __( 'Inactive', 'tiermint' )
										}
									/>
								</td>
								<td>
									<Button
										variant="secondary"
										aria-label={ editButtonLabel(
											discount.code
										) }
										onClick={ () =>
											setModalTarget( discount )
										}
									>
										{ __( 'Edit', 'tiermint' ) }
									</Button>
									<Button
										variant="tertiary"
										aria-label={
											isActive( discount )
												? deactivateButtonLabel(
														discount.code
												  )
												: activateButtonLabel(
														discount.code
												  )
										}
										isBusy={ isFlipping( discount.id ) }
										disabled={ isFlipping( discount.id ) }
										__experimentalIsFocusable
										onClick={ () => flipStatus( discount ) }
									>
										{ isActive( discount )
											? __( 'Deactivate', 'tiermint' )
											: __( 'Activate', 'tiermint' ) }
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										aria-label={ deleteButtonLabel(
											discount.code
										) }
										isBusy={
											deleteMutation.isPending &&
											confirmingDeleteId === discount.id
										}
										onClick={ () => {
											setDeleteError( '' );
											setConfirmingDeleteId(
												discount.id
											);
										} }
									>
										{ __( 'Delete', 'tiermint' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ /* Keep the pager mounted during a pending page read. */ }
			<Pagination
				page={ page }
				total={ total }
				totalPages={ totalPages }
				pending={ waiting }
				onChange={ setPage }
			/>

			{ confirmingDeleteId !== null && (
				<Modal
					className="tiermint-modal tiermint-modal--confirm"
					title={ __( 'Delete discount', 'tiermint' ) }
					onRequestClose={ () => {
						setDeleteError( '' );
						setConfirmingDeleteId( null );
					} }
				>
					{ deleteError && (
						<Notice status="error" isDismissible={ false }>
							{ deleteError }
						</Notice>
					) }
					<p>
						{ singleDeleteQuestion(
							items.find(
								( row ) => row.id === confirmingDeleteId
							)?.code
						) }
					</p>
					<div className="tiermint-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => {
								setDeleteError( '' );
								setConfirmingDeleteId( null );
							} }
						>
							{ __( 'Cancel', 'tiermint' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ deleteMutation.isPending }
							disabled={ deleteMutation.isPending }
							__experimentalIsFocusable
							onClick={ () =>
								deleteMutation.mutate( confirmingDeleteId )
							}
						>
							{ __( 'Confirm delete', 'tiermint' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ modalTarget && (
				<DiscountModal
					key={ modalTarget === 'create' ? 'create' : modalTarget.id }
					discount={ modalTarget === 'create' ? null : modalTarget }
					currency={ currency }
					onClose={ () => setModalTarget( null ) }
					onSaved={ () => setModalTarget( null ) }
				/>
			) }
		</section>
	);
}

/**
 * Route registry entry for the discounts screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/discounts',
	title: __( 'Discounts', 'tiermint' ),
	capability: 'manage_tiermint',
	component: Discounts,
};

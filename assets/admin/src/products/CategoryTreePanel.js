/**
 * Product category tree with inline creation.
 *
 * Keep a pending category out of the product save body.
 */

/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
	CheckboxControl,
	SelectControl,
	TextControl,
	Button,
	Notice,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { get, post } from '../api/client';
import { SkeletonPanel } from '../components/Skeleton';
import { errorDetail, nameKey } from './termHelpers';
import { flattenTermTree, parentOptions } from './termTree';
import { firstReadPending } from '../utils/listState';

/**
 * Temporary ID for a pending category.
 *
 * @type {number}
 */
const PENDING_ID = -1;

/**
 * Render the category tree and its inline creation box.
 *
 * @param {Object}               props             Component props.
 * @param {number[]}             props.selectedIds The product's assigned category ids.
 * @param {(id: number) => void} props.onAdd       Add a category id to the selection.
 * @param {(id: number) => void} props.onRemove    Remove a category id from the selection.
 * @return {Element} The panel element.
 */
export default function CategoryTreePanel( { selectedIds, onAdd, onRemove } ) {
	const queryClient = useQueryClient();

	const termsQuery = useQuery( {
		queryKey: [ 'terms', 'category' ],
		queryFn: () => get( '/terms', { taxonomy: 'category' } ),
	} );

	const inHand = Array.isArray( termsQuery.data );
	const terms = inHand ? termsQuery.data : [];

	// Keep the pending category outside the product form.
	const [ newName, setNewName ] = useState( '' );
	const [ newParent, setNewParent ] = useState( '0' );
	const [ error, setError ] = useState( null );
	const [ pending, setPending ] = useState( null );

	// Add the pending row before flattening so it keeps its parent.
	const rows = flattenTermTree(
		pending
			? terms.concat( {
					id: PENDING_ID,
					name: pending.name,
					slug: '',
					parent: pending.parent,
			  } )
			: terms
	);

	const createMutation = useMutation( {
		mutationFn: ( body ) => post( '/terms', body ),
		onMutate: ( body ) => {
			setError( null );
			setPending( { name: body.name, parent: body.parent } );
		},
		onSuccess: ( saved ) => {
			// Seed the cache before clearing the pending row.
			queryClient.setQueryData( [ 'terms', 'category' ], ( old ) =>
				Array.isArray( old ) ? [ ...old, saved ] : [ saved ]
			);
			onAdd( saved.id );
			setNewName( '' );
			setNewParent( '0' );
			setPending( null );
			queryClient.invalidateQueries( {
				queryKey: [ 'terms', 'category' ],
			} );
		},
		onError: ( err ) => {
			// The product form never receives the pending category.
			setPending( null );
			setError( errorDetail( err ) );
		},
	} );

	/**
	 * Create the typed category, or refuse a name that already exists.
	 *
	 * @return {void}
	 */
	const onCreate = () => {
		const name = newName.trim();
		if ( '' === name ) {
			return;
		}
		const exists = terms.some(
			( term ) => nameKey( term.name ) === nameKey( name )
		);
		if ( exists ) {
			setError(
				sprintf(
					/* translators: %s: the category name a merchant tried to create. */
					__( 'A category named "%s" already exists.', 'tiermint' ),
					name
				)
			);
			return;
		}
		createMutation.mutate( {
			taxonomy: 'category',
			name,
			parent: Number( newParent ) || 0,
		} );
	};

	let body;
	if ( ! inHand && firstReadPending( termsQuery ) ) {
		body = <SkeletonPanel />;
	} else if ( ! inHand && termsQuery.isError ) {
		// Do not allow creation without a known category list.
		body = (
			<Notice status="error" isDismissible={ false }>
				{ termsQuery.error.message }
			</Notice>
		);
	} else {
		body = (
			<>
				<div className="tiermint-term-tree">
					{ rows.map( ( { term, depth } ) => (
						<div
							key={ term.id }
							className="tiermint-term-tree__row"
							style={ { '--tiermint-term-depth': depth } }
						>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ term.name }
								checked={
									PENDING_ID === term.id ||
									selectedIds.includes( term.id )
								}
								disabled={ PENDING_ID === term.id }
								onChange={ ( checked ) =>
									checked
										? onAdd( term.id )
										: onRemove( term.id )
								}
							/>
						</div>
					) ) }
				</div>
				<div className="tiermint-term-field__add">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Add a new category', 'tiermint' ) }
						value={ newName }
						onChange={ ( value ) => {
							setNewName( value );
							if ( error ) {
								setError( null );
							}
						} }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Parent category', 'tiermint' ) }
						value={ newParent }
						options={ parentOptions( terms, null ) }
						onChange={ ( value ) => setNewParent( value ) }
					/>
					<Button
						variant="secondary"
						isBusy={ createMutation.isPending }
						disabled={
							'' === newName.trim() || createMutation.isPending
						}
						__experimentalIsFocusable
						onClick={ onCreate }
					>
						{ __( 'Add category', 'tiermint' ) }
					</Button>
				</div>
				{ error && (
					<p className="tiermint-term-field__error" role="alert">
						{ error }
					</p>
				) }
			</>
		);
	}

	return (
		<div className="tiermint-panel tiermint-product-edit__box tiermint-term-field">
			<h2>{ __( 'Product categories', 'tiermint' ) }</h2>
			{ body }
		</div>
	);
}

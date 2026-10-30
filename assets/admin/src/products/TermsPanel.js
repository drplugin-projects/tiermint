/**
 * Select and create product tags in the sidebar.
 */

/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
	FormTokenField,
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
import { firstReadPending } from '../utils/listState';

/**
 * Render the tag token field and its inline creation box.
 *
 * @param {Object}               props             Component props.
 * @param {number[]}             props.selectedIds The product's selected tag ids.
 * @param {(id: number) => void} props.onAdd       Add a tag id to the selection.
 * @param {(id: number) => void} props.onRemove    Remove a tag id from the selection.
 * @return {Element} The panel element.
 */
export default function TermsPanel( { selectedIds, onAdd, onRemove } ) {
	const queryClient = useQueryClient();

	const termsQuery = useQuery( {
		queryKey: [ 'terms', 'tag' ],
		queryFn: () => get( '/terms', { taxonomy: 'tag' } ),
	} );

	const inHand = Array.isArray( termsQuery.data );
	const terms = inHand ? termsQuery.data : [];

	// Keep the provisional tag out of the product save body.
	const [ newName, setNewName ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ pending, setPending ] = useState( null );

	// Use the slug to distinguish tags with the same name.
	const nameCounts = {};
	terms.forEach( ( term ) => {
		const key = nameKey( term.name );
		nameCounts[ key ] = ( nameCounts[ key ] || 0 ) + 1;
	} );
	const displayFor = ( term ) =>
		nameCounts[ nameKey( term.name ) ] > 1
			? `${ term.name } (${ term.slug })`
			: term.name;

	const displayToId = new Map();
	terms.forEach( ( term ) => {
		displayToId.set( nameKey( displayFor( term ) ), term.id );
	} );

	const selectedTerms = selectedIds
		.map( ( id ) => terms.find( ( term ) => term.id === id ) )
		.filter( Boolean );

	const tokens = [
		...selectedTerms.map( displayFor ),
		...( pending ? [ pending.name ] : [] ),
	];
	const suggestions = terms
		.filter( ( term ) => ! selectedIds.includes( term.id ) )
		.map( displayFor );

	const createMutation = useMutation( {
		mutationFn: ( body ) => post( '/terms', body ),
		onMutate: ( body ) => {
			setError( null );
			setPending( { name: body.name } );
		},
		onSuccess: ( saved ) => {
			// Keep the token visible until the refetch returns.
			queryClient.setQueryData( [ 'terms', 'tag' ], ( old ) =>
				Array.isArray( old ) ? [ ...old, saved ] : [ saved ]
			);
			onAdd( saved.id );
			setNewName( '' );
			setPending( null );
			queryClient.invalidateQueries( {
				queryKey: [ 'terms', 'tag' ],
			} );
		},
		onError: ( err ) => {
			// The form does not receive a term until this request succeeds.
			setPending( null );
			setError( errorDetail( err ) );
		},
	} );

	/**
	 * Apply token edits to the selected term ids.
	 *
	 * @param {string[]} nextTokens The tokens after the edit.
	 * @return {void}
	 */
	const onFieldChange = ( nextTokens ) => {
		nextTokens
			.filter( ( token ) => ! tokens.includes( token ) )
			.forEach( ( token ) => {
				const id = displayToId.get( nameKey( token ) );
				if ( id ) {
					onAdd( id );
				}
			} );
		tokens
			.filter( ( token ) => ! nextTokens.includes( token ) )
			.forEach( ( token ) => {
				const id = displayToId.get( nameKey( token ) );
				if ( id ) {
					onRemove( id );
				}
			} );
	};

	/**
	 * Allow tokens that match an existing term.
	 *
	 * @param {string} token The typed token.
	 * @return {boolean} True when it maps to a term.
	 */
	const validateInput = ( token ) => displayToId.has( nameKey( token ) );

	/**
	 * Create the typed term, or refuse a name that already exists.
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
					/* translators: %s: the tag name a merchant tried to create. */
					__( 'A tag named "%s" already exists.', 'tiermint' ),
					name
				)
			);
			return;
		}
		createMutation.mutate( { taxonomy: 'tag', name } );
	};

	let body;
	if ( ! inHand && firstReadPending( termsQuery ) ) {
		body = <SkeletonPanel />;
	} else if ( ! inHand && termsQuery.isError ) {
		// Do not offer creation until the first term list is known.
		body = (
			<Notice status="error" isDismissible={ false }>
				{ termsQuery.error.message }
			</Notice>
		);
	} else {
		body = (
			<>
				<FormTokenField
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					__experimentalExpandOnFocus
					__experimentalValidateInput={ validateInput }
					label={ __( 'Tags', 'tiermint' ) }
					value={ tokens }
					suggestions={ suggestions }
					onChange={ onFieldChange }
				/>
				<div className="tiermint-term-field__add">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Add a new tag', 'tiermint' ) }
						value={ newName }
						onChange={ ( value ) => {
							setNewName( value );
							if ( error ) {
								setError( null );
							}
						} }
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
						{ __( 'Add tag', 'tiermint' ) }
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
			<h2>{ __( 'Product tags', 'tiermint' ) }</h2>
			{ body }
		</div>
	);
}

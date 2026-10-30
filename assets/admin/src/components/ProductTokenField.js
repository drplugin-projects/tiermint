/**
 * Multi-select product picker that stores product ids.
 *
 * Show duplicate names with ids. Accept only known products.
 */

/**
 * External dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { FormTokenField } from '@wordpress/components';
import { useQuery } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { get } from '../api/client';

/**
 * Wait after typing before searching.
 *
 * @type {number}
 */
const SEARCH_DEBOUNCE = 250;

/**
 * Product token, with its id when names repeat.
 *
 * @param {number}                 id       The product id.
 * @param {string}                 name     The product name.
 * @param {Object<string, number>} nameSeen How many known products carry each name.
 * @return {string} The display token.
 */
function tokenFor( id, name, nameSeen ) {
	return nameSeen[ name ] > 1 ? `${ name } (#${ id })` : name;
}

/**
 * A multi-select product picker.
 *
 * @param {Object}                  props          Component props.
 * @param {string}                  props.label    The field label.
 * @param {number[]}                props.value    The selected product ids.
 * @param {(ids: number[]) => void} props.onChange Report the new id selection.
 * @param {string}                  [props.help]   Optional help text shown below the field.
 * @return {Element} The picker element.
 */
export default function ProductTokenField( { label, value, onChange, help } ) {
	const ids = useMemo(
		() => ( Array.isArray( value ) ? value : [] ),
		[ value ]
	);

	const [ term, setTerm ] = useState( '' );
	const [ search, setSearch ] = useState( '' );

	useEffect( () => {
		const timer = setTimeout(
			() => setSearch( term.trim() ),
			SEARCH_DEBOUNCE
		);
		return () => clearTimeout( timer );
	}, [ term ] );

	const suggestionsQuery = useQuery( {
		queryKey: [ 'editor-products', 'search', search ],
		queryFn: () => get( '/editor/products', { search } ),
	} );

	// The saved ids are resolved to their names once on open (and whenever the selection
	// changes), so a token never shows a bare id after a reload.
	const includeKey = ids.join( ',' );
	const includeQuery = useQuery( {
		queryKey: [ 'editor-products', 'include', includeKey ],
		queryFn: () => get( '/editor/products', { include: ids } ),
		enabled: ids.length > 0,
	} );

	// Everything the field knows about, merged into one id -> name map: the resolved
	// selection plus the current suggestions.
	const known = useMemo( () => {
		const map = new Map();
		[ includeQuery.data, suggestionsQuery.data ].forEach( ( rows ) => {
			( Array.isArray( rows ) ? rows : [] ).forEach( ( row ) => {
				map.set( row.id, row.name );
			} );
		} );
		return map;
	}, [ includeQuery.data, suggestionsQuery.data ] );

	// The token <-> id maps, and the tokens/suggestions the field renders.
	const { tokens, suggestions, tokenToId, validateInput } = useMemo( () => {
		const nameSeen = {};
		known.forEach( ( name ) => {
			nameSeen[ name ] = ( nameSeen[ name ] || 0 ) + 1;
		} );

		const toId = new Map();
		known.forEach( ( name, id ) => {
			toId.set( tokenFor( id, name, nameSeen ), id );
		} );

		// A selected id whose name has not resolved yet shows a bare-id token, still
		// removable, and maps back to its id.
		const shown = ids.map( ( id ) => {
			if ( known.has( id ) ) {
				return tokenFor( id, known.get( id ), nameSeen );
			}
			const placeholder = `#${ id }`;
			toId.set( placeholder, id );
			return placeholder;
		} );

		const options = (
			Array.isArray( suggestionsQuery.data ) ? suggestionsQuery.data : []
		)
			.filter( ( row ) => ! ids.includes( row.id ) )
			.map( ( row ) => tokenFor( row.id, row.name, nameSeen ) );

		return {
			tokens: shown,
			suggestions: options,
			tokenToId: toId,
			validateInput: ( token ) => toId.has( token ),
		};
	}, [ known, ids, suggestionsQuery.data ] );

	/**
	 * Convert known tokens to selected ids.
	 *
	 * @param {string[]} nextTokens The tokens after the edit.
	 * @return {void}
	 */
	const onFieldChange = ( nextTokens ) => {
		const next = [];
		nextTokens.forEach( ( token ) => {
			const id = tokenToId.get( token );
			if ( undefined !== id && ! next.includes( id ) ) {
				next.push( id );
			}
		} );
		onChange( next );
	};

	return (
		<div className="tiermint-field tiermint-token-field">
			<FormTokenField
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				__experimentalExpandOnFocus
				__experimentalValidateInput={ validateInput }
				label={ label }
				value={ tokens }
				suggestions={ suggestions }
				onInputChange={ setTerm }
				onChange={ onFieldChange }
			/>
			{ help && <p className="tiermint-field__help">{ help }</p> }
		</div>
	);
}

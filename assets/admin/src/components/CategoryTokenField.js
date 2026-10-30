/**
 * Multi-select category picker that stores term ids.
 *
 * Show duplicate names with their slug. Accept only known categories.
 */

/**
 * External dependencies
 */
import { useMemo } from '@wordpress/element';
import { FormTokenField } from '@wordpress/components';
import { useQuery } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { get } from '../api/client';

/**
 * Category token, with its slug when names repeat.
 *
 * @param {Object}                 term     The category term as {id, slug, name}.
 * @param {Object<string, number>} nameSeen How many categories carry each name.
 * @return {string} The display token.
 */
function tokenFor( term, nameSeen ) {
	return nameSeen[ term.name ] > 1
		? `${ term.name } (${ term.slug })`
		: term.name;
}

/**
 * A multi-select category picker.
 *
 * @param {Object}                  props          Component props.
 * @param {string}                  props.label    The field label.
 * @param {number[]}                props.value    The selected term ids.
 * @param {(ids: number[]) => void} props.onChange Report the new id selection.
 * @param {string}                  [props.help]   Optional help text shown below the field.
 * @return {Element} The picker element.
 */
export default function CategoryTokenField( { label, value, onChange, help } ) {
	const ids = useMemo(
		() => ( Array.isArray( value ) ? value : [] ),
		[ value ]
	);

	const termsQuery = useQuery( {
		queryKey: [ 'editor-terms', 'category' ],
		queryFn: () => get( '/editor/terms', { taxonomy: 'category' } ),
	} );

	const terms = useMemo(
		() => ( Array.isArray( termsQuery.data ) ? termsQuery.data : [] ),
		[ termsQuery.data ]
	);

	const { tokens, suggestions, tokenToId } = useMemo( () => {
		const nameSeen = {};
		terms.forEach( ( term ) => {
			nameSeen[ term.name ] = ( nameSeen[ term.name ] || 0 ) + 1;
		} );

		const byId = new Map();
		const toId = new Map();
		terms.forEach( ( term ) => {
			byId.set( term.id, term );
			toId.set( tokenFor( term, nameSeen ), term.id );
		} );

		const shown = ids.map( ( id ) => {
			if ( byId.has( id ) ) {
				return tokenFor( byId.get( id ), nameSeen );
			}
			// A selected id whose term has not loaded (or was removed) stays a removable
			// bare-id token.
			const placeholder = `#${ id }`;
			toId.set( placeholder, id );
			return placeholder;
		} );

		const options = terms
			.filter( ( term ) => ! ids.includes( term.id ) )
			.map( ( term ) => tokenFor( term, nameSeen ) );

		return { tokens: shown, suggestions: options, tokenToId: toId };
	}, [ terms, ids ] );

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
				__experimentalValidateInput={ ( token ) =>
					tokenToId.has( token )
				}
				label={ label }
				value={ tokens }
				suggestions={ suggestions }
				onChange={ onFieldChange }
			/>
			{ help && <p className="tiermint-field__help">{ help }</p> }
		</div>
	);
}

/**
 * Generic editor registration for every TierMint block.
 *
 * Build all editor controls from the server control map.
 */
import apiFetch from '@wordpress/api-fetch';
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	ComboboxControl,
	Disabled,
	FormTokenField,
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

/**
 * The editor product-search endpoint (published id/name, edit_posts-gated).
 *
 * @type {string}
 */
const PRODUCTS_ENDPOINT = '/tiermint/v1/editor/products';

/**
 * The editor term-list endpoint (whole taxonomy as slug/name, edit_posts-gated).
 *
 * @type {string}
 */
const TERMS_ENDPOINT = '/tiermint/v1/editor/terms';

/**
 * The editor page-search endpoint (published id/title, edit_posts-gated).
 *
 * @type {string}
 */
const PAGES_ENDPOINT = '/tiermint/v1/editor/pages';

/**
 * Add encoded query parameters to a REST path.
 *
 * @param {string} path   The base REST path.
 * @param {Object} params The query params.
 * @return {string} The path with an encoded query string.
 */
function withQuery( path, params ) {
	const pairs = [];

	Object.keys( params ).forEach( ( key ) => {
		const value = params[ key ];

		if ( Array.isArray( value ) ) {
			value.forEach( ( item, index ) => {
				pairs.push(
					`${ key }[${ index }]=${ encodeURIComponent( item ) }`
				);
			} );
			return;
		}

		pairs.push( `${ key }=${ encodeURIComponent( value ) }` );
	} );

	return pairs.length ? `${ path }?${ pairs.join( '&' ) }` : path;
}

/**
 * Parse a comma-separated value into a typed array.
 *
 * @param {string} value The raw comma-separated input.
 * @param {string} items The declared item type (string|integer|number).
 * @return {Array} The typed array to store on the attribute.
 */
function parseArrayValue( value, items ) {
	const parts = value
		.split( ',' )
		.map( ( part ) => part.trim() )
		.filter( ( part ) => '' !== part );

	if ( 'integer' === items ) {
		return parts
			.filter( ( part ) => ! isNaN( parseInt( part, 10 ) ) )
			.map( ( part ) => parseInt( part, 10 ) );
	}

	if ( 'number' === items ) {
		return parts
			.filter( ( part ) => ! isNaN( parseFloat( part ) ) )
			.map( ( part ) => parseFloat( part ) );
	}

	return parts;
}

/**
 * Search and select a product or page id.
 *
 * @param {Object}      props               Component props.
 * @param {Object}      props.control       The localized control definition.
 * @param {number}      props.value         The current id attribute.
 * @param {Function}    props.setAttributes Attribute setter from the block editor.
 * @param {string}      props.endpoint      The editor search endpoint to read.
 * @param {string}      props.labelField    The result field holding the label.
 * @param {string|null} props.leadingLabel  Label for a prepended `0` option, or null.
 * @param {string}      [props.placeholder] Placeholder shown when nothing is selected.
 * @return {Element} The combobox control.
 */
function SearchableIdPicker( {
	control,
	value,
	setAttributes,
	endpoint,
	labelField,
	leadingLabel,
	placeholder,
} ) {
	const current = Number.isInteger( value ) ? value : parseInt( value, 10 );
	const selected = Number.isNaN( current ) ? 0 : current;

	const [ savedOption, setSavedOption ] = useState( null );
	const [ results, setResults ] = useState( [] );

	// A monotonically increasing token so an earlier, slower search cannot land
	// its rows on top of a later one that already resolved (fast typing).
	const searchSeq = useRef( 0 );

	// Resolve the saved id to its label. A published match gives its label; anything
	// else keeps the id visible as a "#id" option so the value is never lost.
	useEffect( () => {
		if ( selected <= 0 ) {
			setSavedOption( null );
			return undefined;
		}

		let active = true;

		apiFetch( {
			path: withQuery( endpoint, { include: [ selected ] } ),
		} )
			.then( ( rows ) => {
				if ( ! active ) {
					return;
				}

				const row = Array.isArray( rows ) ? rows[ 0 ] : null;

				setSavedOption( {
					value: String( selected ),
					label: row ? row[ labelField ] : `#${ selected }`,
				} );
			} )
			.catch( () => {} );

		return () => {
			active = false;
		};
	}, [ selected, endpoint, labelField ] );

	const runSearch = useCallback(
		( term ) => {
			const seq = searchSeq.current + 1;
			searchSeq.current = seq;

			apiFetch( { path: withQuery( endpoint, { search: term } ) } )
				.then( ( rows ) => {
					// Ignore a response a newer search has already superseded.
					if ( seq !== searchSeq.current ) {
						return;
					}

					setResults(
						Array.isArray( rows )
							? rows.map( ( row ) => ( {
									value: String( row.id ),
									label: row[ labelField ],
							  } ) )
							: []
					);
				} )
				.catch( () => {} );
		},
		[ endpoint, labelField ]
	);

	const debouncedSearch = useDebounce( runSearch, 250 );

	// Populate the list once on mount so the picker shows the first page of results
	// the moment it opens, rather than staying empty until the editor types (the
	// endpoint answers a blank search with its first page).
	useEffect( () => {
		runSearch( '' );
	}, [ runSearch ] );

	const options = useMemo( () => {
		const list = [];
		const seen = new Set();

		if ( null !== leadingLabel ) {
			list.push( { value: '0', label: leadingLabel } );
			seen.add( '0' );
		}

		if ( savedOption && ! seen.has( savedOption.value ) ) {
			list.push( savedOption );
			seen.add( savedOption.value );
		}

		results.forEach( ( option ) => {
			if ( ! seen.has( option.value ) ) {
				list.push( option );
				seen.add( option.value );
			}
		} );

		return list;
	}, [ leadingLabel, savedOption, results ] );

	return (
		<ComboboxControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ control.label }
			help={ control.description || undefined }
			placeholder={ placeholder }
			value={ String( selected ) }
			options={ options }
			onFilterValueChange={ ( next ) => debouncedSearch( next ) }
			onChange={ ( next ) =>
				setAttributes( {
					[ control.name ]: parseInt( next, 10 ) || 0,
				} )
			}
		/>
	);
}

/**
 * Select a product from the editor endpoint.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.control       The localized control definition.
 * @param {number}   props.value         The current product id attribute.
 * @param {Function} props.setAttributes Attribute setter from the block editor.
 * @return {Element} The combobox control.
 */
function ProductPicker( { control, value, setAttributes } ) {
	return (
		<SearchableIdPicker
			control={ control }
			value={ value }
			setAttributes={ setAttributes }
			endpoint={ PRODUCTS_ENDPOINT }
			labelField="name"
			leadingLabel={
				control.routed
					? __( 'Current product (from page URL)', 'tiermint' )
					: null
			}
			placeholder={
				control.routed
					? undefined
					: __( 'Select a product', 'tiermint' )
			}
		/>
	);
}

/**
 * Select a page from the editor endpoint.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.control       The localized control definition (carries zero_label).
 * @param {number}   props.value         The current page id attribute.
 * @param {Function} props.setAttributes Attribute setter from the block editor.
 * @return {Element} The combobox control.
 */
function PagePicker( { control, value, setAttributes } ) {
	return (
		<SearchableIdPicker
			control={ control }
			value={ value }
			setAttributes={ setAttributes }
			endpoint={ PAGES_ENDPOINT }
			labelField="title"
			leadingLabel={ control.zero_label || __( 'Default', 'tiermint' ) }
		/>
	);
}

/**
 * Select taxonomy terms and store their slugs.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.control       The localized control definition (carries taxonomy).
 * @param {Array}    props.value         The current slug array attribute.
 * @param {Function} props.setAttributes Attribute setter from the block editor.
 * @return {Element} The token-field control.
 */
function TermPicker( { control, value, setAttributes } ) {
	const slugs = Array.isArray( value ) ? value : [];
	const [ terms, setTerms ] = useState( [] );

	useEffect( () => {
		let active = true;

		apiFetch( {
			path: withQuery( TERMS_ENDPOINT, { taxonomy: control.taxonomy } ),
		} )
			.then( ( rows ) => {
				if ( active && Array.isArray( rows ) ) {
					setTerms( rows );
				}
			} )
			.catch( () => {} );

		return () => {
			active = false;
		};
	}, [ control.taxonomy ] );

	const nameToSlug = useMemo(
		() =>
			terms.reduce( ( map, term ) => {
				map[ term.name ] = term.slug;
				return map;
			}, {} ),
		[ terms ]
	);

	const slugToName = useMemo(
		() =>
			terms.reduce( ( map, term ) => {
				map[ term.slug ] = term.name;
				return map;
			}, {} ),
		[ terms ]
	);

	// A saved slug shows as its name once the taxonomy has loaded; until then, or
	// for a slug whose term was removed, the slug itself is the token.
	const tokens = slugs.map( ( slug ) => slugToName[ slug ] || slug );

	const suggestions = terms
		.map( ( term ) => term.name )
		.filter( ( name ) => ! tokens.includes( name ) );

	const onChange = ( nextTokens ) => {
		const next = [];

		nextTokens.forEach( ( token ) => {
			if ( undefined !== nameToSlug[ token ] ) {
				next.push( nameToSlug[ token ] );
			} else if ( undefined !== slugToName[ token ] ) {
				// A token already carrying a known slug (a saved value rendered
				// before the names loaded) is kept as is.
				next.push( token );
			}
			// Anything else is a free-typed token with no matching term: drop it.
		} );

		setAttributes( {
			[ control.name ]: next.filter(
				( slug, index ) => next.indexOf( slug ) === index
			),
		} );
	};

	return (
		<FormTokenField
			__nextHasNoMarginBottom
			__experimentalExpandOnFocus
			label={ control.label }
			value={ tokens }
			suggestions={ suggestions }
			onChange={ onChange }
		/>
	);
}

/**
 * Render one schema-derived inspector control.
 *
 * @param {Object}   control       The localized control definition.
 * @param {Object}   attributes    Current block attributes.
 * @param {Function} setAttributes Attribute setter from the block editor.
 * @return {Element} The control element.
 */
export function controlField( control, attributes, setAttributes ) {
	const value = attributes[ control.name ];
	const help = control.description || undefined;

	if ( 'product_select' === control.control && 'integer' === control.type ) {
		return (
			<ProductPicker
				key={ control.name }
				control={ control }
				value={ value }
				setAttributes={ setAttributes }
			/>
		);
	}

	if ( 'term_select' === control.control ) {
		return (
			<TermPicker
				key={ control.name }
				control={ control }
				value={ value }
				setAttributes={ setAttributes }
			/>
		);
	}

	if ( 'page_select' === control.control ) {
		return (
			<PagePicker
				key={ control.name }
				control={ control }
				value={ value }
				setAttributes={ setAttributes }
			/>
		);
	}

	if ( 'boolean' === control.type ) {
		return (
			<ToggleControl
				key={ control.name }
				__nextHasNoMarginBottom
				label={ control.label }
				help={ help }
				checked={ !! value }
				onChange={ ( next ) =>
					setAttributes( { [ control.name ]: next } )
				}
			/>
		);
	}

	if ( 'enum' === control.type ) {
		return (
			<SelectControl
				key={ control.name }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ control.label }
				help={ help }
				value={ String( value ?? '' ) }
				options={ control.options.map( ( option ) => ( {
					label: option,
					value: option,
				} ) ) }
				onChange={ ( next ) =>
					setAttributes( { [ control.name ]: next } )
				}
			/>
		);
	}

	if ( 'integer' === control.type || 'number' === control.type ) {
		return (
			<TextControl
				key={ control.name }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				label={ control.label }
				help={ help }
				value={ String( value ?? 0 ) }
				onChange={ ( next ) => {
					const parsed =
						'integer' === control.type
							? parseInt( next, 10 )
							: parseFloat( next );
					setAttributes( {
						[ control.name ]: isNaN( parsed ) ? 0 : parsed,
					} );
				} }
			/>
		);
	}

	if ( 'array' === control.type ) {
		return (
			<TextControl
				key={ control.name }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ control.label }
				help={ help || __( 'Comma-separated list.', 'tiermint' ) }
				value={ ( value || [] ).join( ', ' ) }
				onChange={ ( next ) =>
					setAttributes( {
						[ control.name ]: parseArrayValue(
							next,
							control.items
						),
					} )
				}
			/>
		);
	}

	return (
		<TextControl
			key={ control.name }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ control.label }
			help={ help }
			value={ String( value ?? '' ) }
			onChange={ ( next ) => setAttributes( { [ control.name ]: next } ) }
		/>
	);
}

/**
 * Build an edit component for a block definition.
 *
 * @param {Object} block The localized block definition (name, controls).
 * @return {Function} The block edit component.
 */
function createEdit( block ) {
	/**
	 * The generic edit component.
	 *
	 * @param {Object}   props               Block props.
	 * @param {Object}   props.attributes    Current attributes.
	 * @param {Function} props.setAttributes Attribute setter.
	 * @return {Element} The editor canvas element.
	 */
	return function Edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();

		return (
			<>
				{ block.controls.length > 0 && (
					<InspectorControls>
						<PanelBody title={ __( 'Settings', 'tiermint' ) }>
							{ block.controls.map( ( control ) =>
								controlField(
									control,
									attributes,
									setAttributes
								)
							) }
						</PanelBody>
					</InspectorControls>
				) }
				<div { ...blockProps }>
					<Disabled>
						<ServerSideRender
							block={ block.name }
							attributes={ attributes }
						/>
					</Disabled>
				</div>
			</>
		);
	};
}

const config = window.tierMintBlocks || { blocks: [] };

config.blocks.forEach( ( block ) => {
	registerBlockType( block.name, {
		edit: createEdit( block ),
		/**
		 * Dynamic blocks save no markup; the server renders.
		 *
		 * @return {null} Nothing is saved.
		 */
		save: () => null,
	} );
} );

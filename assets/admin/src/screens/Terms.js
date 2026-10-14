/**
 * Terms screen.
 *
 * Manages product categories and tags at `#/products/terms`.
 */

/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
	Button,
	TextControl,
	TextareaControl,
	SelectControl,
	Notice,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { get, post, put, del } from '../api/client';
import EmptyState from '../components/EmptyState';
import ScreenHeader from '../components/ScreenHeader';
import { SkeletonPanel } from '../components/Skeleton';
import { useUnsavedChanges } from '../app/dirtyState';
import { flattenTermTree, parentOptions } from '../products/termTree';
import { firstReadPending } from '../utils/listState';

/**
 * Creates blank category form state.
 *
 * @return {{name: string, slug: string, parent: string, description: string}}
 *         A fresh category form object.
 */
function blankCategory() {
	return { name: '', slug: '', parent: '0', description: '' };
}

/**
 * Creates blank tag form state.
 *
 * @return {{name: string, slug: string, description: string}} A fresh tag form.
 */
function blankTag() {
	return { name: '', slug: '', description: '' };
}

/**
 * Creates category form state from a term.
 *
 * @param {Object} term The category term.
 * @return {{name: string, slug: string, parent: string, description: string}}
 *         The form shape.
 */
function categoryFormFrom( term ) {
	return {
		name: term.name || '',
		slug: term.slug || '',
		parent: String( term.parent || 0 ),
		description: term.description || '',
	};
}

/**
 * Creates tag form state from a term.
 *
 * @param {Object} term The tag term.
 * @return {{name: string, slug: string, description: string}} The form shape.
 */
function tagFormFrom( term ) {
	return {
		name: term.name || '',
		slug: term.slug || '',
		description: term.description || '',
	};
}

/**
 * Checks form values.
 *
 * @param {Object} a The first form.
 * @param {Object} b The second form.
 * @return {boolean} True when they match.
 */
function sameForm( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Render the categories and tags management screen.
 *
 * @return {Element} The terms screen element.
 */
export default function Terms() {
	const queryClient = useQueryClient();

	const categoriesQuery = useQuery( {
		queryKey: [ 'terms', 'category' ],
		queryFn: () => get( '/terms', { taxonomy: 'category' } ),
	} );
	const tagsQuery = useQuery( {
		queryKey: [ 'terms', 'tag' ],
		queryFn: () => get( '/terms', { taxonomy: 'tag' } ),
	} );

	const categories = Array.isArray( categoriesQuery.data )
		? categoriesQuery.data
		: [];
	const tags = Array.isArray( tagsQuery.data ) ? tagsQuery.data : [];

	// Nest categories. Tags remain flat.
	const categoryRows = flattenTermTree( categories );

	// Add-form state by taxonomy.
	const [ addCat, setAddCat ] = useState( blankCategory );
	const [ addTag, setAddTag ] = useState( blankTag );

	// One category and one tag editor can be open.
	const [ editCatId, setEditCatId ] = useState( null );
	const [ editCat, setEditCat ] = useState( blankCategory );
	const [ editTagId, setEditTagId ] = useState( null );
	const [ editTag, setEditTag ] = useState( blankTag );

	// Track each write because a mutation observes only its latest call.
	const [ busyKeys, setBusyKeys ] = useState( {
		create: [],
		update: [],
		delete: [],
	} );

	// An open editor is dirty only when it differs from its saved term.
	const editingCategory = categories.find(
		( term ) => term.id === editCatId
	);
	const editingTag = tags.find( ( term ) => term.id === editTagId );
	useUnsavedChanges(
		! sameForm( addCat, blankCategory() ) ||
			! sameForm( addTag, blankTag() ) ||
			( editingCategory !== undefined &&
				! sameForm( editCat, categoryFormFrom( editingCategory ) ) ) ||
			( editingTag !== undefined &&
				! sameForm( editTag, tagFormFrom( editingTag ) ) )
	);

	/**
	 * Mark one target as having a write of the given kind in flight.
	 *
	 * @param {string}        action One of `create`, `update` or `delete`.
	 * @param {string|number} key    The taxonomy (create) or term id (update,
	 *                               delete) the write was issued for.
	 * @return {void}
	 */
	const markBusy = ( action, key ) => {
		setBusyKeys( ( prev ) => ( {
			...prev,
			[ action ]: prev[ action ].concat( key ),
		} ) );
	};

	/**
	 * Removes one settled target key without freeing other busy controls.
	 *
	 * @param {string}        action One of `create`, `update` or `delete`.
	 * @param {string|number} key    The taxonomy (create) or term id (update,
	 *                               delete) the write was issued for.
	 * @return {void}
	 */
	const clearBusy = ( action, key ) => {
		setBusyKeys( ( prev ) => ( {
			...prev,
			[ action ]: prev[ action ].filter( ( entry ) => entry !== key ),
		} ) );
	};

	/**
	 * Whether a term is being saved, so its editor's Save must be refused.
	 *
	 * @param {number} id The term id.
	 * @return {boolean} True while that term's update is in flight.
	 */
	const isSaving = ( id ) => busyKeys.update.includes( id );

	/**
	 * Whether a term is being deleted, so its row's Delete must be refused.
	 *
	 * @param {number} id The term id.
	 * @return {boolean} True while that term's delete is in flight.
	 */
	const isDeleting = ( id ) => busyKeys.delete.includes( id );

	/**
	 * Invalidates a taxonomy list. Deletions await this to keep their row guarded.
	 *
	 * @param {string} taxonomy The taxonomy whose list to refresh.
	 * @return {Promise<void>} Resolves once the refetch settles.
	 */
	const invalidate = ( taxonomy ) =>
		queryClient.invalidateQueries( { queryKey: [ 'terms', taxonomy ] } );

	/**
	 * Invalidates products that include renamed or deleted terms.
	 *
	 * @return {void}
	 */
	const invalidateAssignments = () => {
		queryClient.invalidateQueries( { queryKey: [ 'products' ] } );
		queryClient.invalidateQueries( { queryKey: [ 'product' ] } );
	};

	const createMutation = useMutation( {
		mutationFn: ( body ) => post( '/terms', body ),
		onMutate: ( body ) => {
			markBusy( 'create', body.taxonomy );
		},
		onSuccess: ( data, body ) => {
			if ( body.taxonomy === 'category' ) {
				setAddCat( blankCategory() );
			} else {
				setAddTag( blankTag() );
			}
			return invalidate( body.taxonomy );
		},
		onSettled: ( data, error, body ) => {
			clearBusy( 'create', body.taxonomy );
		},
	} );

	const updateMutation = useMutation( {
		mutationFn: ( { id, changes } ) => put( `/terms/${ id }`, changes ),
		onMutate: ( { id } ) => {
			markBusy( 'update', id );
		},
		onSuccess: ( data, variables ) => {
			if ( variables.taxonomy === 'category' ) {
				setEditCatId( null );
			} else {
				setEditTagId( null );
			}
			invalidateAssignments();
			return invalidate( variables.taxonomy );
		},
		onSettled: ( data, error, variables ) => {
			clearBusy( 'update', variables.id );
		},
	} );

	const deleteMutation = useMutation( {
		mutationFn: ( { id } ) => del( `/terms/${ id }` ),
		onMutate: ( { id } ) => {
			markBusy( 'delete', id );
		},
		onSuccess: ( data, variables ) => {
			invalidateAssignments();
			return invalidate( variables.taxonomy );
		},
		onSettled: ( data, error, variables ) => {
			clearBusy( 'delete', variables.id );
		},
	} );

	/**
	 * Create a category from the add form, sending parent only when set.
	 *
	 * @return {void}
	 */
	const onAddCategory = () => {
		const body = { taxonomy: 'category', name: addCat.name };
		if ( addCat.slug ) {
			body.slug = addCat.slug;
		}
		if ( addCat.parent && addCat.parent !== '0' ) {
			body.parent = Number( addCat.parent );
		}
		if ( addCat.description ) {
			body.description = addCat.description;
		}
		createMutation.mutate( body );
	};

	/**
	 * Create a tag from the add form. Tags are flat, so no parent is sent.
	 *
	 * @return {void}
	 */
	const onAddTag = () => {
		const body = { taxonomy: 'tag', name: addTag.name };
		if ( addTag.slug ) {
			body.slug = addTag.slug;
		}
		if ( addTag.description ) {
			body.description = addTag.description;
		}
		createMutation.mutate( body );
	};

	/**
	 * Open the inline editor for a category, seeding it from the term.
	 *
	 * @param {Object} term The category to edit.
	 * @return {void}
	 */
	const startEditCategory = ( term ) => {
		setEditCatId( term.id );
		setEditCat( categoryFormFrom( term ) );
	};

	/**
	 * Open the inline editor for a tag, seeding it from the term.
	 *
	 * @param {Object} term The tag to edit.
	 * @return {void}
	 */
	const startEditTag = ( term ) => {
		setEditTagId( term.id );
		setEditTag( tagFormFrom( term ) );
	};

	/**
	 * Persist inline category edits.
	 *
	 * @param {Object} term The category being edited.
	 * @return {void}
	 */
	const saveCategory = ( term ) => {
		updateMutation.mutate( {
			id: term.id,
			taxonomy: 'category',
			changes: {
				name: editCat.name,
				slug: editCat.slug,
				parent: Number( editCat.parent ) || 0,
				description: editCat.description,
			},
		} );
	};

	/**
	 * Persist inline tag edits.
	 *
	 * @param {Object} term The tag being edited.
	 * @return {void}
	 */
	const saveTag = ( term ) => {
		updateMutation.mutate( {
			id: term.id,
			taxonomy: 'tag',
			changes: {
				name: editTag.name,
				slug: editTag.slug,
				description: editTag.description,
			},
		} );
	};

	/**
	 * Delete a category after confirming, explaining the re-parent behavior.
	 *
	 * @param {Object} term The category to delete.
	 * @return {void}
	 */
	const deleteCategory = ( term ) => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__(
				'Deleting this category will re-parent its child categories to its parent. Continue?',
				'tiermint'
			)
		);
		if ( confirmed ) {
			deleteMutation.mutate( { id: term.id, taxonomy: 'category' } );
		}
	};

	/**
	 * Delete a tag after confirming.
	 *
	 * @param {Object} term The tag to delete.
	 * @return {void}
	 */
	const deleteTag = ( term ) => {
		// eslint-disable-next-line no-alert
		if ( window.confirm( __( 'Delete this tag?', 'tiermint' ) ) ) {
			deleteMutation.mutate( { id: term.id, taxonomy: 'tag' } );
		}
	};

	return (
		<section className="tiermint-admin-screen">
			<ScreenHeader
				title={ __( 'Categories and tags', 'tiermint' ) }
				description={ __(
					'Categories group products into a hierarchy; tags are flat labels. Both are assigned per product in the product editor.',
					'tiermint'
				) }
			/>

			{ ( createMutation.isError ||
				updateMutation.isError ||
				deleteMutation.isError ) && (
				<Notice status="error" isDismissible={ false }>
					{
						(
							createMutation.error ||
							updateMutation.error ||
							deleteMutation.error
						).message
					}
				</Notice>
			) }

			<div className="tiermint-terms-columns">
				<section
					aria-label={ __( 'Categories', 'tiermint' ) }
					className="tiermint-panel tiermint-terms-panel"
				>
					<h2>{ __( 'Categories', 'tiermint' ) }</h2>

					{ /* Show this before the parent selector to prevent duplicate root terms. */ }
					{ categoriesQuery.isError && (
						<Notice status="error" isDismissible={ false }>
							{ categoriesQuery.error.message }
						</Notice>
					) }

					<div className="tiermint-term-add">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Name', 'tiermint' ) }
							value={ addCat.name }
							onChange={ ( value ) =>
								setAddCat( ( prev ) => ( {
									...prev,
									name: value,
								} ) )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Slug (optional)', 'tiermint' ) }
							value={ addCat.slug }
							onChange={ ( value ) =>
								setAddCat( ( prev ) => ( {
									...prev,
									slug: value,
								} ) )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Parent', 'tiermint' ) }
							value={ addCat.parent }
							options={ parentOptions( categories, null ) }
							onChange={ ( value ) =>
								setAddCat( ( prev ) => ( {
									...prev,
									parent: value,
								} ) )
							}
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Description (optional)', 'tiermint' ) }
							value={ addCat.description }
							onChange={ ( value ) =>
								setAddCat( ( prev ) => ( {
									...prev,
									description: value,
								} ) )
							}
						/>
						{ /* Keep a pending write guarded until the refreshed list arrives. */ }
						<Button
							variant="primary"
							isBusy={ busyKeys.create.includes( 'category' ) }
							disabled={
								! addCat.name ||
								busyKeys.create.includes( 'category' )
							}
							__experimentalIsFocusable
							onClick={ onAddCategory }
						>
							{ __( 'Add category', 'tiermint' ) }
						</Button>
					</div>

					{ firstReadPending( categoriesQuery ) && <SkeletonPanel /> }

					{ /* Do not claim the list is empty before a successful read. */ }
					{ ! firstReadPending( categoriesQuery ) &&
						! categoriesQuery.isError &&
						categories.length === 0 && (
							<EmptyState
								className="tiermint-empty-state--panel"
								message={ __(
									'No categories yet. Add one above to start grouping products.',
									'tiermint'
								) }
							/>
						) }

					<ul className="tiermint-term-list">
						{ categoryRows.map( ( { term, depth } ) =>
							editCatId === term.id ? (
								<li
									key={ term.id }
									className="tiermint-term-edit"
									style={ { '--tiermint-term-depth': depth } }
								>
									<TextControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __( 'Name', 'tiermint' ) }
										value={ editCat.name }
										onChange={ ( value ) =>
											setEditCat( ( prev ) => ( {
												...prev,
												name: value,
											} ) )
										}
									/>
									<TextControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Slug (optional)',
											'tiermint'
										) }
										value={ editCat.slug }
										onChange={ ( value ) =>
											setEditCat( ( prev ) => ( {
												...prev,
												slug: value,
											} ) )
										}
									/>
									<SelectControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __( 'Parent', 'tiermint' ) }
										value={ editCat.parent }
										options={ parentOptions(
											categories,
											term.id
										) }
										onChange={ ( value ) =>
											setEditCat( ( prev ) => ( {
												...prev,
												parent: value,
											} ) )
										}
									/>
									<TextareaControl
										__nextHasNoMarginBottom
										label={ __(
											'Description (optional)',
											'tiermint'
										) }
										value={ editCat.description }
										onChange={ ( value ) =>
											setEditCat( ( prev ) => ( {
												...prev,
												description: value,
											} ) )
										}
									/>
									<div className="tiermint-form-actions">
										<Button
											variant="primary"
											isBusy={ isSaving( term.id ) }
											disabled={ isSaving( term.id ) }
											__experimentalIsFocusable
											onClick={ () =>
												saveCategory( term )
											}
										>
											{ __( 'Save', 'tiermint' ) }
										</Button>
										<Button
											variant="secondary"
											onClick={ () =>
												setEditCatId( null )
											}
										>
											{ __( 'Cancel', 'tiermint' ) }
										</Button>
									</div>
								</li>
							) : (
								<li
									key={ term.id }
									style={ { '--tiermint-term-depth': depth } }
								>
									<span className="tiermint-term-name">
										{ term.name }
									</span>
									<span className="tiermint-term-slug">
										{ term.slug }
									</span>
									<div className="tiermint-term-actions">
										<Button
											variant="secondary"
											onClick={ () =>
												startEditCategory( term )
											}
										>
											{ __( 'Edit', 'tiermint' ) }
										</Button>
										<Button
											variant="secondary"
											isDestructive
											isBusy={ isDeleting( term.id ) }
											disabled={ isDeleting( term.id ) }
											__experimentalIsFocusable
											onClick={ () =>
												deleteCategory( term )
											}
										>
											{ __( 'Delete', 'tiermint' ) }
										</Button>
									</div>
								</li>
							)
						) }
					</ul>
				</section>

				<section
					aria-label={ __( 'Tags', 'tiermint' ) }
					className="tiermint-panel tiermint-terms-panel"
				>
					<h2>{ __( 'Tags', 'tiermint' ) }</h2>

					{ tagsQuery.isError && (
						<Notice status="error" isDismissible={ false }>
							{ tagsQuery.error.message }
						</Notice>
					) }

					<div className="tiermint-term-add">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Name', 'tiermint' ) }
							value={ addTag.name }
							onChange={ ( value ) =>
								setAddTag( ( prev ) => ( {
									...prev,
									name: value,
								} ) )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Slug (optional)', 'tiermint' ) }
							value={ addTag.slug }
							onChange={ ( value ) =>
								setAddTag( ( prev ) => ( {
									...prev,
									slug: value,
								} ) )
							}
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Description (optional)', 'tiermint' ) }
							value={ addTag.description }
							onChange={ ( value ) =>
								setAddTag( ( prev ) => ( {
									...prev,
									description: value,
								} ) )
							}
						/>
						<Button
							variant="primary"
							isBusy={ busyKeys.create.includes( 'tag' ) }
							disabled={
								! addTag.name ||
								busyKeys.create.includes( 'tag' )
							}
							__experimentalIsFocusable
							onClick={ onAddTag }
						>
							{ __( 'Add tag', 'tiermint' ) }
						</Button>
					</div>

					{ firstReadPending( tagsQuery ) && <SkeletonPanel /> }

					{ ! firstReadPending( tagsQuery ) &&
						! tagsQuery.isError &&
						tags.length === 0 && (
							<EmptyState
								className="tiermint-empty-state--panel"
								message={ __(
									'No tags yet. Add one above to start labelling products.',
									'tiermint'
								) }
							/>
						) }

					<ul className="tiermint-term-list">
						{ tags.map( ( term ) =>
							editTagId === term.id ? (
								<li
									key={ term.id }
									className="tiermint-term-edit"
								>
									<TextControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __( 'Name', 'tiermint' ) }
										value={ editTag.name }
										onChange={ ( value ) =>
											setEditTag( ( prev ) => ( {
												...prev,
												name: value,
											} ) )
										}
									/>
									<TextControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Slug (optional)',
											'tiermint'
										) }
										value={ editTag.slug }
										onChange={ ( value ) =>
											setEditTag( ( prev ) => ( {
												...prev,
												slug: value,
											} ) )
										}
									/>
									<TextareaControl
										__nextHasNoMarginBottom
										label={ __(
											'Description (optional)',
											'tiermint'
										) }
										value={ editTag.description }
										onChange={ ( value ) =>
											setEditTag( ( prev ) => ( {
												...prev,
												description: value,
											} ) )
										}
									/>
									<div className="tiermint-form-actions">
										<Button
											variant="primary"
											isBusy={ isSaving( term.id ) }
											disabled={ isSaving( term.id ) }
											__experimentalIsFocusable
											onClick={ () => saveTag( term ) }
										>
											{ __( 'Save', 'tiermint' ) }
										</Button>
										<Button
											variant="secondary"
											onClick={ () =>
												setEditTagId( null )
											}
										>
											{ __( 'Cancel', 'tiermint' ) }
										</Button>
									</div>
								</li>
							) : (
								<li key={ term.id }>
									<span className="tiermint-term-name">
										{ term.name }
									</span>
									<span className="tiermint-term-slug">
										{ term.slug }
									</span>
									<div className="tiermint-term-actions">
										<Button
											variant="secondary"
											onClick={ () =>
												startEditTag( term )
											}
										>
											{ __( 'Edit', 'tiermint' ) }
										</Button>
										<Button
											variant="secondary"
											isDestructive
											isBusy={ isDeleting( term.id ) }
											disabled={ isDeleting( term.id ) }
											__experimentalIsFocusable
											onClick={ () => deleteTag( term ) }
										>
											{ __( 'Delete', 'tiermint' ) }
										</Button>
									</div>
								</li>
							)
						) }
					</ul>
				</section>
			</div>
		</section>
	);
}

/**
 * Route registry entry for the terms screen.
 *
 * @type {{path: string, title: string, capability: string, component: Function}}
 */
export const route = {
	path: '#/products/terms',
	title: __( 'Categories and tags', 'tiermint' ),
	capability: 'manage_tiermint',
	component: Terms,
};

/**
 * Details panel for the product editor.
 *
 * Keep the saved permalink visible while a slug change is unsaved.
 */

/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl } from '@wordpress/components';

/**
 * Render the details panel.
 *
 * @param {Object}      props             Component props.
 * @param {Object}      props.form        The current product form state.
 * @param {Function}    props.update      Update one top-level form field.
 * @param {Element}     props.fieldPanels The registered product-field panels node.
 * @param {boolean}     props.isNew       Whether this is an unsaved product.
 * @param {string|null} props.permalink   The saved product's storefront URL, or
 *                                        null when there is none to link to.
 * @return {Element} The details panel element.
 */
export default function DetailsPanel( {
	form,
	update,
	fieldPanels,
	isNew,
	permalink,
} ) {
	// Reset this state when the editor loads a different product.
	const [ editingSlug, setEditingSlug ] = useState( false );

	const hasLink = ! isNew && !! permalink;
	const showSlug = ! hasLink || editingSlug;

	const slugField = (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ __( 'Slug', 'tiermint' ) }
			value={ form.slug }
			help={
				isNew
					? __(
							'Leave empty to generate one from the name.',
							'tiermint'
					  )
					: __( 'Leave empty to keep the current slug.', 'tiermint' )
			}
			onChange={ ( value ) => update( 'slug', value ) }
		/>
	);

	return (
		<div className="tiermint-panel">
			<div className="tiermint-form-stack">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Name', 'tiermint' ) }
					value={ form.name }
					onChange={ ( value ) => update( 'name', value ) }
				/>
				<div className="tiermint-permalink">
					{ hasLink && (
						<p className="tiermint-permalink__line">
							<span className="tiermint-permalink__label">
								{ __( 'Permalink:', 'tiermint' ) }
							</span>
							<a
								className="tiermint-permalink__url"
								href={ permalink }
								target="_blank"
								rel="noreferrer"
							>
								{ permalink }
							</a>
							{ ! editingSlug && (
								<Button
									variant="link"
									onClick={ () => setEditingSlug( true ) }
								>
									{ __( 'Edit', 'tiermint' ) }
								</Button>
							) }
						</p>
					) }
					{ showSlug && slugField }
					{ ! hasLink && (
						<p className="tiermint-permalink__note">
							{ isNew
								? __(
										'Save the product to get a shareable link.',
										'tiermint'
								  )
								: __(
										'Set a product details page under Settings to get a shareable link.',
										'tiermint'
								  ) }
						</p>
					) }
				</div>
			</div>
			{ fieldPanels }
		</div>
	);
}

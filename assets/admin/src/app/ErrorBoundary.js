/**
 * Error boundary for the admin content area.
 *
 * Keeps navigation available when a screen fails to render.
 */

/**
 * External dependencies
 */
import { Component } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Render-error boundary that swaps a failed subtree for an error notice.
 */
export default class ErrorBoundary extends Component {
	/**
	 * Seed the boundary with a no-error state.
	 *
	 * @param {Object} props The component props.
	 */
	constructor( props ) {
		super( props );
		this.state = { hasError: false };
	}

	/**
	 * Flip the boundary into its error state when a child throws.
	 *
	 * @return {{hasError: boolean}} The next state.
	 */
	static getDerivedStateFromError() {
		return { hasError: true };
	}

	/**
	 * Render the children, or an error notice once a child has thrown.
	 *
	 * @return {Element} The children or the fallback notice.
	 */
	render() {
		if ( this.state.hasError ) {
			return (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Something went wrong while rendering this screen.',
						'tiermint'
					) }
				</Notice>
			);
		}
		return this.props.children;
	}
}

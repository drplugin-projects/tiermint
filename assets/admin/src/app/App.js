/**
 * Admin SPA root and shell.
 *
 * Provides shared registries and renders the matched route. Landing redirects
 * select an allowed first screen, while denied deep links show the access panel.
 */

/**
 * External dependencies
 */
import { useMemo, useEffect, useSyncExternalStore } from '@wordpress/element';
import { QueryClientProvider } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { readBootData } from '../bootData';
import { createQueryClient } from '../api/queryClient';
import { useHashRoute, matchRoute } from '../router/router';
import {
	createRouteRegistry,
	hasCapability,
	resolveLandingRedirect,
} from '../routes/registry';
import {
	createFieldRendererRegistry,
	FieldRendererContext,
} from '../settings/fieldRenderers';
import {
	createProductFieldRendererRegistry,
	ProductFieldRendererContext,
} from '../products/fieldRenderers';
import {
	createPriceTierExtensionRegistry,
	PriceTierExtensionContext,
} from '../products/priceTierExtensions';
import { replaceHash } from './navigate';
import CacheSeam from './cacheSeam';
import {
	createDashboardPanelRegistry,
	DashboardPanelContext,
} from './dashboardPanels';
import {
	createOrderDetailActionRegistry,
	OrderDetailActionContext,
} from './orderDetailActions';
import {
	createReleaseRowExtensionRegistry,
	ReleaseRowExtensionContext,
} from './releaseRowExtensions';
import ErrorBoundary from './ErrorBoundary';
import NotFound from './NotFound';
import AccessDenied from './AccessDenied';
import UpsellBanner from './UpsellBanner';

/**
 * The navigable chrome: nav plus the matched screen.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The normalized boot object.
 * @return {Element} The admin shell element.
 */
function AdminShell( { boot } ) {
	// One registry per boot object, bound to its `admin` namespace.
	const registry = useMemo( () => createRouteRegistry( boot ), [ boot ] );

	// Register extension fields before Settings mounts.
	const fieldRenderers = useMemo(
		() => createFieldRendererRegistry( boot ),
		[ boot ]
	);

	// Register extension product fields before the editor mounts.
	const productFieldRenderers = useMemo(
		() => createProductFieldRendererRegistry( boot ),
		[ boot ]
	);

	const dashboardPanels = useMemo(
		() => createDashboardPanelRegistry( boot ),
		[ boot ]
	);

	// Register extension order actions before the detail screen mounts.
	const orderDetailActions = useMemo(
		() => createOrderDetailActionRegistry( boot ),
		[ boot ]
	);

	const releaseRowExtensions = useMemo(
		() => createReleaseRowExtensionRegistry( boot ),
		[ boot ]
	);

	const priceTierExtensions = useMemo(
		() => createPriceTierExtensionRegistry( boot ),
		[ boot ]
	);

	// Late route registration occurs outside React, and can land between the
	// first render and the subscription.
	const routes = useSyncExternalStore(
		registry.subscribe,
		registry.getRoutes
	);

	const hash = useHashRoute();
	const match = matchRoute( routes, hash );

	// Redirect only empty landing hashes. Keep denied deep links visible.
	const landingRedirect = resolveLandingRedirect( routes, boot.caps, hash );
	useEffect( () => {
		if ( landingRedirect ) {
			// Do not keep the denied landing in browser history.
			replaceHash( landingRedirect );
		}
	}, [ landingRedirect ] );

	let content;
	if ( landingRedirect ) {
		// Wait for the redirect to avoid flashing the access panel.
		content = null;
	} else if ( ! match ) {
		content = <NotFound />;
	} else if ( ! hasCapability( boot.caps, match.route.capability ) ) {
		content = <AccessDenied />;
	} else {
		const Screen = match.route.component;
		content = <Screen params={ match.params } boot={ boot } />;
	}

	return (
		<DashboardPanelContext.Provider value={ dashboardPanels }>
			<OrderDetailActionContext.Provider value={ orderDetailActions }>
				<ReleaseRowExtensionContext.Provider
					value={ releaseRowExtensions }
				>
					<PriceTierExtensionContext.Provider
						value={ priceTierExtensions }
					>
						<FieldRendererContext.Provider value={ fieldRenderers }>
							<ProductFieldRendererContext.Provider
								value={ productFieldRenderers }
							>
								<div className="tiermint-admin">
									<UpsellBanner boot={ boot } />
									<main className="tiermint-admin-content">
										<ErrorBoundary>
											{ content }
										</ErrorBoundary>
									</main>
								</div>
							</ProductFieldRendererContext.Provider>
						</FieldRendererContext.Provider>
					</PriceTierExtensionContext.Provider>
				</ReleaseRowExtensionContext.Provider>
			</OrderDetailActionContext.Provider>
		</DashboardPanelContext.Provider>
	);
}

/**
 * The admin SPA root: providers plus the shell.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.boot The boot object (normalized defensively here, so
 *                            callers may pass a partial object).
 * @return {Element} The app root element.
 */
export default function App( { boot } ) {
	// Accept partial boot data from tests and extensions.
	const normalizedBoot = useMemo( () => readBootData( boot ), [ boot ] );
	const queryClient = useMemo( () => createQueryClient(), [] );

	return (
		<QueryClientProvider client={ queryClient }>
			{ /* The child reads and clears the query client from context. */ }
			<CacheSeam admin={ normalizedBoot.admin } />
			<AdminShell boot={ normalizedBoot } />
		</QueryClientProvider>
	);
}

/**
 * React Query client factory.
 *
 * Keeps query defaults in one place. Data stays fresh for 30 seconds, and writes
 * invalidate their affected queries.
 */

/**
 * External dependencies
 */
import { QueryClient } from '@tanstack/react-query';

/**
 * Create a QueryClient with the app-wide defaults.
 *
 * @return {QueryClient} A configured React Query client.
 */
export function createQueryClient() {
	return new QueryClient( {
		defaultOptions: {
			queries: {
				refetchOnWindowFocus: false,
				retry: 1,
				staleTime: 30000,
			},
		},
	} );
}

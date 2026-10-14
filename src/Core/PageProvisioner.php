<?php
/**
 * Creates and wires the storefront pages a fresh install needs.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Core;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Frontend\PageResolver;
use DrPlugin\WP\TierMint\Frontend\StorefrontPageContent;

/**
 * Creates the default storefront pages.
 *
 * It never replaces a configured page. Provisioning runs on init, after post
 * types and blocks load.
 *
 * @since 1.0.0
 */
class PageProvisioner implements Hookable {

	/**
	 * Post-meta marker for provisioned pages.
	 *
	 * @var string
	 */
	public const MARKER_META = '_tiermint_page';

	/**
	 * Flag that defers provisioning until init.
	 *
	 * @var string
	 */
	public const PROVISION_FLAG = 'tiermint_pending_page_provision';

	/**
	 * The settings option holding the pages map.
	 *
	 * @var string
	 */
	private const SETTINGS_OPTION = 'tiermint_settings';

	/**
	 * Statuses that prevent page replacement.
	 *
	 * @var array<int, string>
	 */
	private const LIVE_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'maybeProvision' ), 20 );
		add_action( 'tiermint_provision_pages', array( $this, 'provision' ) );
	}

	/**
	 * Provision pages after activation.
	 *
	 * Clear the flag first. A race can create one orphan page, but markers prevent
	 * later duplicates.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybeProvision(): void {
		if ( ! get_option( self::PROVISION_FLAG ) ) {
			return;
		}

		delete_option( self::PROVISION_FLAG );
		$this->provision();
	}

	/**
	 * Create every missing storefront page and wire its settings slot.
	 *
	 * @since 1.0.0
	 *
	 * @return array{created: array<string, int>, wired: array<string, int>} The pages created and the slots wired, keyed by page key.
	 */
	public function provision(): array {
		$settings = get_option( self::SETTINGS_OPTION );
		$settings = is_array( $settings ) ? $settings : array();
		$pages    = isset( $settings['pages'] ) && is_array( $settings['pages'] ) ? $settings['pages'] : array();

		$created = array();
		$wired   = array();

		foreach ( $this->definitions() as $key => $definition ) {
			$configured = (int) ( $pages[ $key ] ?? 0 );

			// Never replace a configured live page.
			if ( $configured > 0 && $this->pageExists( $configured ) ) {
				continue;
			}

			// Reuse a marked page whose slot was cleared rather than duplicate it.
			$targetId = $this->existingMarkedPage( $key );

			if ( null === $targetId ) {
				$targetId = $this->createPage( $key, $definition['title'], $definition['content'] );

				if ( 0 === $targetId ) {
					continue;
				}

				$created[ $key ] = $targetId;
			}

			$pages[ $key ] = $targetId;
			$wired[ $key ] = $targetId;
		}

		if ( array() !== $wired ) {
			$settings['pages'] = $pages;
			update_option( self::SETTINGS_OPTION, $settings );
		}

		return array(
			'created' => $created,
			'wired'   => $wired,
		);
	}

	/**
	 * The pages the provisioner creates: page key to its title and block body.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{title: string, content: string}> The page definitions.
	 */
	private function definitions(): array {
		$definitions = array(
			PageResolver::STORE          => array(
				'title'   => __( 'Store', 'tiermint' ),
				'content' => StorefrontPageContent::store(),
			),
			PageResolver::PRODUCT        => array(
				'title'   => __( 'Product', 'tiermint' ),
				'content' => StorefrontPageContent::productDetails(),
			),
			PageResolver::CHECKOUT       => array(
				'title'   => __( 'Checkout', 'tiermint' ),
				'content' => StorefrontPageContent::checkout(),
			),
			PageResolver::ACCOUNT        => array(
				'title'   => __( 'Account', 'tiermint' ),
				'content' => StorefrontPageContent::account(),
			),
			PageResolver::LOGIN          => array(
				'title'   => __( 'Sign in', 'tiermint' ),
				'content' => StorefrontPageContent::login(),
			),
			PageResolver::PASSWORD_RESET => array(
				'title'   => __( 'Reset password', 'tiermint' ),
				'content' => StorefrontPageContent::passwordReset(),
			),
		);

		// Add registration only when the site accepts sign-ups.
		if ( (bool) get_option( 'users_can_register' ) ) {
			$definitions[ PageResolver::REGISTER ] = array(
				'title'   => __( 'Register', 'tiermint' ),
				'content' => StorefrontPageContent::registration(),
			);
		}

		return $definitions;
	}

	/**
	 * Whether a post id resolves to an existing, non-trashed post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $postId The post id.
	 * @return bool
	 */
	private function pageExists( int $postId ): bool {
		$status = get_post_status( $postId );

		return false !== $status && 'trash' !== $status;
	}

	/**
	 * The id of a live page carrying the marker for a key, or null.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The page key.
	 * @return int|null
	 */
	private function existingMarkedPage( string $key ): ?int {
		$ids = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => self::LIVE_STATUSES,
				'meta_key'    => self::MARKER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact-match lookup of our own marker on activation, not a hot path.
				'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact-match lookup of our own marker on activation, not a hot path.
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);

		return array() === $ids ? null : (int) $ids[0];
	}

	/**
	 * Create a published marked page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     The page key stamped as the marker value.
	 * @param string $title   The page title.
	 * @param string $content The block markup.
	 * @return int The new post id, or 0.
	 */
	private function createPage( string $key, string $title, string $content ): int {
		$pageId = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $pageId ) || 0 === (int) $pageId ) {
			return 0;
		}

		$pageId = (int) $pageId;

		update_post_meta( $pageId, self::MARKER_META, $key );

		return $pageId;
	}
}

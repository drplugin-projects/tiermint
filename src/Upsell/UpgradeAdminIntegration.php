<?php
/**
 * The "TierMint Pro" entry in the TierMint admin menu.
 *
 * @package DrPlugin\WP\TierMint
 */

declare(strict_types=1);

namespace DrPlugin\WP\TierMint\Upsell;

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

use DrPlugin\WP\TierMint\Core\Capabilities;
use DrPlugin\WP\TierMint\Core\Hookable;

/**
 * Adds a "TierMint Pro" submenu that opens the in-SPA upgrade screen.
 *
 * The entry shows only while Pro is absent. The filter is attached
 * unconditionally and reads the status when it fires, so the class stays
 * inspectable and unit-testable with a mocked status.
 *
 * @since 1.0.0
 */
final class UpgradeAdminIntegration implements Hookable {

	/**
	 * The menu item id.
	 *
	 * @var string
	 */
	public const ITEM_ID = 'pro_upsell';

	/**
	 * The screen hash the item opens.
	 *
	 * @var string
	 */
	public const HASH = '#/pro';

	/**
	 * The Pro-presence seam.
	 *
	 * @var ProStatus
	 */
	private ProStatus $proStatus;

	/**
	 * Wire the integration with the Pro-presence seam.
	 *
	 * @since 1.0.0
	 *
	 * @param ProStatus $proStatus The Pro-presence seam.
	 */
	public function __construct( ProStatus $proStatus ) {
		$this->proStatus = $proStatus;
	}

	/**
	 * Filter the admin menu registry.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_filter( 'tiermint_admin_menu_items', array( $this, 'addMenuItem' ) );
	}

	/**
	 * Append the "TierMint Pro" item, last, when Pro is absent.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, string>> $items The registry items built so far.
	 * @return array<int, array<string, string>> The items, with the upgrade entry when Pro is absent.
	 */
	public function addMenuItem( array $items ): array {
		if ( $this->proStatus->isActive() ) {
			return $items;
		}

		$items[] = array(
			'id'    => self::ITEM_ID,
			'title' => __( 'TierMint Pro', 'tiermint' ),
			'hash'  => self::HASH,
			'cap'   => Capabilities::MANAGE,
		);

		return $items;
	}
}

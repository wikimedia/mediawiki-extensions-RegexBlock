<?php

class RegexBlockHooks {
	/**
	 * Prepare data by getting blockers
	 *
	 * @param User $current_user Current user
	 * @return bool
	 */
	public static function onGetBlockedStatus( $current_user ) {
		global $wgRequest;

		if ( $current_user->isAllowed( 'regexblock-exempt' ) ) {
			// Users with superhuman powers (staff) should not be blocked in any case
			return true;
		}

		// sanitizeIP() check is needed for IPv6 -- upon saving a RegexBlock,
		// IPv6 IPs like ::1 (localhost) are expanded to 0:0:0:0:0:0:0:1, but
		// $wgRequest->getIP() contains just "::1" so the checks fail and
		// blocked IPv6 IPs would still be able to edit
		$ip_to_check = IP::sanitizeIP( $wgRequest->getIP() );

		/* First check cache */
		$blocked = RegexBlock::isBlockedCheck( $current_user, $ip_to_check );
		if ( $blocked ) {
			return true;
		}

		$blockers_array = RegexBlock::getBlockers();
		$block_data = RegexBlock::getBlockData( $current_user, $blockers_array );

		/* check user for each blocker */
		foreach ( $blockers_array as $blocker ) {
			$blocker_block_data = isset( $block_data[$blocker] ) ? $block_data[$blocker] : null;
			RegexBlock::blocked( $blocker, $blocker_block_data, $current_user, $ip_to_check );
		}

		return true;
	}

	/**
	 * Add a link to Special:RegexBlock on Special:Contributions/USERNAME
	 * pages if the user has 'regexblock' permission
	 *
	 * @param int $id
	 * @param Title $nt
	 * @param array $links Other existing contributions links
	 * @return bool
	 */
	public static function onContributionsToolLinks( $id, $nt, &$links ) {
		global $wgUser;
		if ( $wgUser->isAllowed( 'regexblock' ) ) {
			$links[] = Linker::linkKnown(
				SpecialPage::getTitleFor( 'RegexBlock' ),
				wfMessage( 'regexblock-link' )->escaped(),
				array(),
				array( 'wpTarget' => $nt->getText() )
			);
		}
		return true;
	}

	/**
	 * Creates the necessary database tables when the user runs
	 * maintenance/update.php.
	 *
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$file = __DIR__ . '/regexblock_schema.sql';
		$updater->addExtensionUpdate( array( 'addTable', 'blockedby', $file, true ) );
		$updater->addExtensionUpdate( array( 'addTable', 'stats_blockedby', $file, true ) );
		return true;
	}
}
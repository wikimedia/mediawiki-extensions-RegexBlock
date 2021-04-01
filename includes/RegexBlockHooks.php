<?php

use Wikimedia\IPUtils;

class RegexBlockHooks {
	/**
	 * See if we should (try to) block $current_user or not.
	 *
	 * @param User $current_user Current user
	 * @param string $ip Current user's IP address
	 * @param MediaWiki\Block\AbstractBlock|null &$block AbstractBlock subclass or null if the user isn't (yet) blocked
	 * @return bool True if the user is not blocked or blockable (etc.), false if they *are* blocked
	 */
	public static function onGetUserBlock( User $current_user, $ip, &$block ) {
		if ( $current_user->isAllowed( 'regexblock-exempt' ) ) {
			// Users with superhuman powers (staff) should not be blocked in any case
			return true;
		}

		if ( !$ip ) {
			// ABORT THE MISSION
			// RegexBlock#updateStats _needs_ an IP address; if one is not provided, we
			// get a database query error because stats_blockedby.stats_ip is a NOT NULL column
			// @todo FIXME: Just *why* is this sometimes null, though? *That* is the more interesting question.
			return true;
		}

		// @todo CHECKME: is the sanitizeIP() call still needed? Nowadays this doesn't use WebRequest,
		// so the comment below is inaccurate.
		//
		// sanitizeIP() check is needed for IPv6 -- upon saving a RegexBlock,
		// IPv6 IPs like ::1 (localhost) are expanded to 0:0:0:0:0:0:0:1, but
		// $wgRequest->getIP() contains just "::1" so the checks fail and
		// blocked IPv6 IPs would still be able to edit
		$ip_to_check = IPUtils::sanitizeIP( $ip );

		/* First check cache */
		$blocked = RegexBlock::isBlockedCheck( $current_user, $ip_to_check );
		if ( $blocked ) {
			// @todo CHECKME: make sure this works as expected
			$block = RegularExpressionDatabaseBlock::newFromID( $blocked['blckid'] );
			return false;
		}

		// @todo FIXME: This is an annoyingly stupid API. Why the hell should the *blockers* matter?!
		// Nobody cares about who _placed_ the block, what matters is who (or what) is getting blocked,
		// and the APIs should reflect that! --ashley, 30 October 2020
		$blockers_array = RegexBlock::getBlockers();
		$block_data = RegexBlock::getBlockData( $current_user, $blockers_array );

		/* check user for each blocker */
		foreach ( $blockers_array as $blocker ) {
			$blocker_block_data = isset( $block_data[$blocker] ) ? $block_data[$blocker] : null;
			$match = RegexBlock::blocked( $blocker, $blocker_block_data, $current_user, $ip_to_check );

			if ( is_array( $match ) ) {
				$block = RegularExpressionDatabaseBlock::newFromID( $match['blckid'] );
				/* This definitely works, but is a bit crude:
				$block = new MediaWiki\Block\SystemBlock( [
					'address' => $ip_to_check,
					// 'byText' => $match['blocker'], // doesn't seem to work
					'reason' => $match['reason'],
					'systemBlock' => 'global-block'
				] );
				*/
				// Returning false means we stop processing as the user is supposed to *not* be
				// allowed any further
				return false;
			}
		}

		return true;
	}

	/**
	 * Add a link to Special:RegexBlock on Special:Contributions/USERNAME
	 * pages if the user has 'regexblock' permission
	 *
	 * @param int $id
	 * @param Title $nt
	 * @param array &$links Other existing contributions links
	 * @param SpecialContributions $sp
	 */
	public static function onContributionsToolLinks( $id, $nt, &$links, SpecialPage $sp ) {
		if ( $sp->getUser()->isAllowed( 'regexblock' ) ) {
			$links[] = $sp->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'RegexBlock' ),
				$sp->msg( 'regexblock-link' )->text(),
				[],
				[ 'wpTarget' => $nt->getText() ]
			);
		}
	}

	/**
	 * Creates the necessary database tables when the user runs
	 * maintenance/update.php.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$file = __DIR__ . '/../sql/regexblock_schema.sql';
		$updater->addExtensionTable( 'blockedby', $file );
		$updater->addExtensionTable( 'stats_blockedby', $file );
	}
}

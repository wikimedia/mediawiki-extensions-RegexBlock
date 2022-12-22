<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IDatabase;

/**
 * RegexBlock class for the new (1.34-1.35+) blocking system.
 *
 * @note Adapted from /includes/block/DatabaseBlock.php@REL1_35.
 */
class RegularExpressionDatabaseBlock extends MediaWiki\Block\DatabaseBlock {
	/**
	 * Return the tables, fields, and join conditions to be selected to create
	 * a new block object.
	 *
	 * @return array With three keys:
	 *   - tables: (string[]) to include in the `$table` to `IDatabase->select()`
	 *   - fields: (string[]) to include in the `$vars` to `IDatabase->select()`
	 *   - joins: (array) to include in the `$join_conds` to `IDatabase->select()`
	 */
	public static function getQueryInfo() {
		return [
			'tables' => 'blockedby',
			'fields' => [
				'blckby_id',
				'blckby_name',
				'blckby_blocker',
				'blckby_timestamp',
				'blckby_expire',
				'blckby_create',
				'blckby_exact',
				'blckby_reason'
			],
			'joins' => []
		];
	}

	/**
	 * Load a block from the block ID.
	 *
	 * @param int $id ID to search for
	 * @return RegularExpressionDatabaseBlock|null
	 */
	public static function newFromID( $id ) {
		$dbr = RegexBlock::getDB( DB_REPLICA );
		$blockQuery = self::getQueryInfo();
		$res = $dbr->selectRow(
			$blockQuery['tables'],
			$blockQuery['fields'],
			[ 'blckby_id' => $id ],
			__METHOD__,
			[],
			$blockQuery['joins']
		);
		if ( $res ) {
			return self::newFromRow( $res );
		} else {
			return null;
		}
	}

	/**
	 * Load blocks from the database which target the specific target exactly, or which cover the
	 * vague target.
	 *
	 * @param User|string|null $specificTarget
	 * @param int|null $specificType
	 * @param bool $fromMaster
	 * @param User|string|null $vagueTarget Also search for blocks affecting this target. Doesn't
	 *     make any sense to use TYPE_AUTO / TYPE_ID here. Leave blank to skip IP lookups.
	 * @throws MWException
	 * @return DatabaseBlock[] Any relevant blocks
	 */
	protected static function newLoad(
		$specificTarget,
		$specificType,
		$fromMaster,
		$vagueTarget = null
	) {
		$db = RegexBlock::getDB( $fromMaster ? DB_PRIMARY : DB_REPLICA );

		if ( $specificType !== null ) {
			$conds = [ 'blckby_name' => [ (string)$specificTarget ] ];
		} else {
			$conds = [ 'blckby_name' => [] ];
		}

		# Be aware that the != '' check is explicit, since empty values will be
		# passed by some callers (T31116)
		if ( $vagueTarget != '' ) {
			list( $target, $type ) = self::parseTarget( $vagueTarget );
			switch ( $type ) {
				case 6: // the de facto "regular expression block" "constant", from SpecialRegexBlock.php
				case self::TYPE_USER:
				case self::TYPE_IP:
					# Slightly weird, but who are we to argue?
					$conds['blckby_name'][] = (string)$target;
					break;

				/* combined this w/ TYPE_USER above b/c RegexBlock doesn't support ranges,
				and thus getRangeCond() is not (properly) implemented

				case self::TYPE_IP:
					$conds['blckby_name'][] = (string)$target;
					$conds['blckby_name'] = array_unique( $conds['blckby_name'] );
					$conds[] = self::getRangeCond( IPUtils::toHex( $target ) );
					$conds = $db->makeList( $conds, LIST_OR );
					break;

				case self::TYPE_RANGE:
					list( $start, $end ) = IPUtils::parseRange( $target );
					$conds['blckby_name'][] = (string)$target;
					$conds[] = self::getRangeCond( $start, $end );
					$conds = $db->makeList( $conds, LIST_OR );
					break;
				*/

				default:
					throw new MWException( "Tried to load block with invalid type" );
			}
		}

		$blockQuery = self::getQueryInfo();
		$res = $db->select(
			$blockQuery['tables'],
			$blockQuery['fields'],
			$conds,
			__METHOD__,
			[],
			$blockQuery['joins']
		);

		$blocks = [];
		$blockIds = [];
		$autoBlocks = [];
		foreach ( $res as $row ) {
			$block = self::newFromRow( $row );

			# Don't use expired blocks
			if ( $block->isExpired() ) {
				continue;
			}

			# Don't use anon only blocks on users
			if ( $specificType == self::TYPE_USER && !$block->isHardblock() ) {
				continue;
			}

			// Check for duplicate autoblocks
			if ( $block->getType() !== self::TYPE_AUTO ) {
				$blocks[] = $block;
				$blockIds[] = $block->getId();
			}
		}

		return $blocks;
	}

	// @todo FIXME: maybe implement this, too:
	// public function appliesToRight( $right ) { ... }

	/**
	 * Given a database row from the blockedby table, initialize
	 * member variables
	 *
	 * @param stdClass $row A row from the blockedby table
	 */
	protected function initFromRow( $row ) {
		$this->setTarget( $row->blckby_name );

		$this->setTimestamp( wfTimestamp( TS_MW, $row->blckby_timestamp ) );
		// $this->mAuto = true;
		$this->setHideName( false );
		$this->mId = (int)$row->blckby_id;
		// $this->mParentBlockId = null;

		$this->setBlocker( User::newFromName( $row->blckby_blocker ) );

		// I wish I didn't have to do this
		$db = wfGetDB( DB_REPLICA );
		$this->setExpiry( $db->decodeExpiry( $row->blckby_expire ) );
		$this->setReason( $row->blckby_reason );

		$this->isHardblock( true );
		$this->isAutoblocking( true );
		$this->isSitewide( true );

		$this->isCreateAccountBlocked( (bool)$row->blckby_create );
		$this->isEmailBlocked( true );
		$this->isUsertalkEditAllowed( false );
	}

	/**
	 * Create a new RegularExpressionDatabaseBlock object from a database row
	 *
	 * @param stdClass $row Row from the blockedby table
	 * @return RegularExpressionDatabaseBlock
	 */
	public static function newFromRow( $row ) {
		$block = new RegularExpressionDatabaseBlock;
		$block->initFromRow( $row );
		return $block;
	}

	/**
	 * Delete the row from the blockedby table.
	 *
	 * @throws MWException
	 * @return bool
	 */
	public function delete() {
		if ( MediaWikiServices::getInstance()->getReadOnlyMode()->isReadOnly() ) {
			return false;
		}

		if ( !$this->getId() ) {
			throw new MWException(
				__METHOD__ . " requires that the mId member be filled\n"
			);
		}

		// ashley 30 October 2020 TODO: Should this be using blckby_name with (string)$this->target rather than the ID, like the old code?
		$dbw = RegexBlock::getDB( DB_PRIMARY );
		$dbw->delete( 'blockedby', [ 'blckby_id' => $this->getId() ], __METHOD__ );

		$result = ( $dbw->affectedRows() > 0 );
		if ( $result ) {
			/* success, remember to delete cache key */
			RegexBlock::unsetKeys( $regex );
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Insert a block into the blockedby table. Will fail if there is a conflicting
	 * block (same name and options) already in the database.
	 *
	 * @todo FIXME: should probably use $dbw->replace like the old method did. --ashley, 30 October 2020
	 *
	 * @param IDatabase|null $dbw If you have one available
	 * @return bool|array False on failure, assoc array on success:
	 * 	('id' => block ID, 'autoIds' => array of autoblock IDs)
	 */
	public function insert( IDatabase $dbw = null ) {
		global $wgBlockDisablesLogin;

		if ( !$this->getBlocker() || $this->getBlocker()->getName() === '' ) {
			throw new MWException( 'Cannot insert a block without a blocker set' );
		}

		wfDebug( __METHOD__ . "; timestamp {$this->mTimestamp}" );

		if ( $dbw === null ) {
			$dbw = RegexBlock::getDB( DB_PRIMARY );
		}

		// ashley 30 October 2020: hol' up...
		// self::purgeExpired();

		$row = $this->getDatabaseArray( $dbw );

		$dbw->insert( 'blockedby', $row, __METHOD__, [ 'IGNORE' ] );
		$affected = $dbw->affectedRows();
		if ( $affected ) {
			$this->setBlockId( $dbw->insertId() );
		}

		# Don't collide with expired blocks.
		# Do this after trying to insert to avoid locking.
		if ( !$affected ) {
			# T96428: The ipb_address index uses a prefix on a field, so
			# use a standard SELECT + DELETE to avoid annoying gap locks.
			$ids = $dbw->selectFieldValues(
				'blockedby',
				'blckby_id',
				[
					'blckby_name' => $row['blckby_name'],
					'blckby_blocker' => $row['blckby_blocker'],
					'blckby_expire < ' . $dbw->addQuotes( $dbw->timestamp() )
				],
				__METHOD__
			);
			if ( $ids ) {
				$dbw->delete( 'blockedby', [ 'blckby_id' => $ids ], __METHOD__ );
				$dbw->insert( 'blockedby', $row, __METHOD__, [ 'IGNORE' ] );
				$affected = $dbw->affectedRows();
				$this->setBlockId( $dbw->insertId() );
			}
		}

		if ( $affected ) {
			if ( $wgBlockDisablesLogin && $this->target instanceof User ) {
				// Change user login token to force them to be logged out.
				$this->target->setToken();
				$this->target->saveSettings();
			}

			return [
				'id' => $this->mId,
				'autoIds' => false
			];
		}

		return false;
	}

	/**
	 * Update a block in the DB with new parameters.
	 * The ID field needs to be loaded first.
	 *
	 * @return bool|array False on failure, array on success:
	 *   ('id' => block ID, 'autoIds' => array of autoblock IDs)
	 */
	public function update() {
		wfDebug( __METHOD__ . "; timestamp {$this->mTimestamp}" );
		$dbw = RegexBlock::getDB( DB_PRIMARY );

		$dbw->startAtomic( __METHOD__ );

		$result = $dbw->update(
			'blockedby',
			$this->getDatabaseArray( $dbw ),
			[ 'blckby_id' => $this->getId() ],
			__METHOD__
		);

		$dbw->endAtomic( __METHOD__ );

		if ( $result ) {
			return [
				'id' => $this->mId,
				'autoIds' => false
			];
		}

		return $result;
	}

	/**
	 * Get an array suitable for passing to $dbw->insert() or $dbw->update()
	 *
	 * @param IDatabase $dbw
	 * @return array
	 */
	protected function getDatabaseArray( IDatabase $dbw ) {
		# @todo FIXME fails w/ "Invalid timestamp - infinity"
		$expiry = /* $dbw->encodeExpiry( */ $this->getExpiry(); /* ) */

		/* undefined property is undefined
		if ( $this->forcedTargetID ) {
			$uid = $this->forcedTargetID;
		} else {
			$uid = $this->target instanceof User ? $this->target->getId() : 0;
		}
		*/

		$a = [
			'blckby_name'          => (string)$this->target,
			'blckby_blocker'       => (
				$this->getBlocker() ?
					$this->getBlocker()->getName() :
					User::newSystemUser( 'MediaWiki default', [ 'steal' ] )
			),
			'blckby_timestamp'     => $dbw->timestamp( $this->getTimestamp() ),
			'blckby_expire'        => $expiry,
			'blckby_create'        => $this->isCreateAccountBlocked(),
			'blckby_exact'         => (int)$this->getExact(),
			'blckby_reason'        => $this->getReasonComment()->text
		];

		return $a;
	}

	/**
	 * ashley note 30 October 2020: this is from SpecialRegexBlock.php, may need to be updated to AbstractBlock/DatabaseBlock standards
	 *
	 * <s>From an existing Block,</s> get the target and the type of target.
	 * Note that, except for null, it is always safe to treat the target
	 * as a string; for User objects this will return User::__toString()
	 * which in turn gives User::getName().
	 *
	 * Had to override this to take regexes into account, which SpecialBlock's
	 * method obviously doesn't, because as of MW 1.28 core doesn't have native
	 * support for blocking via regexes. One day...
	 *
	 * @param string|int|User|null $target
	 * @return array( User|String|null, Block::TYPE_ constant|null )
	 */
	public static function parseTarget( $target ) {
		# We may have been through this before
		if ( $target instanceof User ) {
			if ( IPUtils::isValid( $target->getName() ) ) {
				return [ $target, self::TYPE_IP ];
			} elseif ( RegexBlockData::isValidRegex( $target->getName() ) ) {
				return [ $target, 6 /* Block::TYPE_ constants are numbered 1-5, so using 6 here is safe for now */ ];
			} else {
				return [ $target, self::TYPE_USER ];
			}
		} elseif ( $target === null ) {
			return [ null, null ];
		}

		$target = trim( $target );

		if ( IPUtils::isValid( $target ) ) {
			# We can still create a User if it's an IP address, but we need to turn
			# off validation checking (which would exclude IP addresses)
			return [
				User::newFromName( IPUtils::sanitizeIP( $target ), false ),
				self::TYPE_IP
			];

		} elseif ( IPUtils::isValidRange( $target ) ) {
			# Can't create a User from an IP range
			return [ IPUtils::sanitizeRange( $target ), self::TYPE_RANGE ];
		}

		# Consider the possibility that this is not a username at all
		# but actually an old subpage (bug #29797)
		if ( strpos( $target, '/' ) !== false ) {
			# An old subpage, drill down to the user behind it
			$target = explode( '/', $target )[0];
		}

		$userObj = User::newFromName( $target );
		// Give regexness priority because "SpamUser.*" is also a valid username as-is,
		// but our first and foremost concern is with regexes here
		// @todo FIXME: actually this is dumb. Only in case of an invalid regex we'd
		// move onto the next conditional; we'll always return [ $target, 6 ] for regexes
		// here now.
		if ( RegexBlockData::isValidRegex( $target ) ) {
			return [ $target, 6 /* AbstractBlock::TYPE_ constants are numbered 1-5, so using 6 here is safe for now */ ];
		} elseif ( $userObj instanceof User ) {
			# Note that since numbers are valid usernames, a $target of "12345" will be
			# considered a User.  If you want to pass a block ID, prepend a hash "#12345",
			# since hash characters are not valid in usernames or titles generally.
			return [ $userObj, self::TYPE_USER ];
		} else {
			# WTF?
			return [ null, null ];
		}
	}

	/**
	 * Set the block ID
	 *
	 * @param int $blockId
	 * @return self
	 */
	private function setBlockId( $blockId ) {
		$this->mId = (int)$blockId;

		return $this;
	}

	/**
	 * Need to define this to get the RegexBlock ID to show up properly in the
	 * blockedtext i18n msg.
	 *
	 * @return int
	 */
	public function getIdentifier() {
		return (int)$this->mId;
	}

	/**
	 * @inheritDoc
	 */
	public function appliesToTitle( Title $title ) {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function appliesToNamespace( $ns ) {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function appliesToPage( $pageId ) {
		return true;
	}

	/** CUSTOM STUFF, i.e. things which are totally our own and not overrides of parent classes' methods */

	/**
	 * Set whether this is an exact username block (true) or a regex match (false)
	 *
	 * @param bool $exact
	 */
	public function setExact( $exact ) {
		$this->mExact = $exact;
	}

	/**
	 * Get whether this is an exact username block (true) or a regex match (false)
	 *
	 * @return bool
	 */
	public function getExact() {
		return $this->mExact;
	}

}

<?php
/**
 * Extension used for blocking users names and IP addresses with regular
 * expressions. Contains both the blocking mechanism and a special page to
 * add/manage blocks
 *
 * @file
 * @ingroup Extensions
 * @author Bartek Łapiński <bartek at wikia-inc.com>
 * @author Tomasz Klim
 * @author Piotr Molski <moli@wikia-inc.com>
 * @author Adrian 'ADi' Wieczorek <adi(at)wikia-inc.com>
 * @author Alexandre Emsenhuber
 * @author Jack Phoenix
 * @copyright Copyright © 2007, Wikia Inc.
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

class RegexBlock {

	/**
	 * Get a database object
	 *
	 * @param int Either DB_REPLICA (for reads) or DB_MASTER (for writes)
	 * @return Database
	 */
	public static function getDB( $db ) {
		global $wgRegexBlockDatabase;
		return wfGetDB( $db, [], $wgRegexBlockDatabase );
	}

	/**
	 * Get a memcached key
	 * @return string
	 */
	public static function memcKey( /* ... */ ) {
		global $wgRegexBlockDatabase, $wgMemc;

		$wiki = ( $wgRegexBlockDatabase !== false ) ? $wgRegexBlockDatabase : wfWikiID();
		$newArgs = array_merge( [ $wiki ], func_get_args() );

		return call_user_func_array(
			[ $wgMemc, 'makeGlobalKey' ],
			$newArgs
		);
	}

	/**
	 * Get blockers
	 *
	 * @param bool $master Use DB_MASTER for reading?
	 * @return array
	 */
	public static function getBlockers( $master = false ) {
		global $wgMemc;

		$key = self::memcKey( 'regex_blockers' );
		$cached = $wgMemc->get( $key );
		$blockers_array = [];

		if ( !is_array( $cached ) ) {
			/* get from database */
			$dbr = self::getDB( $master ? DB_MASTER : DB_REPLICA );
			$res = $dbr->select(
				'blockedby',
				[ 'blckby_blocker' ],
				[ "blckby_blocker <> ''" ],
				__METHOD__,
				[ 'GROUP BY' => 'blckby_blocker' ]
			);
			while ( $row = $res->fetchObject() ) {
				$blockers_array[] = $row->blckby_blocker;
			}
			$res->free();
			$wgMemc->set( $key, $blockers_array, 0 /* 0 = infinite */ );
		} else {
			/* get from cache */
			$blockers_array = $cached;
		}

		return $blockers_array;
	}

	/**
	 * Check if the given user is blocked
	 *
	 * @param User $user The user (object) who we're checking
	 * @param string $ip The user's IP address
	 * @return array|bool An array of arrays to run a regex match against or
	 *                    bool false if target isn't blocked
	 */
	public static function isBlockedCheck( $user, $ip ) {
		global $wgMemc;

		$result = false;

		$key = self::memcKey( 'regex_user_block', str_replace( ' ', '_', $user->getName() ) );
		$cached = $wgMemc->get( $key );

		if ( is_object( $cached ) ) {
			$ret = self::expireNameCheck( $cached );
			if ( ( $ret !== false ) && ( is_array( $ret ) ) ) {
				$ret['match'] = $user->getName();
				$ret['ip'] = 0;
				$result = self::setUserData( $user, $ip, $ret['blocker'], $ret );
			}
		}

		if ( ( $result === false ) && ( $ip != $user->getName() ) ) {
			$key = self::memcKey( 'regex_user_block', str_replace( ' ', '_', $ip ) );
			$cached = $wgMemc->get( $key );
			if ( is_object( $cached ) ) {
				$ret = self::expireNameCheck( $cached );
				if ( ( $ret !== false ) && ( is_array( $ret ) ) ) {
					$ret['match'] = $ip;
					$ret['ip'] = 1;
					$result = self::setUserData( $user, $ip, $ret['blocker'], $ret );
				}
			}
		}

		return $result;
	}

	/**
	 * Fetch usernames or IP addresses to run a match against
	 *
	 * @param User $user Current user
	 * @param array $blockers List of admins who blocked
	 * @return array An array of arrays to run a regex match against
	 */
	public static function getBlockData( $user, $blockers, $master = false ) {
		global $wgMemc;

		$blockData = [];

		/**
		 * First, check if regex strings are already stored in memcached
		 * we will store entire array of regex strings here
		 */
		if ( !( $user instanceof User ) ) {
			return false;
		}

		$memkey = self::memcKey( 'regex_blockers', 'All-In-One' );
		$cached = $wgMemc->get( $memkey );

		if ( empty( $cached ) ) {
			/* Fetch data from DB, concatenate into one string, then fill cache */
			$dbr = self::getDB( $master ? DB_MASTER : DB_REPLICA );

			foreach ( $blockers as $blocker ) {
				$res = $dbr->select(
					'blockedby',
					[ 'blckby_id', 'blckby_name', 'blckby_exact' ],
					[ 'blckby_blocker' => $blocker ],
					__METHOD__
				);

				$loop = 0;
				$names = [
					'ips' => '',
					'exact' => '',
					'regex' => ''
				];
				while ( $row = $res->fetchObject() ) {
					$key = 'regex';
					if ( User::isIP( $row->blckby_name ) != 0 ) {
						$key = 'ips';
					} elseif ( $row->blckby_exact != 0 ) {
						$key = 'exact';
					}
					$names[$key][] = $row->blckby_name;
					$loop++;
				}
				$res->free();

				if ( $loop > 0 ) {
					$blockData[$blocker] = $names;
				}
			}
			$wgMemc->set( $memkey, $blockData, 0 /* 0 = infinite */ );
		} else {
			/* take it from cache */
			$blockData = $cached;
		}

		return $blockData;
	}

	/**
	 * Perform a match against all given values
	 *
	 * @param array $matching Array of strings containing list of values
	 * @param string $value A given value to run a match against
	 * @return array|bool Array of matched values or boolean false
	 */
	public static function performMatch( $matching, $value ) {
		$matched = [];

		if ( !is_array( $matching ) ) {
			/* empty? begone! */
			return false;
		}

		/* normalise for regex */
		$loop = 0;
		$match = [];
		foreach ( $matching as $one ) {
			/* the real deal */
			$found = preg_match( '/' . $one . '/i', $value, $match );
			if ( $found ) {
				if ( is_array( $match ) && ( !empty( $match[0] ) ) ) {
					$matched[] = $one;
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * Check if the block expired or not (AFTER we found an existing block)
	 *
	 * @param User $user Current User object
	 * @param bool $array_match
	 * @param int $ips Matched IP addresses
	 * @param int $iregex Use exact matching instead of regex matching?
	 * @return array|bool
	 */
	public static function expireCheck( $user, $array_match = null, $ips = 0, $iregex = 0 ) {
		global $wgMemc;

		/* I will use memcached, with the key being particular block */
		if ( empty( $array_match ) ) {
			return false;
		}

		$ret = [];
		/**
		 * For EACH match check whether timestamp expired until found VALID timestamp
		 * but: only for a BLOCKED user, and it will be memcached
		  * moreover, expired blocks will be consequently deleted
		 */
		$blocked = '';
		foreach ( $array_match as $single ) {
			$key = self::memcKey( 'regex_user_block', str_replace( ' ', '_', $single ) );
			$blocked = null;
			$cached = $wgMemc->get( $key );
			if ( empty( $cached ) || ( !is_object( $cached ) ) ) {
				/* get from database */
				$dbr = self::getDB( DB_MASTER );
				$any = $dbr->anyString();
				$where = [ 'blckby_name ' . $dbr->buildLike( $any, $single, $any ) ];
				if ( !empty( $iregex ) ) {
					$where = [ 'blckby_name' => $single ];
				}
				$res = $dbr->select(
					'blockedby',
					[
						'blckby_id', 'blckby_timestamp', 'blckby_expire',
						'blckby_blocker', 'blckby_create', 'blckby_exact',
						'blckby_reason'
					],
					$where,
					__METHOD__
				);
				if ( $row = $res->fetchObject() ) {
					/* if still valid or infinite, ok to block user */
					$blocked = $row;
				}
				$res->free();
			} else {
				/* get from cache */
				$blocked = $cached;
			}

			/* check conditions */
			if ( is_object( $blocked ) ) {
				$ret = self::expireNameCheck( $blocked );
				if ( $ret !== false ) {
					$ret['match'] = $single;
					$ret['ip'] = $ips;
					$wgMemc->set( $key, $blocked, 30 * 86400 );
					return $ret;
				} else {
					/* clean up an obsolete block */
					self::removeBlock( $single, $blocked->blckby_blocker );
				}
			}
		}

		return false;
	}

	/**
	 * Check if the USER block expired or not (AFTER we found an existing block)
	 *
	 * @param ResultWrapper $blocked Array of information about the block
	 * @return array|bool
	 */
	public static function expireNameCheck( $blocked ) {
		$ret = false;

		if ( is_object( $blocked ) ) {
			if (
				( wfTimestampNow() <= $blocked->blckby_expire ) ||
				( $blocked->blckby_expire == 'infinity' )
			)
			{
				$ret = [
					'blckid' => $blocked->blckby_id,
					'create' => $blocked->blckby_create,
					'exact'  => $blocked->blckby_exact,
					'reason' => $blocked->blckby_reason,
					'expire' => $blocked->blckby_expire,
					'blocker' => $blocked->blckby_blocker,
					'timestamp' => $blocked->blckby_timestamp
				];
			}
		}

		return $ret;
	}

	/**
	 * Remove a block from the blockedby DB table.
	 *
	 * @param string $regex Username or regular expression to unblock
	 * @param string $blocker Name of the blocker [unused - remove?]
	 * @return bool True if unblocked succeeded, otherwise false
	 */
	public static function removeBlock( $regex, $blocker ) {
		$result = false;

		$dbw = self::getDB( DB_MASTER );

		$dbw->delete(
			'blockedby',
			[ 'blckby_name' => $regex ],
			__METHOD__
		);

		if ( $dbw->affectedRows() ) {
			/* success, remember to delete cache key */
			self::unsetKeys( $regex );
			$result = true;
		}

		return $result;
	}

	/**
	 * Put the stats about block into database
	 *
	 * @param string $username
	 * @param string $user_ip IP address of the current user
	 * @param string $blocker
	 * @param $match
	 * @param int $blckid
	 */
	public static function updateStats( $user, $user_ip, $blocker, $match, $blckid ) {
		global $wgDBname;

		$result = false;

		$dbw = self::getDB( DB_MASTER );
		$dbw->insert(
			'stats_blockedby',
			[
				'stats_id' => null,
				'stats_blckby_id' => $blckid,
				'stats_user' => $user->getName(),
				'stats_ip' => $user_ip,
				'stats_blocker' => $blocker,
				'stats_timestamp' => wfTimestampNow(),
				'stats_match' => $match,
				'stats_dbname' => $wgDBname
			],
			__METHOD__
		);

		if ( $dbw->affectedRows() ) {
			$result = true;
		}

		return $result;
	}

	/**
	 * The actual blocking goes here, for each blocker
	 *
	 * @param string $blocker User name of the person who placed the block
	 * @param array $blocker_block_data
	 * @param User $user User who is being blocked
	 * @param string $user_ip IP address of the user who is being blocked
	 */
	public static function blocked( $blocker, $blocker_block_data, $user, $user_ip ) {
		if ( $blocker_block_data == null ) {
			// no data for given blocker, aborting...
			return false;
		}

		$ips = isset( $blocker_block_data['ips'] ) ? $blocker_block_data['ips'] : null;
		$names = isset( $blocker_block_data['regex'] ) ? $blocker_block_data['regex'] : null;
		$exact = isset( $blocker_block_data['exact'] ) ? $blocker_block_data['exact'] : null;
		// backward compatibility ;)
		$result = $blocker_block_data;

		/* check IPs */
		if ( !empty( $ips ) && in_array( $user_ip, $ips ) ) {
			$result['ips']['matches'] = [ $user_ip ];
			wfDebugLog( 'RegexBlock', 'Found some IPs to block: ' . implode( ',', $result['ips']['matches'] ) . "\n" );
		}

		/* check regexes */
		if ( !empty( $result['regex'] ) && is_array( $result['regex'] ) ) {
			$result['regex']['matches'] = self::performMatch( $result['regex'], $user->getName() );
			if ( !empty( $result['regex']['matches'] ) ) {
				wfDebugLog( 'RegexBlock', 'Found some regexes to block: ' . implode( ',', $result['regex']['matches'] ) . "\n" );
			}
		}

		/* check names of user */
		$exact = ( is_array( $exact ) ) ? $exact : [ $exact ];
		if ( !empty( $exact ) && in_array( $user->getName(), $exact ) ) {
			$key = array_search( $user->getName(), $exact );
			$result['exact']['matches'] = [ $exact[$key] ];
			wfDebugLog( 'RegexBlock', 'Found some users to block: ' . implode( ',', $result['exact']['matches'] ) . "\n" );
		}

		unset( $ips );
		unset( $names );
		unset( $exact );

		/**
		 * Run expire checks for all matched values
		 * this is only for determining validity of this block, so
		 * a first successful match means the block is applied
		 */
		$valid = false;
		foreach ( $result as $key => $value ) {
			$isIP = ( $key == 'ips' ) ? 1 : 0;
			$isRegex = ( $key == 'regex' ) ? 1 : 0;
			/* check if this block hasn't expired already  */
			if ( !empty( $result[$key]['matches'] ) ) {
				$valid = self::expireCheck( $user, $result[$key]['matches'], $isIP, $isRegex );
				if ( is_array( $valid ) ) {
					break;
				}
			}
		}

		if ( is_array( $valid ) ) {
			self::setUserData( $user, $user_ip, $blocker, $valid );
		}

		return true;
	}

	/**
	 * Update user structure
	 *
	 * @param User $user User who is being blocked
	 * @param string $user_ip IP of the user who is being blocker
	 * @param string $blocker User name of the person who placed this block
	 * @param array $valid Block info
	 */
	public static function setUserData( &$user, $user_ip, $blocker, $valid ) {
		global $wgContactLink, $wgRequest;

		$result = false;

		if ( !( $user instanceof User ) ) {
			return $result;
		}

		if ( is_array( $valid ) ) {
			$user->mBlockedby = User::idFromName( $blocker );
			// Need to construct a new Block object here and load it into the
			// User object in order for the block to actually, y'know, work...
			if ( $user->mBlock === null ) {
				$user->mBlock = new Block( [
					'address'         => ( $valid['ip'] == 1 ) ? $wgRequest->getIP() : $user->getName(),
					'by'              => User::idFromName( $blocker ),
					'reason'          => $valid['reason'],
					'timestamp'       => $valid['timestamp'],
					'auto'            => false,
					'expiry'          => $valid['expire'],
					'anonOnly'        => false,
					'createAccount'   => (bool)( $valid['create'] == 1 ),
					'enableAutoblock' => true,
					'hideName'        => false,
					'blockEmail'      => true,
					'allowUsertalk'   => false,
					'byText'          => $blocker,
				] );
			}

			if ( $valid['reason'] != '' ) {
				/* a reason was given, display it */
				$user->getBlock()->mReason = $valid['reason'];
			} else {
				/**
				 * Display generic reasons
				 * By default we blocked by regex match
				 */
				$user->getBlock()->mReason = wfMessage( 'regexblock-reason-regex', $wgContactLink )->text();
				if ( $valid['ip'] == 1 ) {
					/* we blocked by IP */
					$user->getBlock()->mReason = wfMessage( 'regexblock-reason-ip', $wgContactLink )->text();
				} elseif ( $valid['exact'] == 1 ) {
					/* we blocked by username exact match */
					$user->getBlock()->mReason = wfMessage( 'regexblock-reason-name', $wgContactLink )->text();
				}
			}

			/* account creation check goes through the same hook... */
			if ( $valid['create'] == 1 ) {
				if ( $user->getBlock() ) {
					$user->getBlock()->prevents( 'createaccount', true );
				}
			}

			/* set expiry information */
			if ( $user->getBlock() ) {
				/* FIXME: why does this want to do this?
				 * ANSWER: to set the block ID so that it displays correctly on the "you are blocked" msg.
				 * w/o this it displays "your block ID is #." which is confusing to the end-users.
				 * As of MW 1.28 it is not possible for an extension to set this (Block::$mId is protected).
				 * Wikia had patched core to add a setId() method to the Block class to hack around this.
				$user->getBlock()->mId = $valid['blckid'];
				*/
				$user->getBlock()->mExpiry = wfGetDB( DB_REPLICA )->decodeExpiry( $valid['expire'] );
				$user->getBlock()->mTimestamp = $valid['timestamp'];
				$user->getBlock()->setTarget( ( $valid['ip'] == 1 ) ? $wgRequest->getIP() : $user->getName() );
			}

			if ( wfReadOnly() ) {
				$result = true;
			} else {
				$result = self::updateStats( $user, $user_ip, $blocker, $valid['match'], $valid['blckid'] );
			}
		}

		return $result;
	}

	/**
	 * Clean the memcached keys
	 *
	 * @param string $username Name of the user
	 */
	public static function unsetKeys( $username ) {
		global $wgMemc, $wgUser;

		$readMaster = 1;
		$key = self::memcKey( 'regexBlockSpecial', 'number_records' );
		$wgMemc->delete( $key );
		/* main cache of user-block data */
		$key = self::memcKey( 'regex_user_block', str_replace( ' ', '_', $username ) );
		$wgMemc->delete( $key );
		/* blockers */
		$key = self::memcKey( 'regex_blockers' );
		$wgMemc->delete( $key );
		$blockers_array = self::getBlockers( $readMaster );
		/* blocker's matches */
		$key = self::memcKey( 'regex_blockers', 'All-In-One' );
		$wgMemc->delete( $key );
		self::getBlockData( $wgUser, $blockers_array, $readMaster );
	}

}
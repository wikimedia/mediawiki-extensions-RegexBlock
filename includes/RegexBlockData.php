<?php
/**
 * @class RegexBlockData
 * helper classes & functions
 */
class RegexBlockData {
	public $mNbrResults;

	public function __construct() {
		$this->mNbrResults = 0;
	}

	/**
	 * Fetch number of all rows
	 *
	 * @return int
	 */
	public function fetchNbrResults() {
		global $wgMemc;

		$this->mNbrResults = 0;
		/* we use memcached here */
		$key = RegexBlock::memcKey( 'regexBlockSpecial', 'number_records' );
		$cached = $wgMemc->get( $key );

		if ( empty( $cached ) ) {
			$dbr = RegexBlock::getDB( DB_MASTER );

			$res = $dbr->select(
				'blockedby',
				[ 'COUNT(*) AS cnt' ],
				[ "blckby_blocker <> ''" ],
				__METHOD__
			);

			$row = $dbr->fetchObject( $res );
			if ( $row ) {
				$this->mNbrResults = $row->cnt;
			}

			$wgMemc->set( $key, $this->mNbrResults, 0 /* 0 = infinite */ );
		} else {
			$this->mNbrResults = $cached;
		}

		return $this->mNbrResults;
	}

	/**
	 * @return int
	 */
	public function getNbrResults() {
		return $this->mNbrResults;
	}

	/**
	 * Fetch names of all blockers and write them into select's options
	 *
	 * @return $blockers_array
	 */
	public function fetchBlockers() {
		return RegexBlock::getBlockers();
	}

	/**
	 *
	 * @param string $current
	 * @param string $username
	 * @param int $limit LIMIT for the SQL query
	 * @param int $offset OFFSET for the SQL query
	 * @return array
	 */
	public function getBlockersData( $current = '', $username = '', $limit, $offset ) {
		global $wgLang;

		$blocker_list = [];
		/* get data and play with data */
		$dbr = RegexBlock::getDB( DB_MASTER );
		$conds = [ "blckby_blocker <> ''" ];

		if ( !empty( $current ) ) {
			$conds = [ 'blckby_blocker' => $current ];
		}

		if ( !empty( $username ) ) {
			$any = $dbr->anyString();
			$conds = [ 'blckby_name ' . $dbr->buildLike( $any, $username, $any ) ];
		}

		$res = $dbr->select(
			'blockedby',
			[
				'blckby_id', 'blckby_name', 'blckby_blocker',
				'blckby_timestamp', 'blckby_expire', 'blckby_create',
				'blckby_exact', 'blckby_reason'
			],
			$conds,
			__METHOD__,
			[
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => 'blckby_id DESC'
			]
		);

		foreach ( $res as $row ) {
			$ublock_ip = urlencode( $row->blckby_name );
			$ublock_blocker = urlencode( $row->blckby_blocker );
			if ( $row->blckby_reason ) {
				$reason = wfMessage( 'regexblock-form-reason' )->text() . $row->blckby_reason;
			} else {
				$reason = wfMessage( 'regexblock-view-reason-default' )->text();
			}
			$datim = $wgLang->timeanddate( wfTimestamp( TS_MW, $row->blckby_timestamp ), true );
			$date = $wgLang->date( wfTimestamp( TS_MW, $row->blckby_timestamp ), true );
			$time = $wgLang->time( wfTimestamp( TS_MW, $row->blckby_timestamp ), true );

			/* put data to array */
			$blocker_list[] = [
				'blckby_name' => $row->blckby_name,
				'exact_match' => $row->blckby_exact,
				'create_block' => $row->blckby_create,
				'blocker' => $row->blckby_blocker,
				'reason' => $reason,
				'datim' => $datim,
				'date' => $date,
				'time' => $time,
				'ublock_ip'	=> $ublock_ip,
				'ublock_blocker' => $ublock_blocker,
				'expiry' => $dbr->decodeExpiry( $row->blckby_expire ),
				'blckid' => $row->blckby_id
			];
		}

		return $blocker_list;
	}

	/**
	 * Fetch number of all stats rows
	 *
	 * @param int $id ID of the regexBlock entry (value of stats_blckby_id column in the stats_blockedby database table)
	 * @return int
	 */
	public function fetchNbrStatResults( $id ) {
		$nbrStats = 0;

		$dbr = RegexBlock::getDB( DB_REPLICA );
		$res = $dbr->select(
			'stats_blockedby',
			[ 'COUNT(*) AS cnt' ],
			[ 'stats_blckby_id' => intval( $id ) ],
			__METHOD__
		);

		$row = $dbr->fetchObject( $res );
		if ( $row ) {
			$nbrStats = $row->cnt;
		}

		return $nbrStats;
	}

	/**
	 * Fetch all logs
	 *
	 * @param int $id ID of the regexBlock entry (value of stats_blckby_id column in the stats_blockedby database table)
	 * @param int $limit LIMIT for the SQL query
	 * @param int $offset OFFSET for the SQL query (skip this many entries)
	 * @return $stats
	 */
	public function getStatsData( $id, $limit = 50, $offset = 0 ) {
		$stats = [];

		/* from database */
		$dbr = RegexBlock::getDB( DB_REPLICA );
		$res = $dbr->select(
			'stats_blockedby',
			[
				'stats_blckby_id', 'stats_user', 'stats_blocker',
				'stats_timestamp', 'stats_ip', 'stats_match', 'stats_dbname'
			],
			[ 'stats_blckby_id' => intval( $id ) ],
			__METHOD__,
			[
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => 'stats_timestamp DESC'
			]
		);

		foreach ( $res as $row ) {
			$stats[] = $row;
		}

		return $stats;
	}

	/**
	 * Fetch record for selected identifier of regex block
	 *
	 * @param int $id ID of the regexBlock entry (value of blckby_id column in the stats_blockedby database table)
	 * @return $record
	 */
	public function getRegexBlockById( $id ) {
		$record = null;

		$dbr = RegexBlock::getDB( DB_MASTER );
		$res = $dbr->select(
			'blockedby',
			[
				'blckby_id', 'blckby_name', 'blckby_blocker', 'blckby_timestamp',
				'blckby_expire', 'blckby_create', 'blckby_exact', 'blckby_reason'
			],
			[ 'blckby_id' => intval( $id ) ],
			__METHOD__
		);

		$row = $dbr->fetchObject( $res );
		if ( $row ) {
			$record = $row;
		}

		return $record;
	}

	/**
	 * Insert a block record to the blockedby database table
	 *
	 * @param string $address User name, IP address or regular expression being blocked
	 * @param $expiry Expiry time of the block
	 * @param int $exact Is this an exact block?
	 * @param int $creation Was account creation blocked?
	 * @param string $reason Given block reason, which will be displayed to the regexblocked user
	 */
	public static function blockUser( $address, $expiry, $exact, $creation, $reason ) {
		/* make insert */
		$dbw = RegexBlock::getDB( DB_MASTER );
		$name = RequestContext::getMain()->getUser()->getName();

		$res = $dbw->replace(
			'blockedby',
			[ 'blckby_id', 'blckby_name' ],
			[
				'blckby_id' => null,
				'blckby_name' => $address,
				'blckby_blocker' => $name,
				'blckby_timestamp' => $dbw->timestamp( wfTimestampNow() ),
				'blckby_expire' => $dbw->encodeExpiry( $expiry ),
				'blckby_exact' => intval( $exact ),
				'blckby_create' => intval( $creation ),
				'blckby_reason' => $reason
			],
			__METHOD__
		);

		// Change user login token to force them to be logged out.
		if ( $exact ) {
			$targetUser = User::newFromName( $address );
			if ( $targetUser instanceof User ) {
				$targetUser->setToken();
				$targetUser->saveSettings();
			}
		}

		return true;
	}

	/**
	 * Check that the given regex is valid
	 *
	 * @param string $text Regular expression to be tested for validity
	 * @return bool
	 */
	public static function isValidRegex( $text ) {
		return ( sprintf( '%s', @preg_match( "/{$text}/", 'regex' ) ) !== '' );
	}
}
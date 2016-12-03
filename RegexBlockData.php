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
				array( 'COUNT(*) AS cnt' ),
				array( "blckby_blocker <> ''" ),
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

		$blocker_list = array();
		/* get data and play with data */
		$dbr = RegexBlock::getDB( DB_MASTER );
		$conds = array( "blckby_blocker <> ''" );

		if ( !empty( $current ) ) {
			$conds = array( 'blckby_blocker' => $current );
		}

		if ( !empty( $username ) ) {
			$any = $dbr->anyString();
			$conds = array( 'blckby_name ' . $dbr->buildLike( $any, $username, $any ) );
		}

		$res = $dbr->select(
			'blockedby',
			array(
				'blckby_id', 'blckby_name', 'blckby_blocker',
				'blckby_timestamp', 'blckby_expire', 'blckby_create',
				'blckby_exact', 'blckby_reason'
			),
			$conds,
			__METHOD__,
			array(
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => 'blckby_id DESC'
			)
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
			$blocker_list[] = array(
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
				'expiry' => $row->blckby_expire,
				'blckid' => $row->blckby_id
			);
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

		$dbr = RegexBlock::getDB( DB_SLAVE );
		$res = $dbr->select(
			'stats_blockedby',
			array( 'COUNT(*) AS cnt' ),
			array( 'stats_blckby_id' => intval( $id ) ),
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
		$stats = array();

		/* from database */
		$dbr = RegexBlock::getDB( DB_SLAVE );
		$res = $dbr->select(
			'stats_blockedby',
			array(
				'stats_blckby_id', 'stats_user', 'stats_blocker',
				'stats_timestamp', 'stats_ip', 'stats_match', 'stats_dbname'
			),
			array( 'stats_blckby_id' => intval( $id ) ),
			__METHOD__,
			array(
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'ORDER BY' => 'stats_timestamp DESC'
			)
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
			array(
				'blckby_id', 'blckby_name', 'blckby_blocker', 'blckby_timestamp',
				'blckby_expire', 'blckby_create', 'blckby_exact', 'blckby_reason'
			),
			array( 'blckby_id' => intval( $id ) ),
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
	 * @param $address
	 * @param $expiry Expiry time of the block
	 * @param int $exact
	 * @param int $creation
	 * @param string $reason Given block reason, which will be displayed to the regexblocked user
	 */
	public static function blockUser( $address, $expiry, $exact, $creation, $reason ) {
		global $wgUser;

		/* make insert */
		$dbw = RegexBlock::getDB( DB_MASTER );
		$name = $wgUser->getName();

		$res = $dbw->replace(
			'blockedby',
			array( 'blckby_id', 'blckby_name' ),
			array(
				'blckby_id' => null,
				'blckby_name' => $address,
				'blckby_blocker' => $name,
				'blckby_timestamp' => wfTimestampNow(),
				'blckby_expire' => $expiry,
				'blckby_exact' => intval( $exact ),
				'blckby_create' => intval( $creation ),
				'blckby_reason' => $reason
			),
			__METHOD__
		);

		return true;
	}

	/**
	 * Gets and returns the expiry time values
	 *
	 * @return array Array of block expiry times
	 */
	public static function getExpireValues() {
		$expiry_values = explode( ',', wfMessage( 'regexblock-expire-duration' )->text() );
		$expiry_text = array(
			'1 hour', '2 hours', '4 hours', '6 hours', '1 day', '3 days', '1 week',
			'2 weeks', '1 month', '3 months', '6 months', '1 year', 'infinite'
		);

		return array_combine( $expiry_text, $expiry_values );
	}

	/**
	 * Check that the given regex is valid
	 *
	 * @param string $text Regular expression to be tested for validity
	 * @return bool
	 */
	public static function isValidRegex( $text ) {
		return ( sprintf( "%s", @preg_match( "/{$text}/", 'regex' ) ) === '' );
	}
}
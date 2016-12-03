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
 * @author Jack Phoenix <jack@countervandalism.net>
 * @copyright Copyright © 2007, Wikia Inc.
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

class RegexBlockForm extends SpecialPage {
	public $numResults = 0;
	public $numStatResults = 0;
	public $mAction;
	public $mFilter, $mRegexFilter;
	public $mLimit;
	public $mOffset;
	public $mError, $mMsg;

	/**
	 * Constructor -- set up the new, restricted special page
	 */
	public function __construct() {
		$this->mAction = '';
		$this->mFilter = $this->mRegexFilter = '';
		$this->mError = $this->mMsg = '';
		parent::__construct( 'RegexBlock', 'regexblock' );
	}

	/**
	 * Under which header this special page is listed in Special:SpecialPages
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'users';
	}

	// @see https://phabricator.wikimedia.org/T123591
	public function doesWrites() {
		return true;
	}

	/**
	 * Show the special page
	 *
	 * @param string|null $subpage Parameter passed to the page, if any
	 */
	public function execute( $subpage ) {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		# If the user doesn't have the required 'regexblock' permission, display an error
		if ( !$user->isAllowed( 'regexblock' ) ) {
			throw new PermissionsError( 'regexblock' );
		}

		# Show a message if the database is in read-only mode
		$this->checkReadOnly();

		# If user is blocked, s/he doesn't need to access this page
		if ( $user->isBlocked() ) {
			throw new UserBlockedError( $user->getBlock() );
		}

		// Initial output
		$this->mTitle = $this->getPageTitle();

		$this->setHeaders();
		$out->setPageTitle( $this->msg( 'regexblock-page-title' ) );

		$this->mAction = $request->getVal( 'action' );
		$this->mFilter = $request->getVal( 'filter' );
		$this->mRegexFilter = $request->getVal( 'rfilter' );

		list( $this->mLimit, $this->mOffset ) = $request->getLimitOffset();

		$this->mRegexBlockedAddress = $this->mRegexBlockedExact = $this->mRegexBlockedCreation = $this->mRegexBlockedExpire = $this->mRegexBlockedReason = '';
		if ( $this->mAction == 'submit' ) {
			$this->mRegexBlockedAddress = htmlspecialchars( $request->getVal( 'wpRegexBlockedAddress', $request->getVal( 'ip' ) ) );
			$this->mRegexBlockedExact = $request->getInt( 'wpRegexBlockedExact' );
			$this->mRegexBlockedCreation = $request->getInt( 'wpRegexBlockedCreation' );
			$this->mRegexBlockedExpire = htmlspecialchars( $request->getVal( 'wpRegexBlockedExpire' ) );
			$this->mRegexBlockedReason = htmlspecialchars( $request->getVal( 'wpRegexBlockedReason' ) );
		}

		/* Actions */
		switch ( $this->mAction ) {
			case 'success_block':
				$out->setSubTitle( $this->msg( 'regexblock-block-success' ) );
				$this->mMsg = $this->msg( 'regexblock-block-log', $request->getVal( 'ip' ) )->parse();
				break;
			case 'success_unblock':
				$out->setSubTitle( $this->msg( 'regexblock-unblock-success' ) );
				$this->mMsg = $this->msg( 'regexblock-unblock-log', $request->getVal( 'ip' ) )->parse();
				break;
			case 'failure_unblock':
				$this->mError = $this->msg( 'regexblock-unblock-error', $request->getVal( 'ip' ) )->text();
				break;
			case 'stats':
				$blckid = $request->getVal( 'blckid' );
				$this->showStatsList( $blckid );
				break;
			case 'submit':
				if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
					$this->mAction = $this->doSubmit();
				}
				break;
			case 'delete':
				$this->deleteFromRegexBlockList();
				break;
		}

		if ( !in_array( $this->mAction, array( 'submit', 'stats' ) ) ) {
			$this->showForm();
			unset( $this->mError );
			unset( $this->mMsg );
			$this->showRegexList();
		}
	}

	/**
	 * Show the form for blocking IPs / users
	 */
	private function showForm() {
		$out = $this->getOutput();
		$request = $this->getRequest();

		$token = htmlspecialchars( $this->getUser()->getEditToken() );
		$action = htmlspecialchars( $this->mTitle->getLocalURL( array( 'action' => 'submit' ) + $this->makeListUrlParams() ), ENT_QUOTES );

		$expiries = SpecialBlock::getSuggestedDurations(); // RegexBlockData::getExpireValues();
		$regexBlockAddress = ( empty( $this->mRegexBlockedAddress ) && ( $request->getVal( 'ip' ) != null ) &&
			( $request->getVal( 'action' ) == null ) ) ? $request->getVal( 'ip' ) : $this->mRegexBlockedAddress;

		$tpl = new RegexBlockUITemplate;
		$tpl->setRef( 'class', $this );
		$tpl->setRef( 'out', $out );
		$tpl->set( 'msg', $this->mMsg );
		$tpl->set( 'action', $action );
		$tpl->set( 'regexBlockAddress', $regexBlockAddress );
		$tpl->set( 'mRegexBlockedExact', $this->mRegexBlockedExact );
		$tpl->set( 'mRegexBlockedCreation', $this->mRegexBlockedCreation );
		$tpl->set( 'err', $this->mError );
		$tpl->set( 'expiries', $expiries );
		$tpl->set( 'token', $token );

		// CSS & JS
		$out->addModuleStyles( 'ext.regexBlock.styles' );
		$out->addModules( 'mediawiki.userSuggest' );

		$out->addTemplate( $tpl );
	}

	/**
	 * Show the list of regex blocks - current and expired, along with some controls (unblock, statistics, etc.)
	 */
	private function showRegexList() {
		$out = $this->getOutput();

		$action = htmlspecialchars( $this->mTitle->getLocalURL( $this->makeListUrlParams() ), ENT_QUOTES );

		$regexData = new RegexBlockData();
		$lang = $this->getLanguage();
		$this->numResults = $regexData->fetchNbrResults();
		$pager = $lang->viewPrevNext(
			SpecialPage::getTitleFor( 'RegexBlock' ),
			$this->mOffset,
			$this->mLimit,
			array(
				'filter' => $this->mFilter,
				'rfilter' => $this->mRegexFilter
			),
			( $this->numResults - $this->mOffset ) <= $this->mLimit
		);

		/* allow display by specific blockers only */
		$blockers = $regexData->fetchBlockers();
		$blocker_list = array();
		if ( !empty( $blockers ) ) {
			$blocker_list = $regexData->getBlockersData( $this->mFilter, $this->mRegexFilter, $this->mLimit, $this->mOffset );
		}

		/* make link to statistics */
		$out->addHTML( '<br />
			<b>' . $this->msg( 'regexblock-currently-blocked' )->text() . '</b>
			<p>' . $pager . '</p>
			<form name="regexlist" method="get" action="' . $action . '">
				' . $this->msg( 'regexblock-view-blocked' )->text() . '
				<select name="filter">
					<option value="">' . $this->msg( 'regexblock-view-all' )->text() . '</option>' );

		if ( is_array( $blockers ) ) {
			foreach ( $blockers as $id => $blocker ) {
				$sel = htmlspecialchars( ( $this->mFilter == $blocker ) ) ? ' selected="selected"' : '';
				$out->addHTML( '<option value="' . htmlspecialchars( $blocker ) . '"' . $sel . '>' . htmlspecialchars( $blocker ) . '</option>' );
			}
		}

		$out->addHTML(
			'</select>&#160;' . $this->msg( 'regexblock-regex-filter' )->text() . $this->msg( 'word-separator' )->text() . '
				<input type="text" name="rfilter" id="regex_filter" value="' . $this->mRegexFilter . '" />
				<input type="submit" value="' . $this->msg( 'regexblock-view-go' )->text() . '" />
			</form>
			<br />
			<form name="regexbyid" method="get" action="' . $action . '">
				<input type="hidden" name="action" value="stats" />' .
				$this->msg( 'regexblock-view-block-id' )->text() .
				$this->msg( 'word-separator' )->text() .
				'<input type="text" name="blckid" id="blckid" value="" />
				<input type="submit" value="' . $this->msg( 'regexblock-view-go' )->text() . '" />
			</form>'
		);
		if ( !empty( $blockers ) ) {
			$out->addHTML( '<ul id="regexblock_blocks">' );
			$loop = 0;
			$comma = ' <b>&#183;</b> '; // the spaces here are intentional
			foreach ( $blocker_list as $id => $row ) {
				$loop++;
				$color_expire = '%s';
				if ( $row['expiry'] == 'infinite' ) {
					$row['expiry'] = $this->msg( 'regexblock-view-block-infinite' )->text();
				} else {
					if ( wfTimestampNow() > $row['expiry'] ) {
						$color_expire = '<span class="regexblock-expired">%s</span>';
					}
					$row['expiry'] = sprintf( $color_expire, $lang->timeanddate( wfTimestamp( TS_MW, $row['expiry'] ), true ) );
				}

				$exact_match = ( ( $row['exact_match'] ) ? $this->msg( 'regexblock-view-match' )->text() : $this->msg( 'regexblock-view-regex' )->text() );
				$create_block = ( $row['create_block'] ) ? $this->msg( 'regexblock-view-account' )->text() : '';
				$reason = '<i>' . $row['reason'] . '</i>';
				$stats_link = Linker::linkKnown(
					$this->mTitle,
					$this->msg( 'regexblock-view-stats' ),
					array(),
					array( 'action' => 'stats', 'blckid' => $row['blckid'] )
				);
				$space = $this->msg( 'word-separator' )->text();
				$unblock_link = Linker::linkKnown(
					$this->mTitle,
					$this->msg( 'regexblock-view-block-unblock' )->text(),
					array(),
					array(
						'action' => 'delete',
						'ip' => $row['ublock_ip'],
						'blocker' => $row['ublock_blocker']
					) + $this->makeListUrlParams()
				);

				$out->addHTML(
					'<li>
					<b><span class="regexblock-target">' . $row['blckby_name'] . '</span>' . $comma . $exact_match . $space . $create_block . '</b>' . $comma . '
					(' . $this->msg( 'regexblock-view-block-by' ) . ' <b>' . $row['blocker'] . '</b>, ' . $reason . ') ' .
					 $this->msg( 'regexblock-view-time', $row['datim'], $row['date'], $row['time'] )->text() . $comma .
					'(' . $unblock_link . ') ' . $comma . $row['expiry'] . $comma . ' (' . $stats_link . ')
					</li>'
				);
			}
			$out->addHTML( '</ul><br /><p>' . $pager . '</p>' );
		} else {
			$out->addWikiMsg( 'regexblock-view-empty' );
		}
	}

	/**
	 * @param bool $noLimit Skip adding limit and offset params?
	 * @return array
	 */
	private function makeListUrlParams( $noLimit = false ) {
		$request = $this->getRequest();
		$pieces = array();
		if ( !$noLimit ) {
			$pieces['limit'] = $this->mLimit;
			$pieces['offset'] = $this->mOffset;
		}
		$pieces['filter'] = $request->getVal( 'filter' );
		$pieces['rfilter'] = $request->getVal( 'rfilter' );

		return $pieces;
	}

	/* On submit */
	private function doSubmit() {
		/* empty name */
		if ( strlen( $this->mRegexBlockedAddress ) == 0 ) {
			$this->mError = $this->msg( 'regexblock-form-submit-empty' )->text();
			return false;
		}

		/* castrate regexes */
		if ( RegexBlockData::isValidRegex( $this->mRegexBlockedAddress ) ) {
			$this->mError = $this->msg( 'regexblock-form-submit-regex' )->text();
			return false;
		}

		/* check expiry */
		if ( strlen( $this->mRegexBlockedExpire ) == 0 ) {
			$this->mError = $this->msg( 'regexblock-form-submit-expiry' )->text();
			return false;
		}

		if ( $this->mRegexBlockedExpire != 'infinite' ) {
			$expiry = strtotime( $this->mRegexBlockedExpire );
			if ( $expiry < 0 || $expiry === false ) {
				$this->mError = $this->msg( 'ipb_expiry_invalid' )->text();
				return false;
			}
			$expiry = wfTimestamp( TS_MW, $expiry );
		} else {
			$expiry = $this->mRegexBlockedExpire;
		}

		$result = RegexBlockData::blockUser(
			$this->mRegexBlockedAddress,
			$expiry,
			$this->mRegexBlockedExact,
			$this->mRegexBlockedCreation,
			$this->mRegexBlockedReason
		);
		/* clear memcached */
		RegexBlock::unsetKeys( $this->mRegexBlockedAddress );

		/* redirect */
		$this->getOutput()->redirect( $this->mTitle->getFullURL( array(
			'action' => 'success_block',
			'ip' => $this->mRegexBlockedAddress
		) + $this->makeListUrlParams() ) );

		return;
	}

	/**
	 * Remove name or address from list - without confirmation
	 */
	private function deleteFromRegexBlockList() {
		$request = $this->getRequest();

		$ip = $request->getVal( 'ip' );
		$blocker = $request->getVal( 'blocker' );

		$result = RegexBlock::clearExpired( $ip, $blocker );

		if ( $result === true ) {
			$this->getOutput()->redirect( $this->mTitle->getFullURL( array(
				'action' => 'success_unblock',
				'ip' => $ip
			) + $this->makeListUrlParams() ) );
		} else {
			$this->getOutput()->redirect( $this->mTitle->getFullURL( array(
				'action' => 'failure_unblock',
				'ip' => $ip
			) + $this->makeListUrlParams() ) );
		}

		return;
	}

	/**
	 * Display some statistics when a user clicks stats link (&action=stats)
	 *
	 * @param int $blckid ID number of the block
	 */
	private function showStatsList( $blckid ) {
		$out = $this->getOutput();

		$action = htmlspecialchars( $this->mTitle->getLocalURL( $this->makeListUrlParams( true ) ), ENT_QUOTES );

		$regexData = new RegexBlockData();
		$lang = $this->getLanguage();
		$this->numStatResults = $regexData->fetchNbrStatResults( $blckid );
		$pager = $lang->viewPrevNext(
			SpecialPage::getTitleFor( 'RegexBlock' ),
			$this->mOffset,
			$this->mLimit,
			array(
				'action' => 'stats',
				'filter' => $this->mFilter,
				'blckid' => $blckid
			),
			( $this->numStatResults - $this->mOffset ) <= $this->mLimit
		);

		/* allow display by specific blockers only */
		$blockInfo = $regexData->getRegexBlockById( $blckid );
		$stats_list = array();
		if ( !empty( $blockInfo ) && ( is_object( $blockInfo ) ) ) {
			$stats_list = $regexData->getStatsData( $blckid, $this->mLimit, $this->mOffset );
		}

		$blocker_link = Linker::linkKnown( $this->mTitle, $blockInfo->blckby_blocker, array(), array( 'filter' => $blockInfo->blckby_blocker ) );
		$blockername_link = Linker::linkKnown( $this->mTitle, $blockInfo->blckby_name, array(), array( 'rfilter' => $blockInfo->blckby_name ) );
		if ( $blockInfo->blckby_reason ) {
			$blockReason = $this->msg( 'regexblock-form-reason' ) . $blockInfo->blckby_reason;
		} else {
			$blockReason = $this->msg( 'regexblock-view-reason-default' )->text();
		}

		$out->addHTML(
			'<h5>' . $this->msg( 'regexblock-stats-title' )->text() . ' <strong> ' .
			$blockername_link . '</strong> (' . $this->msg( 'regexblock-view-block-by' )->text() .
			' <b>' . $blocker_link . '</b>,&#160;<i>' . $blockReason . '</i>)</h5><br />'
		);

		if ( !empty( $stats_list ) ) {
			$out->addHTML( '<p>' . $pager . '</p><br /><ul id="regexblock_triggers">' );
			foreach ( $stats_list as $id => $row ) {
				$out->addHTML(
					'<li>' .
					$this->msg( 'regexblock-match-stats-record',
						array(
							$row->stats_match,
							$row->stats_user,
							htmlspecialchars( $row->stats_dbname ),
							$lang->timeanddate( wfTimestamp( TS_MW, $row->stats_timestamp ), true ),
							$row->stats_ip,
							$lang->date( wfTimestamp( TS_MW, $row->stats_timestamp ), true ),
							$lang->time( wfTimestamp( TS_MW, $row->stats_timestamp ), true )
						)
					)->text() .
					'</li>'
				);
			}
			$unblockLink = Linker::linkKnown(
				$this->mTitle,
				$this->msg( 'regexblock-view-block-unblock' ),
				array(),
				array( 'action' => 'delete', 'blckid' => $blockInfo->blckby_id )
			);
			$out->addHTML( '</ul>' . $unblockLink . '<br />
				<p>' . $pager . '</p>' );
		} else {
			$out->addWikiMsg( 'regexblock-nodata-found' );
		}
	}
}
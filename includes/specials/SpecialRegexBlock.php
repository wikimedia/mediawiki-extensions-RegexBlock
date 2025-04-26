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
 * @license GPL-2.0-or-later
 * @note This file heavily reuses GPL-licensed code from MediaWiki core special
 *       page Special:Block (/includes/specials/SpecialBlock.php).
 */

use MediaWiki\Block\AbstractBlock;
use MediaWiki\Block\BlockUser;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
// not in MW 1.43, namespaceization happened only in 1.44
// use MediaWiki\Logging\LogEventsList;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserNamePrefixSearch;
use MediaWiki\User\UserNameUtils;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\Widget;
use Wikimedia\IPUtils;

class SpecialRegexBlock extends FormSpecialPage {
	private UserNameUtils $userNameUtils;
	private UserNamePrefixSearch $userNamePrefixSearch;

	public $numResults = 0;
	public $numStatResults = 0;
	public $mAction;
	public $mFilter;
	public $mRegexFilter;
	public $mLimit;
	public $mOffset;

	/**
	 * @var User|string|null User to be blocked, as passed either by parameter (url?wpTarget=Foo)
	 * or as subpage (Special:Block/Foo)
	 */
	public $target;

	/** @var int AbstractBlock::TYPE_ constant */
	protected $type;

	/** @var User|string The previous block target */
	protected $previousTarget;

	/** @var bool */
	protected $alreadyBlocked;

	/** @var array */
	protected $preErrors = [];

	/**
	 * Constructor -- set up the new, restricted special page
	 */
	public function __construct(
		UserNameUtils $userNameUtils,
		UserNamePrefixSearch $userNamePrefixSearch
	) {
		$this->mAction = '';
		$this->mFilter = $this->mRegexFilter = '';
		parent::__construct( 'RegexBlock', 'regexblock' );

		$this->userNameUtils = $userNameUtils;
		$this->userNamePrefixSearch = $userNamePrefixSearch;
	}

	/**
	 * Under which header this special page is listed in Special:SpecialPages
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'users';
	}

	/**
	 * @see https://phabricator.wikimedia.org/T123591
	 *
	 * @return bool true
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * Show the special page
	 *
	 * @param string|null $par Parameter passed to the page, if any
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		$this->setParameter( $par );
		$this->setHeaders();

		// This will throw exceptions if there's a problem
		$this->checkExecutePermissions( $user );

		// Initial output
		$this->mTitle = $this->getPageTitle();

		// Our custom CSS
		$out->addModuleStyles( 'ext.regexBlock.styles' );

		$out->setPageTitleMsg( $this->msg( 'regexblock-page-title' ) );

		$this->mAction = $request->getVal( 'action' );
		$this->mFilter = $request->getVal( 'filter' );
		$this->mRegexFilter = $request->getVal( 'rfilter' );

		[ $this->mLimit, $this->mOffset ] = $request->getLimitOffsetForUser( $user );

		/* Actions */
		switch ( $this->mAction ) {
			case 'success_block':
				$out->setSubtitle( $this->msg( 'regexblock-block-success' ) );
				$out->wrapWikiMsg( '<div class="successbox">$1</div><br />' . "\n", [ 'regexblock-block-log', urldecode( $request->getVal( 'ip' ) ) ] );
				break;
			case 'success_unblock':
				$out->setSubtitle( $this->msg( 'regexblock-unblock-success' ) );
				$out->wrapWikiMsg( '<div class="successbox">$1</div><br />' . "\n", [ 'regexblock-unblock-log', urldecode( $request->getVal( 'ip' ) ) ] );
				break;
			case 'failure_unblock':
				$out->wrapWikiMsg( '<div class="errorbox">$1</div><br />' . "\n", [ 'regexblock-unblock-error', urldecode( $request->getVal( 'ip' ) ) ] );
				break;
			case 'stats':
				$blckid = $request->getVal( 'blckid' );
				$this->showStatsList( $blckid );
				break;
			case 'delete':
				$this->deleteFromRegexBlockList();
				break;
		}

		if ( !in_array( $this->mAction, [ 'submit', 'stats' ] ) ) {
			$form = $this->getForm();
			if ( $form->show() ) {
				$this->onSuccess();
			}
			$this->showRegexList();
		}
	}

	/**
	 * Show the list of regex blocks - current and expired, along with some controls (unblock, statistics, etc.)
	 */
	private function showRegexList() {
		$out = $this->getOutput();

		$action = htmlspecialchars( $this->getPageTitle()->getFullURL( $this->makeListUrlParams() ), ENT_QUOTES );

		$regexData = new RegexBlockData();
		$lang = $this->getLanguage();
		$this->numResults = $regexData->fetchNbrResults();
		$pager = $this->buildPrevNextNavigation(
			$this->mOffset,
			$this->mLimit,
			[
				'filter' => $this->mFilter,
				'rfilter' => $this->mRegexFilter
			],
			( $this->numResults - $this->mOffset ) <= $this->mLimit
		);

		/* allow display by specific blockers only */
		$blockers = $regexData->fetchBlockers();
		$blocker_list = [];
		if ( !empty( $blockers ) ) {
			$blocker_list = $regexData->getBlockersData( $this->mFilter, $this->mRegexFilter, $this->mLimit, $this->mOffset );
		}

		/* make link to statistics */
		$out->addHTML( '<br />
			<b>' . $this->msg( 'regexblock-currently-blocked' )->escaped() . '</b>
			<p>' . $pager . '</p>
			<form name="regexlist" method="get" action="' . $action . '">
				' . $this->msg( 'regexblock-view-blocked' )->escaped() . '
				<select name="filter">
					<option value="">' . $this->msg( 'regexblock-view-all' )->escaped() . '</option>'
		);

		if ( is_array( $blockers ) ) {
			foreach ( $blockers as $id => $blocker ) {
				$sel = htmlspecialchars( ( $this->mFilter == $blocker ) ) ? ' selected="selected"' : '';
				$out->addHTML( '<option value="' . htmlspecialchars( $blocker ) . '"' . $sel . '>' . htmlspecialchars( $blocker ) . '</option>' );
			}
		}

		$out->addHTML(
			'</select>&#160;' . $this->msg( 'regexblock-regex-filter' )->escaped() . $this->msg( 'word-separator' )->escaped() .
				Html::hidden( 'title', $this->getPageTitle() ) .
				Html::input( 'rfilter', $this->mRegexFilter, 'text', [ 'id' => 'regex_filter' ] ) .
				'<input type="submit" value="' . $this->msg( 'regexblock-view-go' )->escaped() . '" />
			</form>
			<br />
			<form name="regexbyid" method="get" action="' . $action . '">' .
				Html::hidden( 'title', $this->getPageTitle() ) .
				Html::hidden( 'action', 'stats' ) .
				$this->msg( 'regexblock-view-block-id' )->escaped() .
				$this->msg( 'word-separator' )->escaped() .
				'<input type="text" name="blckid" id="blckid" value="" />
				<input type="submit" value="' . $this->msg( 'regexblock-view-go' )->escaped() . '" />
			</form>'
		);

		if ( !empty( $blockers ) ) {
			$out->addHTML( '<ul id="regexblock_blocks">' );
			$loop = 0;
			$comma = ' <b>&#183;</b> '; // the spaces here are intentional
			$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			$linkRenderer = $this->getLinkRenderer();

			foreach ( $blocker_list as $id => $row ) {
				$loop++;
				$color_expire = '%s';
				$expiry = $dbr->decodeExpiry( $row['expiry'] );
				if ( $expiry == 'infinity' ) {
					$expiry = $this->msg( 'regexblock-view-block-infinite' )->escaped();
				} else {
					if ( wfTimestampNow() > $expiry ) {
						$color_expire = '<span class="regexblock-expired">%s</span>';
					}
					$expiry = sprintf( $color_expire, $lang->timeanddate( wfTimestamp( TS_MW, $expiry ), true ) );
				}

				$exact_match = ( ( $row['exact_match'] ) ? $this->msg( 'regexblock-view-match' )->escaped() : $this->msg( 'regexblock-view-regex' )->escaped() );
				$create_block = ( $row['create_block'] ) ? $this->msg( 'regexblock-view-account' )->escaped() : '';
				$reason = '<i>' . $row['reason'] . '</i>';
				$stats_link = $linkRenderer->makeKnownLink(
					$this->mTitle,
					$this->msg( 'regexblock-view-stats' )->text(),
					[],
					[ 'action' => 'stats', 'blckid' => $row['blckid'] ]
				);
				$space = $this->msg( 'word-separator' )->text();
				$unblock_link = $linkRenderer->makeKnownLink(
					$this->mTitle,
					$this->msg( 'regexblock-view-block-unblock' )->text(),
					[],
					[
						'action' => 'delete',
						'ip' => $row['ublock_ip'],
						'blocker' => $row['ublock_blocker']
					] + $this->makeListUrlParams()
				);

				$out->addHTML(
					'<li>
					<code class="regexblock-target">' . $row['blckby_name'] . '</code><b>' . $comma . $exact_match . $space . $create_block . '</b>' . $comma . '
					(' . $this->msg( 'regexblock-view-block-by' )->escaped() . ' <b>' . $row['blocker'] . '</b>, ' . $reason . ') ' .
					 $this->msg( 'regexblock-view-time', $row['datim'], $row['date'], $row['time'] )->parse() . $comma .
					'(' . $unblock_link . ') ' . $comma . $expiry . $comma . ' (' . $stats_link . ')
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
		$pieces = [];
		if ( !$noLimit ) {
			$pieces['limit'] = $this->mLimit;
			$pieces['offset'] = $this->mOffset;
		}
		$pieces['filter'] = $request->getVal( 'filter' );
		$pieces['rfilter'] = $request->getVal( 'rfilter' );

		return $pieces;
	}

	/**
	 * Remove name or address from list - without confirmation
	 */
	private function deleteFromRegexBlockList() {
		$request = $this->getRequest();

		$ip = urldecode( $request->getVal( 'ip' ) );

		$result = RegexBlock::removeBlock( $ip );

		if ( $result === true ) {
			$this->getOutput()->redirect( $this->mTitle->getFullURL( [
				'action' => 'success_unblock',
				'ip' => $ip
			] + $this->makeListUrlParams() ) );
		} else {
			$this->getOutput()->redirect( $this->mTitle->getFullURL( [
				'action' => 'failure_unblock',
				'ip' => $ip
			] + $this->makeListUrlParams() ) );
		}
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
		$pager = $this->buildPrevNextNavigation(
			$this->mOffset,
			$this->mLimit,
			[
				'action' => 'stats',
				'filter' => $this->mFilter,
				'blckid' => $blckid
			],
			( $this->numStatResults - $this->mOffset ) <= $this->mLimit
		);

		/* allow display by specific blockers only */
		$blockInfo = $regexData->getRegexBlockById( $blckid );
		// @todo FIXME: does not display the blocked expression, blocker name or reason properly (yet)
		// $blockInfo = RegularExpressionDatabaseBlock::newFromID( $blckid );
		$stats_list = [];
		if ( !empty( $blockInfo ) && ( is_object( $blockInfo ) ) ) {
			$stats_list = $regexData->getStatsData( $blckid, $this->mLimit, $this->mOffset );
		}

		$linkRenderer = $this->getLinkRenderer();
		$blocker_link = $blockername_link = '';
		if ( isset( $blockInfo->blckby_blocker ) && $blockInfo->blckby_blocker ) {
			$blocker_link = $linkRenderer->makeKnownLink( $this->mTitle, $blockInfo->blckby_blocker, [], [ 'filter' => $blockInfo->blckby_blocker ] );
		}
		if ( isset( $blockInfo->blckby_name ) && $blockInfo->blckby_name ) {
			$blockername_link = $linkRenderer->makeKnownLink( $this->mTitle, $blockInfo->blckby_name, [], [ 'rfilter' => $blockInfo->blckby_name ] );
		}
		if ( isset( $blockInfo->blckby_reason ) && $blockInfo->blckby_reason ) {
			$blockReason = $this->msg( 'regexblock-form-reason' )->escaped() . $blockInfo->blckby_reason;
		} else {
			$blockReason = $this->msg( 'regexblock-view-reason-default' )->escaped();
		}

		$out->addHTML(
			'<h5>' . $this->msg( 'regexblock-stats-title' )->escaped() . ' <strong> ' .
			$blockername_link . '</strong> (' . $this->msg( 'regexblock-view-block-by' )->escaped() .
			' <b>' . $blocker_link . '</b>,&#160;<i>' . $blockReason . '</i>)</h5><br />'
		);

		if ( !empty( $stats_list ) ) {
			$out->addHTML( '<p>' . $pager . '</p><br /><ul id="regexblock_triggers">' );

			$messageKey = 'regexblock-match-stats-record';

			$hookContainer = $this->getHookContainer();
			foreach ( $stats_list as $id => $row ) {
				$params = [
					$row->stats_match,
					$row->stats_user,
					htmlspecialchars( $row->stats_dbname ),
					$lang->timeanddate( wfTimestamp( TS_MW, $row->stats_timestamp ), true ),
					$row->stats_ip,
					$lang->date( wfTimestamp( TS_MW, $row->stats_timestamp ), true ),
					$lang->time( wfTimestamp( TS_MW, $row->stats_timestamp ), true )
				];

				// Run a hook to allow altering the display of an individual entry
				// This is mainly because by default the database name ($row->stats_dbname)
				// is used in the message, but on ShoutWiki we want to change that to a
				// clickable link as well as change the displayed text from a DB name to
				// the wiki's site name
				$hookContainer->run( 'RegexBlockShowStatsListFormatRecord', [ &$messageKey, &$params, $row ] );

				$out->addHTML(
					'<li>' .
					$this->msg( $messageKey, $params )->text() .
					'</li>'
				);
			}

			$unblockLink = $linkRenderer->makeKnownLink(
				$this->mTitle,
				$this->msg( 'regexblock-view-block-unblock' )->text(),
				[],
				[ 'action' => 'delete', 'blckid' => $blockInfo->blckby_id ]
			);

			$out->addHTML( '</ul>' . $unblockLink . '<br />
				<p>' . $pager . '</p>' );
		} else {
			$out->addWikiMsg( 'regexblock-nodata-found' );
		}
	}

	/**
	 * Handle some magic here
	 *
	 * @param string $par
	 */
	protected function setParameter( $par ) {
		# Extract variables from the request. Try not to get into a situation where we
		# need to extract *every* variable from the form just for processing here, but
		# there are legitimate uses for some variables
		$request = $this->getRequest();
		[ $this->target, $this->type ] = self::getTargetAndType( $par, $request );
		if ( $this->target instanceof User ) {
			# Set the 'relevant user' in the skin, so it displays links like Contributions,
			# User logs, UserRights, etc.
			$this->getSkin()->setRelevantUser( $this->target );
		}

		[ $this->previousTarget, /*...*/ ] =
			RegularExpressionDatabaseBlock::parseTarget( $request->getVal( 'wpPreviousTarget' ) );
	}

	/**
	 * Customizes the HTMLForm a bit
	 *
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		$form->setWrapperLegendMsg( 'block' );
		$form->setHeaderHtml( '' );
		$form->setSubmitDestructive();

		$form->setSubmitTextMsg( 'regexblock-form-submit' );

		$this->addHelpLink( 'Help:Blocking users' );

		// Don't need to do anything if the form has been posted
		if ( !$this->getRequest()->wasPosted() && $this->preErrors ) {
			// Mimic error messages normally generated by the form
			$form->addHeaderHtml( (string)new FieldLayout(
				new Widget( [] ),
				[
					'align' => 'top',
					'errors' => array_map( function ( $errMsg ) {
						return new HtmlSnippet( $this->msg( $errMsg )->parse() );
					}, $this->preErrors ),
				]
			) );
		}
	}

	/** @inheritDoc */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * Get the HTMLForm descriptor array for the block form
	 * @return array
	 */
	protected function getFormFields() {
		$suggestedDurations = self::getSuggestedDurations();

		$a = [
			'Target' => [ // @note Formerly called wpRegexBlockedAddress
				'type' => 'user',
				'label-message' => 'regexblock-form-username', // 'ipaddressorusername',
				'id' => 'mw-bi-target',
				'size' => '45',
				'autofocus' => true,
				'required' => true,
				'validation-callback' => [ __CLASS__, 'validateTargetField' ],
			],
			'Expiry' => [
				'type' => !count( $suggestedDurations ) ? 'text' : 'selectorother',
				'label-message' => 'block-expiry',
				'required' => true,
				'options' => $suggestedDurations,
				'other' => $this->msg( 'ipbother' )->text(),
				'default' => $this->msg( 'ipb-default-expiry' )->inContentLanguage()->text(),
			],
			'Reason' => [
				'type' => 'selectandother',
				'maxlength' => 255,
				'label-message' => 'ipbreason',
				'options-message' => 'ipbreason-dropdown',
			],
			/*'CreateAccount'*/'RegexBlockedCreation' => [
				'type' => 'check',
				'label-message' => 'regexblock-form-account-block', // 'ipbcreateaccount',
				'default' => true,
			],
			'RegexBlockedExact' => [
				'type' => 'check',
				'label-message' => 'regexblock-form-match',
				'default' => false
			]
		];

		# This is basically a copy of the Target field, but the user can't change it, so we
		# can see if the warnings we maybe showed to the user before still apply
		$a['PreviousTarget'] = [
			'type' => 'hidden',
			'default' => false,
		];

		$this->maybeAlterFormDefaults( $a );

		return $a;
	}

	/**
	 * If the user has already been blocked with similar settings, load that block
	 * and change the defaults for the form fields to match the existing settings.
	 * @param array &$fields HTMLForm descriptor array
	 * @return bool Whether fields were altered (that is, whether the target is
	 *     already blocked)
	 */
	protected function maybeAlterFormDefaults( &$fields ) {
		# This will be overwritten by request data
		$fields['Target']['default'] = (string)$this->target;

		if ( $this->target ) {
			if ( !RegexBlockData::isValidRegex( (string)$this->target ) ) {
				$this->preErrors = array_merge( $this->preErrors, [ 'regexblock-form-submit-regex' ] );
			}
			/*
			$status = MediaWiki\Block\BlockUtils::validateTarget( $this->target, $this->getUser() );
			if ( !$status->isOK() ) {
				$errors = $status->getErrorsArray();
				$this->preErrors = array_merge( $this->preErrors, $errors );
			}
			*/
		}

		# This won't be
		$fields['PreviousTarget']['default'] = (string)$this->target;
	}

	/**
	 * Add header elements like help text, etc.
	 * @return string
	 */
	protected function preHtml() {
		$this->getOutput()->addModules( [
			// 'mediawiki.special.block'
			// Gotta use a custom fork instead, the core module (as of MW 1.43.0) throws some exception
			// about "Widget not found" which seems to originate from the core code being changed between
			// MW 1.35 and 1.43.0 :-(
			'ext.regexBlock.scripts'
		] );

		$blockCIDRLimit = $this->getConfig()->get( 'BlockCIDRLimit' );
		// @note Originally used 'blockiptext'
		// @todo FIXME: (eventually) use the passed params in the i18n msg for realz
		$text = $this->msg( 'regexblock-help', $blockCIDRLimit['IPv4'], $blockCIDRLimit['IPv6'] )->parse();

		return $text;
	}

	/**
	 * Add footer elements to the form
	 * @return string
	 */
	protected function postHtml() {
		$links = [];

		$this->getOutput()->addModuleStyles( 'mediawiki.special' );

		$linkRenderer = $this->getLinkRenderer();

		# Link to the user's contributions, if applicable
		if ( $this->target instanceof User ) {
			$contribsPage = SpecialPage::getTitleFor( 'Contributions', $this->target->getName() );
			$links[] = $linkRenderer->makeLink(
				$contribsPage,
				$this->msg( 'ipb-blocklist-contribs', $this->target->getName() )->text()
			);
		}

		$user = $this->getUser();

		# Link to edit the block dropdown reasons, if applicable
		if ( $user->isAllowed( 'editinterface' ) ) {
			$links[] = $linkRenderer->makeKnownLink(
				$this->msg( 'ipbreason-dropdown' )->inContentLanguage()->getTitle(),
				$this->msg( 'ipb-edit-dropdown' )->text(),
				[],
				[ 'action' => 'edit' ]
			);
		}

		// A hook point for ShoutWiki
		$this->getHookContainer()->run( 'RegexBlockFormPostText', [ &$links, $this ] );

		$text = Html::rawElement(
			'p',
			[ 'class' => 'mw-ipb-conveniencelinks' ],
			$this->getLanguage()->pipeList( $links )
		);

		$userTitle = self::getTargetUserTitle( $this->target );
		if ( $userTitle ) {
			# Get relevant extracts from the block and suppression logs, if possible
			$out = '';

			LogEventsList::showLogExtract(
				$out,
				'block',
				$userTitle,
				'',
				[
					'lim' => 10,
					'msgKey' => [ 'blocklog-showlog', $userTitle->getText() ],
					'showIfEmpty' => false
				]
			);
			$text .= $out;
		}

		return $text;
	}

	/**
	 * Get a user page target for things like logs.
	 * This handles account and IP range targets.
	 * @param User|string $target
	 * @return Title|null
	 */
	protected static function getTargetUserTitle( $target ) {
		if ( $target instanceof User ) {
			return $target->getUserPage();
		} elseif ( IPUtils::isIPAddress( $target ) ) {
			return Title::makeTitleSafe( NS_USER, $target );
		}

		return null;
	}

	/**
	 * Determine the target of the block, and the type of target
	 *
	 * @param string $par Subpage parameter passed to setup, or data value from
	 *     the HTMLForm
	 * @param WebRequest|null $request Optionally try and get data from a request too
	 * @return array( User|string|null, AbstractBlock::TYPE_ constant|null )
	 */
	public static function getTargetAndType( $par, ?WebRequest $request = null ) {
		$i = 0;
		$target = null;

		while ( true ) {
			switch ( $i++ ) {
				case 0:
					# The HTMLForm will check wpTarget first and only if it doesn't get
					# a value use the default, which will be generated from the options
					# below; so this has to have a higher precedence here than $par, or
					# we could end up with different values in $this->target and the HTMLForm!
					if ( $request instanceof WebRequest ) {
						$target = $request->getVal( 'wpTarget' );
					}
					break;
				case 1:
					$target = $par;
					break;
				case 2:
					if ( $request instanceof WebRequest ) {
						$target = $request->getVal( 'ip' );
					}
					break;
				case 3:
					# B/C @since 1.18
					if ( $request instanceof WebRequest ) {
						$target = $request->getVal( 'wpBlockAddress' );
					}
					break;
				case 4:
					break 2;
			}

			[ $target, $type ] = RegularExpressionDatabaseBlock::parseTarget( $target );

			if ( $type !== null ) {
				return [ $target, $type ];
			}
		}

		return [ null, null ];
	}

	/**
	 * HTMLForm field validation callback for Target field.
	 *
	 * @param string $value User-supplied value to check for validity
	 * @param array $alldata
	 * @param HTMLForm $form
	 * @return bool|Message
	 */
	public static function validateTargetField( $value, $alldata, $form ) {
		if ( RegexBlockData::isValidRegex( $value ) ) {
			// valid regex is all it takes [[for now]]...
			return true;
		} else {
			$errors = [];
			$errors[0] = [ 'regexblock-form-submit-regex' ];
			return call_user_func_array( [ $form, 'msg' ], $errors[0] );
		}
	}

	/**
	 * Given the form data, actually implement a block. This is also called from ApiRegexBlock.
	 *
	 * @param array $data
	 * @param IContextSource $context
	 * @return bool|string
	 */
	public static function processForm( array $data, IContextSource $context ) {
		$performer = $context->getUser();

		// Handled by field validator callback
		// self::validateTargetField( $data['Target'] );

		/** @var User $target */
		[ $target, $type ] = self::getTargetAndType( $data['Target'] );
		if ( $type == AbstractBlock::TYPE_USER ) {
			$user = $target;
			$target = $user->getName();
			$userId = $user->getId();

			# Give admins a heads-up before they go and block themselves. Much messier
			# to do this for IPs, but it's pretty unlikely they'd ever get the 'block'
			# permission anyway, although the code does allow for it.
			# Note: Important to use $target instead of $data['Target']
			# since both $data['PreviousTarget'] and $target are normalized
			# but $data['target'] gets overridden by (non-normalized) request variable
			# from previous request.
			if ( $target === $performer->getName() &&
				( $data['PreviousTarget'] !== $target )
			) {
				return [ 'ipb-blockingself', 'ipb-confirmaction' ];
			}
		} elseif ( $type == AbstractBlock::TYPE_RANGE ) {
			$user = null;
			$userId = 0;
		} elseif ( $type == AbstractBlock::TYPE_IP ) {
			$user = null;
			$target = $target->getName();
			$userId = 0;
		} elseif ( $type == 6 /* = our own identifier for regex-based blocks */ ) {
			// for RegexBlock assume that this case means that the target is
			// a regular expression, for which there is no AbstractBlock::TYPE_*
			// constant in MW core, obviously...
			$user = null;
			$userId = 0;
		} else {
			# This should have been caught in the form field validation
			return [ 'badipaddress' ];
		}

		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		// Reason, to be passed to the block object.
		$blockReason = $contLang->truncateForDatabase( $data['Reason'][0], 255 );

		$expiryTime = BlockUser::parseExpiryInput( $data['Expiry'] );

		if (
			// an expiry time is needed
			( strlen( $data['Expiry'] ) == 0 ) ||
			// can't be a larger string as 50 (it should be a time format in any way)
			( strlen( $data['Expiry'] ) > 50 ) ||
			// check, if the time could be parsed
			!$expiryTime
		) {
			return [ 'ipb_expiry_invalid' ];
		}

		// an expiry time should be in the future, not in the
		// past (wouldn't make any sense) - bug T123069
		if ( $expiryTime < wfTimestampNow() ) {
			return [ 'ipb_expiry_old' ];
		}

		# Create block object.
		$block = new RegularExpressionDatabaseBlock();
		$block->setTarget( $target );
		$block->setBlocker( $performer );
		$block->setReason( $blockReason );
		$block->setExpiry( $expiryTime );
		$block->isCreateAccountBlocked( $data['RegexBlockedCreation'] );

		$block->setExact( $data['RegexBlockedExact'] );

		// We currently don't care about conflicting blocks like how core SpecialBlock.php does.
		// Should we?
		$status = $block->insert();
		/* OLD (pre-1.35) CODE
		$result = RegexBlockData::blockUser(
			$target,
			$expiryTime,
			$data['RegexBlockedExact'],
			$data['RegexBlockedCreation'],
			# Truncate reason for whole multibyte characters
			$contLang->truncateForDatabase( $data['Reason'][0], 255 )
		);
		*/

		// clear memcached
		RegexBlock::unsetKeys( $target );

/*
		$logAction = 'block';

		# Prepare log parameters
		$logParams = [];
		$logParams['5::duration'] = $data['Expiry'];
		$logParams['6::flags'] = self::blockLogFlags( $data, $type );

		# Make log entry
		$logEntry = new ManualLogEntry( 'regexblock', $logAction );
		$logEntry->setTarget( Title::makeTitle( NS_USER, $target ) );
		$logEntry->setComment( $data['Reason'][0] );
		$logEntry->setPerformer( $performer );
		$logEntry->setParameters( $logParams );
		# Relate log ID to block IDs (bug 25763)
		$blockIds = array_merge( [ $status['id'] ], $status['autoIds'] );
		$logEntry->setRelations( [ 'ipb_id' => $blockIds ] );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId );
*/
		# Report to the user
		return true;
	}

	/**
	 * Get an array of suggested block durations from MediaWiki:Ipboptions
	 *
	 * @param Language|null $lang The language to get the durations in, or null to use
	 *     the wiki's content language
	 * @return array
	 */
	public static function getSuggestedDurations( $lang = null ) {
		$a = [];
		$msg = $lang === null
			? wfMessage( 'ipboptions' )->inContentLanguage()->text()
			: wfMessage( 'ipboptions' )->inLanguage( $lang )->text();

		if ( $msg == '-' ) {
			return [];
		}

		foreach ( explode( ',', $msg ) as $option ) {
			if ( strpos( $option, ':' ) === false ) {
				$option = "$option:$option";
			}

			[ $show, $value ] = explode( ':', $option );
			$a[$show] = $value;
		}

		return $a;
	}

	/**
	 * Return a comma-delimited list of "flags" to be passed to the log
	 * reader for this block, to provide more information in the logs
	 * @param array $data From HTMLForm data
	 * @param int $type Block::TYPE_ constant (USER, RANGE, or IP)
	 * @return string
	 */
	protected static function blockLogFlags( array $data, $type ) {
		$flags = [];

		if ( $data['RegexBlockedCreation'] ) {
			// For grepping: message block-log-flags-nocreate
			$flags[] = 'nocreate';
		}

		// For grepping: message block-log-flags-nousertalk
		$flags[] = 'nousertalk';

		return implode( ',', $flags );
	}

	/**
	 * Process the form on POST submission.
	 * @param array $data
	 * @param HTMLForm|null $form
	 * @return bool|array True for success, false for didn't-try, array of errors on failure
	 */
	public function onSubmit( array $data, ?HTMLForm $form = null ) {
		return self::processForm( $data, $form->getContext() );
	}

	/**
	 * Do something exciting on successful processing of the form, most likely to show a
	 * confirmation message
	 */
	public function onSuccess() {
		$out = $this->getOutput();
		$this->getOutput()->redirect( $this->getPageTitle()->getFullURL( [
			'action' => 'success_block',
			'ip' => $this->target
		] + $this->makeListUrlParams() ) );
	}

	/**
	 * Return an array of subpages beginning with $search that this special page will accept.
	 *
	 * @param string $search Prefix to search for
	 * @param int $limit Maximum number of results to return (usually 10)
	 * @param int $offset Number of results to skip (usually 0)
	 * @return string[] Matching subpages
	 */
	public function prefixSearchSubpages( $search, $limit, $offset ) {
		$search = $this->userNameUtils->getCanonical( $search );
		if ( !$user ) {
			// No prefix suggestion for invalid user
			return [];
		}
		// Autocomplete subpage as user list - public to allow caching
		return $this->userNamePrefixSearch
			->search( UserNamePrefixSearch::AUDIENCE_PUBLIC, $search, $limit, $offset );
	}
}

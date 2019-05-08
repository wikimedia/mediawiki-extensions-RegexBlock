<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Jack Phoenix
 * @date 7 March 2019
 * @note Based on GPL-licensed core /includes/api/ApiBlock.php file, which is copyright © 2007 Roan Kattouw
 */

/**
 * API module that facilitates the blocking of users via regular expressions (regex).
 * Requires API write mode to be enabled.
 *
 * @ingroup API
 */
class ApiRegexBlock extends ApiBase {

	use ApiBlockInfoTrait;

	/**
	 * Blocks the user specified in the parameters for the given expiry, with the
	 * given reason, and with all other settings provided in the params. If the block
	 * succeeds, produces a result containing the details of the block and notice
	 * of success. If it fails, the result will specify the nature of the error.
	 */
	public function execute() {
		$this->checkUserRightsAny( 'regexblock' );

		$user = $this->getUser();
		$params = $this->extractRequestParams();

		# T17810: blocked admins should have limited access here
		if ( $user->isBlocked() ) {
			$status = SpecialBlock::checkUnblockSelf( $params['regex'], $user );
			if ( $status !== true ) {
				$this->dieWithError(
					$status,
					null,
					[ 'blockinfo' => $this->getBlockInfo( $user->getBlock() ) ]
				);
			}
		}

		list( $target, $type ) = RegexBlockForm::getTargetAndType( $params['regex'] );

		// T40633 - if the target is a user (not an IP address), but it
		// doesn't exist or is unusable, error.
		if ( $type === Block::TYPE_USER &&
			( $target->isAnon() /* doesn't exist */ || !User::isUsableName( $params['regex'] ) )
		) {
			$this->dieWithError( [ 'nosuchusershort', $params['regex'] ], 'nosuchuser' );
		}

		$data = [
			'PreviousTarget' => $params['regex'],
			'Target' => $params['regex'],
			'Reason' => [
				$params['reason'],
				'other',
				$params['reason']
			],
			'Expiry' => $params['expiry'],
			'RegexBlockedCreation' => $params['nocreate'],
			'RegexBlockedExact' => $params['exact'],
		];

		$retval = RegexBlockForm::processForm( $data, $this->getContext() );
		if ( $retval !== true ) {
			$this->dieStatus( $this->errorArrayToStatus( $retval ) );
		}

		list( $target, /*...*/ ) = RegexBlockForm::getTargetAndType( $params['regex'] );
		$res['user'] = $params['regex'];
		$res['userID'] = $target instanceof User ? $target->getId() : 0;

		$res['expiry'] = ApiResult::formatExpiry( SpecialBlock::parseExpiryInput( $data['Expiry'] ), 'infinite' );
		// We don't have a clean way of getting it, *and* because there is no easy way to
		// set the block ID via the Block class, *and* my patch which introduces a setId()
		// method to the Block class is permanently stuck in code review limbo@upstream,
		// we'll just set this to an empty string. Sucks, but whatcha gonna do?
		$res['id'] = '';

		$res['reason'] = $params['reason'];

		$this->getResult()->addValue( null, $this->getModuleName(), $res );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'regex' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			],
			'expiry' => 'never',
			'reason' => '',
			'nocreate' => false,
			'exact' => false
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	protected function getExamplesMessages() {
		// phpcs:disable Generic.Files.LineLength
		return [
			'action=regexblock&regex=192.0.2.5&expiry=3%20days&reason=First%20strike&exact=&token=123ABC'
				=> 'apihelp-regexblock-example-ip-exact-simple',
			'action=regexblock&regex=SpamUser.*&expiry=never&reason=Bad%20username&nocreate=&token=123ABC'
				=> 'apihelp-regexblock-example-user-regex-complex',
		];
		// phpcs:enable
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Extension:RegexBlock/API';
	}
}

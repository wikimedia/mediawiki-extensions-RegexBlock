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
 * @note Based on GPL-licensed core /includes/api/ApiUnblock.php file, which is copyright © 2007 Roan Kattouw
 */

/**
 * API module that facilitates the unblocking of users via regular expressions.
 * Requires API write mode to be enabled.
 *
 * @ingroup API
 */
class ApiRegexUnblock extends ApiBase {

	use ApiBlockInfoTrait;

	/**
	 * Unblocks the specified regular expression or provides the reason the unblock failed.
	 */
	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();

		if ( !$user->isAllowed( 'regexblock' ) ) {
			$this->dieWithError( 'apierror-permissiondenied-unblock', 'permissiondenied' );
		}

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

		$regex = $params['regex'];
		// Quick sanity check before proceeding
		if ( !RegexBlockData::isValidRegex( $regex ) ) {
			$this->dieWithError(
				new RawMessage( 'The given expression is not a valid regular expression.' )
			);
		}

		$result = RegexBlock::removeBlock( $regex );

		$res = [];
		if ( $result === true ) {
			$res = [ 'status' => 'ok' ];
		} else {
			$res = [ 'status' => 'fail' ];
		}

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
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	protected function getExamplesMessages() {
		return [
			'action=regexunblock&regex=192.0.2.5'
				=> 'apihelp-regexunblock-example-ip',
			'action=regexunblock&regex=SpamUser.*'
				=> 'apihelp-regexunblock-example-regex',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:RegexBlock';
	}
}

<?php
/**
 * @file
 * @ingroup Templates
 */
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 1 );
}

/**
 * HTML template for Special:RegexBlock form
 * @ingroup Templates
 */
class RegexBlockUITemplate extends QuickTemplate {
	function execute() {
		$checkExact = htmlspecialchars( ( $this->data['mRegexBlockedExact'] ) ) ? ' checked="checked"' : '';
		$checkCreation = htmlspecialchars( ( $this->data['mRegexBlockedCreation'] ) ) ? ' checked="checked"' : '';

		$msg = '';
		if ( $this->data['err'] != '' ) {
			$this->data['out']->setSubtitle( $this->msg( 'formerror' ) );
			$msg = '<h2 class="errorbox">' . $this->data['err'] . '</h2>';
		} elseif ( $this->data['msg'] != '' ) {
			$msg = '<h2 class="successbox">' . $this->data['msg'] . '</h2>';
		}
?><div class="regexblock-msg"><?php echo $msg ?></div>
	<div class="regexblock-help"><?php echo wfMessage( 'regexblock-help' )->parse() ?></div>
	<fieldset class="regexblock-fieldset" align="center">
		<legend><?php echo wfMessage( 'regexblock-form-submit' )->escaped() ?></legend>
		<form name="regexblock" method="post" action="<?php echo $this->data['action'] ?>">
		<table>
			<tr>
				<td align="right"><?php echo wfMessage( 'regexblock-form-username' )->escaped() ?></td>
				<td align="left">
					<input tabindex="1" name="wpRegexBlockedAddress" id="wpRegexBlockedAddress" class="mw-autocomplete-user" size="40" value="<?php echo $this->data['regexBlockAddress'] ?>" />
				</td>
			</tr>
			<tr>
				<td align="right"><?php echo wfMessage( 'regexblock-form-reason' )->escaped() ?></td>
				<td align="left">
					<input tabindex="2" name="wpRegexBlockedReason" id="wpRegexBlockedReason" size="40" value="<?php echo $this->data['class']->mRegexBlockedReason ?>" />
				</td>
			</tr>
			<tr>
				<td align="right"><?php echo wfMessage( 'regexblock-form-expiry' )->escaped() ?></td>
				<td align="left">
				<select name="wpRegexBlockedExpire" id="wpRegexBlockedExpire" tabindex="3">
				<?php
				foreach ( $this->data['expiries'] as $k => $v ) {
					$selected = htmlspecialchars( ( $k == $this->data['class']->mRegexBlockedExpire ) ) ? ' selected="selected"' : '';
				?>
					<option value="<?php echo htmlspecialchars( $v ) ?>"<?php echo $selected ?>><?php echo htmlspecialchars( $v ) ?></option>
				<?php
				}
				?>
				</select>
			</td>
			</tr>
			<tr>
				<td align="right">&#160;</td>
				<td align="left">
					<input type="checkbox" tabindex="4" name="wpRegexBlockedExact" id="wpRegexBlockedExact" value="1"<?php echo $checkExact ?> />
					<label for="wpRegexBlockedExact"><?php echo wfMessage( 'regexblock-form-match' )->escaped() ?></label>
				</td>
			</tr>
			<tr>
				<td align="right">&#160;</td>
				<td align="left">
					<input type="checkbox" tabindex="5" name="wpRegexBlockedCreation" id="wpRegexBlockedCreation" value="1"<?php echo $checkCreation ?> />
					<label for="wpRegexBlockedCreation"><?php echo wfMessage( 'regexblock-form-account-block' )->escaped() ?></label>
				</td>
			</tr>
			<tr>
				<td align="right">&#160;</td>
				<td align="left">
					<input tabindex="6" name="wpRegexBlockedSubmit" type="submit" value="<?php echo wfMessage( 'regexblock-form-submit' )->escaped() ?>" />
				</td>
			</tr>
		</table>
		<input type="hidden" name="wpEditToken" value="<?php echo $this->data['token'] ?>" />
	</form>
	</fieldset>
	<br />
<?php
	}
}
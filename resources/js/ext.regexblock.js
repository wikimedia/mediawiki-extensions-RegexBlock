/*!
 * JavaScript for Special:RegexBlock
 *
 * An extremely stripped down version of <IP>/resources/src/mediawiki.special.block.js as of REL1_39 on 21 April 2025.
 *
 * Prior to the version of RegexBlock which requires MW 1.43(+), RegexBlock used to load the core
 * RL module used on Special:Block in RegexBlockForm#preHtml, but as of MW 1.43.0, everything's
 * changed enough that trying to load that module on Special:RegexBlock results in JS errors.
 * Hence why this module is a thing: this is only less than 30 lines of meaningful code, as opposed to
 * the core module, which has over 100 lines, most of which we do _not_ want.
 */
( function ( $ ) {
	// Like OO.ui.infuse(), but if the element doesn't exist, return null instead of throwing an exception.
	function infuseIfExists( $el ) {
		if ( !$el.length ) {
			return null;
		}
		return OO.ui.infuse( $el );
	}

	$( () => {
		let blockTargetWidget;

		// This code is also loaded on the "block succeeded" page where there is no form,
		// so check for block target widget; if it exists, the form is present
		blockTargetWidget = infuseIfExists( $( '#mw-bi-target' ) );

		if ( blockTargetWidget ) {
			// Always present if blockTargetWidget is present
			OO.ui.infuse( $( '#mw-input-wpExpiry' ) );
		}
	} );
}( jQuery ) );

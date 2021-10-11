<?php

use Wikimedia\TestingAccessWrapper;

/**
 * @covers RegexBlockForm
 * @group Database
 */
class SpecialRegexBlockTest extends MediaWikiIntegrationTestCase {

	public function provideShowRegexList() {
		yield [ 0, 20 ];
		yield [ 17, 20 ];
		yield [ 0, 17 ];
		yield [ 0, 20 ];
		yield [ 17, 20 ];
	}

	/**
	 * @dataProvider provideShowRegexList
	 */
	public function testShowRegexList( $offset, $limit ) {
		$context = new RequestContext();
		$regexBlock = new RegexBlockForm();
		$regexBlock = TestingAccessWrapper::newFromObject( $regexBlock );

		$context->setUser( $this->getTestUser( [ 'staff' ] )->getUser() );

		$regexBlock->setContext( $context );

		$regexBlock->mLimit = $limit;
		$regexBlock->mOffset = $offset;

		$regexBlock->showRegexList();

		$html = $regexBlock->getOutput()->getHTML();

		preg_match_all( '!<a.*?</a>!', $html, $m, PREG_PATTERN_ORDER );
		$links = $m[0];

		foreach ( $links as $a ) {
			$this->assertStringContainsString( 'Special:RegexBlock', $a );
			$this->assertStringNotContainsString( 'SSpecial:RegexBlock/', $a );
		}

		$i = 0;

		if ( $offset > 0 ) {
			$this->assertStringContainsString(
				'limit=' . $limit . '&amp;offset=' . max( 0, $offset - $limit ) . '&amp;',
				$links[ $i ]
			);
			$this->assertStringContainsString( 'class="mw-prevlink"', $links[$i] );
			$this->assertStringContainsString( '>previous ' . $limit . '<', $links[$i] );
			$i += 1;
		}

		$this->assertCount( 5 + $i, $links );

		$this->assertStringContainsString( 'limit=20&amp;offset=' . $offset, $links[$i] );
		$this->assertStringContainsString( 'title="Show 20 results per page"', $links[$i] );
		$this->assertStringContainsString( 'class="mw-numlink"', $links[$i] );
		$this->assertStringContainsString( '>20<', $links[$i] );
		$i += 4;

		$this->assertStringContainsString( 'limit=500&amp;offset=' . $offset, $links[$i] );
		$this->assertStringContainsString( 'title="Show 500 results per page"', $links[$i] );
		$this->assertStringContainsString( 'class="mw-numlink"', $links[$i] );
		$this->assertStringContainsString( '>500<', $links[$i] );
	}
}

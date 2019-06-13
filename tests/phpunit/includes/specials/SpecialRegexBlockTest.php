<?php

use Wikimedia\TestingAccessWrapper;

/**
 * @covers RegexBlockForm
 * @group Database
 */
class SpecialRegexBlockTest extends MediaWikiTestCase {

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
			$this->assertContains( 'Special:RegexBlock', $a );
			$this->assertNotContains( 'SSpecial:RegexBlock/', $a );
		}

		$i = 0;

		if ( $offset > 0 ) {
			$this->assertContains(
				'limit=' . $limit . '&amp;offset=' . max( 0, $offset - $limit ) . '&amp;',
				$links[ $i ]
			);
			$this->assertContains( 'class="mw-prevlink"', $links[$i] );
			$this->assertContains( '>previous ' . $limit . '<', $links[$i] );
			$i += 1;
		}

		$this->assertCount( 5 + $i, $links );

		$this->assertContains( 'limit=20&amp;offset=' . $offset, $links[$i] );
		$this->assertContains( 'title="Show 20 results per page"', $links[$i] );
		$this->assertContains( 'class="mw-numlink"', $links[$i] );
		$this->assertContains( '>20<', $links[$i] );
		$i += 4;

		$this->assertContains( 'limit=500&amp;offset=' . $offset, $links[$i] );
		$this->assertContains( 'title="Show 500 results per page"', $links[$i] );
		$this->assertContains( 'class="mw-numlink"', $links[$i] );
		$this->assertContains( '>500<', $links[$i] );
	}
}

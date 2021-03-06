<?php

namespace PageImages\Tests\Hooks;

use AbstractContent;
use File;
use LinksUpdate;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiTestCase;
use PageImages\Hooks\LinksUpdateHookHandler;
use PageImages\PageImageCandidate;
use PageImages\PageImages;
use ParserOutput;
use RepoGroup;
use Title;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \PageImages\Hooks\LinksUpdateHookHandler
 *
 * @group PageImages
 *
 * @license WTFPL
 * @author Thiemo Kreuz
 */
class LinksUpdateHookHandlerTest extends MediaWikiTestCase {

	public function setUp(): void {
		parent::setUp();

		// Force LinksUpdateHookHandler::getPageImageCanditates to look at all
		// sections.
		$this->setMwGlobals( 'wgPageImagesLeadSectionOnly', false );
	}

	/**
	 * @param array[] $images
	 * @param array[]|bool $leadImages
	 *
	 * @return LinksUpdate
	 */
	private function getLinksUpdate( array $images, $leadImages = false ) {
		$parserOutput = new ParserOutput();
		$parserOutput->setExtensionData( 'pageImages', $images );
		$parserOutputLead = new ParserOutput();
		$parserOutputLead->setExtensionData( 'pageImages', $leadImages ?: $images );

		$sectionContent = $this->getMockBuilder( AbstractContent::class )
			->disableOriginalConstructor()
			->getMock();

		$sectionContent->method( 'getParserOutput' )
			->willReturn( $parserOutputLead );

		$content = $this->getMockBuilder( AbstractContent::class )
			->disableOriginalConstructor()
			->getMock();

		$content->method( 'getSection' )
			->willReturn( $sectionContent );

		$revRecord = $this->getMockBuilder( RevisionRecord::class )
			->disableOriginalConstructor()
			->getMock();

		$revRecord->method( 'getContent' )
			->willReturn( $content );

		$linksUpdate = $this->getMockBuilder( LinksUpdate::class )
			->disableOriginalConstructor()
			->getMock();

		$linksUpdate->method( 'getTitle' )
			->willReturn( $this->createMock( Title::class ) );

		$linksUpdate->method( 'getParserOutput' )
			->willReturn( $parserOutput );

		$linksUpdate->method( 'getRevisionRecord' )
			->willReturn( $revRecord );

		return $linksUpdate;
	}

	/**
	 * Required to make RepoGroup::findFile in LinksUpdateHookHandler::getScore return something.
	 * @return RepoGroup
	 */
	private function getRepoGroup() {
		$file = $this->getMockBuilder( File::class )
			->disableOriginalConstructor()
			->getMock();
		// ugly hack to avoid all the unmockable crap in FormatMetadata
		$file->method( 'isDeleted' )
			->willReturn( true );

		$repoGroup = $this->getMockBuilder( RepoGroup::class )
			->disableOriginalConstructor()
			->getMock();
		$repoGroup->method( 'findFile' )
			->willReturn( $file );

		return $repoGroup;
	}

	/**
	 * @dataProvider provideDoLinksUpdate
	 * @covers \PageImages\Hooks\LinksUpdateHookHandler::doLinksUpdate
	 */
	public function testDoLinksUpdate(
		array $images,
		$expectedFreeFileName,
		$expectedNonFreeFileName
	) {
		$linksUpdate = $this->getLinksUpdate( $images );
		$mock = TestingAccessWrapper::newFromObject(
				$this->getMockBuilder( LinksUpdateHookHandler::class )
				->onlyMethods( [ 'getScore', 'isImageFree' ] )
				->getMock()
		);

		$isFreeMap = [];
		foreach ( $images as $image ) {
			array_push( $isFreeMap, [ $image['filename'], $image['isFree'] ] );
		}

		$mock->method( 'getScore' )
			->willReturnCallback(
				static function ( PageImageCandidate $_, $position ) use ( $images ) {
					return $images[$position]['score'];
				}
			);

		$mock->method( 'isImageFree' )
			->willReturnMap( $isFreeMap );

		$mock->doLinksUpdate( $linksUpdate );

		$this->assertTrue( property_exists( $linksUpdate, 'mProperties' ), 'precondition' );
		if ( $expectedFreeFileName === null ) {
			$this->assertArrayNotHasKey( PageImages::PROP_NAME_FREE, $linksUpdate->mProperties );
		} else {
			$this->assertSame( $expectedFreeFileName,
				$linksUpdate->mProperties[PageImages::PROP_NAME_FREE] );
		}
		if ( $expectedNonFreeFileName === null ) {
			$this->assertArrayNotHasKey( PageImages::PROP_NAME, $linksUpdate->mProperties );
		} else {
			$this->assertSame( $expectedNonFreeFileName, $linksUpdate->mProperties[PageImages::PROP_NAME] );
		}
	}

	public function provideDoLinksUpdate() {
		return [
			// both images are non-free
			[
				[
					[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => false ],
					[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => false ],
				],
				null,
				'A.jpg'
			],
			// both images are free
			[
				[
					[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => true ],
					[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => true ],
				],
				'A.jpg',
				null
			],
			// one free (with a higher score), one non-free image
			[
				[
					[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => true ],
					[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => false ],
				],
				'A.jpg',
				null
			],
			// one non-free (with a higher score), one free image
			[
				[
					[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => false ],
					[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => true ],
				],
				'B.jpg',
				'A.jpg'
			]
		];
	}

	/**
	 * @covers \PageImages\Hooks\LinksUpdateHookHandler::getPageImageCandidates
	 */
	public function testGetPageImageCandidates() {
		$candidates = [
			[ 'filename' => 'A.jpg', 'score' => 100, 'isFree' => false ],
			[ 'filename' => 'B.jpg', 'score' => 90, 'isFree' => false ],
		];
		$linksUpdate = $this->getLinksUpdate( $candidates, array_slice( $candidates, 0, 1 ) );

		// should get without lead.
		$handler = new LinksUpdateHookHandler();
		$this->setMwGlobals( 'wgPageImagesLeadSectionOnly', false );
		$images = $handler->getPageImageCandidates( $linksUpdate );
		$this->assertCount( 2, $images, 'All images are returned.' );

		$this->setMwGlobals( 'wgPageImagesLeadSectionOnly', true );
		$images = $handler->getPageImageCandidates( $linksUpdate );
		$this->assertCount( 1, $images, 'Only lead images are returned.' );
	}

	/**
	 * @dataProvider provideGetScore
	 */
	public function testGetScore( $image, $scoreFromTable, $position, $expected ) {
		$mock = TestingAccessWrapper::newFromObject(
			$this->getMockBuilder( LinksUpdateHookHandler::class )
				->onlyMethods( [ 'scoreFromTable', 'fetchFileMetadata', 'getRatio', 'getDenylist' ] )
				->getMock()
		);
		$mock->method( 'scoreFromTable' )
			->willReturn( $scoreFromTable );
		$mock->method( 'getRatio' )
			->willReturn( 0 );
		$mock->method( 'getDenylist' )
			->willReturn( [ 'denylisted.jpg' => 1 ] );

		$score = $mock->getScore( PageImageCandidate::newFromArray( $image ), $position );
		$this->assertSame( $expected, $score );
	}

	public function provideGetScore() {
		return [
			[
				[ 'filename' => 'A.jpg', 'handler' => [ 'width' => 100 ] ],
				100,
				0,
				// width score + ratio score + position score
				100 + 100 + 8
			],
			[
				[ 'filename' => 'A.jpg', 'fullwidth' => 100 ],
				50,
				1,
				// width score + ratio score + position score
				106
			],
			[
				[ 'filename' => 'A.jpg', 'fullwidth' => 100 ],
				50,
				2,
				// width score + ratio score + position score
				104
			],
			[
				[ 'filename' => 'A.jpg', 'fullwidth' => 100 ],
				50,
				3,
				// width score + ratio score + position score
				103
			],
			[
				[ 'filename' => 'denylisted.jpg', 'fullwidth' => 100 ],
				50,
				3,
				// denylist score
				- 1000
			],
		];
	}

	/**
	 * @dataProvider provideScoreFromTable
	 * @covers \PageImages\Hooks\LinksUpdateHookHandler::scoreFromTable
	 */
	public function testScoreFromTable( array $scores, $value, $expected ) {
		/** @var LinksUpdateHookHandler $handlerWrapper */
		$handlerWrapper = TestingAccessWrapper::newFromObject( new LinksUpdateHookHandler );

		$score = $handlerWrapper->scoreFromTable( $value, $scores );
		$this->assertEquals( $expected, $score );
	}

	public function provideScoreFromTable() {
		global $wgPageImagesScores;

		return [
			'no match' => [ [], 100, 0 ],
			'float' => [ [ 0.5 ], 0, 0.5 ],

			'always min when below range' => [ [ 200 => 2, 800 => 1 ], 0, 2 ],
			'always max when above range' => [ [ 200 => 2, 800 => 1 ], 1000, 1 ],

			'always min when below range (reversed)' => [ [ 800 => 1, 200 => 2 ], 0, 2 ],
			'always max when above range (reversed)' => [ [ 800 => 1, 200 => 2 ], 1000, 1 ],

			'min match' => [ [ 200 => 2, 400 => 3, 800 => 1 ], 200, 2 ],
			'above min' => [ [ 200 => 2, 400 => 3, 800 => 1 ], 201, 3 ],
			'second last match' => [ [ 200 => 2, 400 => 3, 800 => 1 ], 400, 3 ],
			'above second last' => [ [ 200 => 2, 400 => 3, 800 => 1 ], 401, 1 ],

			// These test cases use the default values from extension.json
			[ $wgPageImagesScores['width'], 100, -100 ],
			[ $wgPageImagesScores['width'], 119, -100 ],
			[ $wgPageImagesScores['width'], 300, 10 ],
			[ $wgPageImagesScores['width'], 400, 10 ],
			[ $wgPageImagesScores['width'], 500, 5 ],
			[ $wgPageImagesScores['width'], 600, 5 ],
			[ $wgPageImagesScores['width'], 601, 0 ],
			[ $wgPageImagesScores['width'], 999, 0 ],
			[ $wgPageImagesScores['galleryImageWidth'], 99, -100 ],
			[ $wgPageImagesScores['galleryImageWidth'], 100, 0 ],
			[ $wgPageImagesScores['galleryImageWidth'], 500, 0 ],
			[ $wgPageImagesScores['ratio'], 1, -100 ],
			[ $wgPageImagesScores['ratio'], 3, -100 ],
			[ $wgPageImagesScores['ratio'], 4, 0 ],
			[ $wgPageImagesScores['ratio'], 5, 0 ],
			[ $wgPageImagesScores['ratio'], 10, 5 ],
			[ $wgPageImagesScores['ratio'], 20, 5 ],
			[ $wgPageImagesScores['ratio'], 25, 0 ],
			[ $wgPageImagesScores['ratio'], 30, 0 ],
			[ $wgPageImagesScores['ratio'], 31, -100 ],
			[ $wgPageImagesScores['ratio'], 40, -100 ],

			'T212013' => [ $wgPageImagesScores['width'], 0, -100 ],
		];
	}

	/**
	 * @dataProvider provideIsFreeImage
	 * @covers \PageImages\Hooks\LinksUpdateHookHandler::isImageFree
	 */
	public function testIsFreeImage( $fileName, $metadata, $expected ) {
		$this->overrideMwServices( null, [
			'RepoGroup' => function () {
				return $this->getRepoGroup();
			}
		] );

		$mock = TestingAccessWrapper::newFromObject(
			$this->getMockBuilder( LinksUpdateHookHandler::class )
				->onlyMethods( [ 'fetchFileMetadata' ] )
				->getMock()
		);
		$mock->method( 'fetchFileMetadata' )
			->willReturn( $metadata );
		/** @var LinksUpdateHookHandler $mock */
		$this->assertSame( $expected, $mock->isImageFree( $fileName ) );
	}

	public function provideIsFreeImage() {
		return [
			[ 'A.jpg', [], true ],
			[ 'A.jpg', [ 'NonFree' => [ 'value' => '0' ] ], true ],
			[ 'A.jpg', [ 'NonFree' => [ 'value' => 0 ] ], true ],
			[ 'A.jpg', [ 'NonFree' => [ 'value' => false ] ], true ],
			[ 'A.jpg', [ 'NonFree' => [ 'value' => 'something' ] ], false ],
			[ 'A.jpg', [ 'something' => [ 'value' => 'something' ] ], true ],
		];
	}
}

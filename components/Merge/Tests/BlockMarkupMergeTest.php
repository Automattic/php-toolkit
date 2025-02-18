<?php

namespace WordPress\Merge\Tests;

use WordPress\Merge\Diff\MyersDiffer;
use WordPress\Merge\Merge\ChunkMerger;
use WordPress\Merge\ThreeWayMerge;
use WordPress\Merge\TwoWayDiff;
use WordPress\Merge\MergeConflictException;
use WordPress\Merge\MergeException;
use WordPress\Merge\MergeStrategy;
use WordPress\Merge\Validate\BlockMarkupMergeValidator;
use WordPress\Merge\Validate\InvalidMergeException;

use function WordPress\Merge\print_diff_chunks;

class BlockMarkupMergeTest extends \PHPUnit\Framework\TestCase {
	/**
	 * @dataProvider threeWayMergeDataProvider
	 */
	public function test_three_way_merge( $common_parent, $branch1, $branch2, $expected ) {
		$strategy     = new MergeStrategy(
			new MyersDiffer(),
			new ChunkMerger()
		);
		$merge_result = $strategy->merge( $common_parent, $branch1, $branch2 );
		$this->assertEquals( $expected, $merge_result->get_merged_content() );
	}

	public function threeWayMergeDataProvider() {
		return [
			'Block attributes: (A) Adds new attribute (B) Updates an existing attribute' => [
				'parent'   => '{"level":1}',
				'branch1'  => '{"newattribute": "before", "level":1}',
				'branch2'  => '{"level":2}',
				'expected' => '{"newattribute": "before", "level":2}',
			],
		];
	}

	/**
	 * @dataProvider corruptedResolutionCasesProvider
	 */
	public function test_corrupted_block_markup( $parent, $changeA, $changeB ) {
		$this->expectException( InvalidMergeException::class );
		$strategy = new MergeStrategy(
			new MyersDiffer(),
			new ChunkMerger(),
			new BlockMarkupMergeValidator()
		);
		$strategy->merge( $parent, $changeA, $changeB );
	}

	public function corruptedResolutionCasesProvider() {
		return $this->getTestCasesFromDirectory( 'corrupted-resolution' );
	}

	/**
	 * @dataProvider conflictingMergeCasesProvider
	 */
	public function test_conflicting_merge_cases( $parent, $changeA, $changeB ) {
		$this->expectException( InvalidMergeException::class );
		$strategy = new MergeStrategy(
			new MyersDiffer(),
			new ChunkMerger(),
			new BlockMarkupMergeValidator()
		);
		$strategy->merge( $parent, $changeA, $changeB );
	}

	public function conflictingMergeCasesProvider() {
		return $this->getTestCasesFromDirectory( 'conflicts-during-resolution' );
	}

	/**
	 * Test three-way merges of different diverging changes. The expected merge results
	 * are often imperfect. That's fine.
	 *
	 * There's no perfect way of performing a three- way merge and the BlockDiffMergeDriver
	 * approach certainly has its limitations. More importantly, these tests confirm the
	 * limitations are what we expect and that the merge driver does not crash in well-known.
	 *
	 * @dataProvider cleanMergeCasesProvider
	 */
	public function test_clean_merge_cases( $parent, $changeA, $changeB, $expected ) {
		try {
			$chunk_merger = new ChunkMerger();
			$strategy     = new MergeStrategy(
				new MyersDiffer(),
				$chunk_merger
			);
			$merge_result = $strategy->merge( $parent, $changeA, $changeB );
			$this->assertEquals( $expected, $merge_result->get_merged_content() );
		} catch ( MergeException $e ) {
			print_diff_chunks( $chunk_merger->chunksA, $chunk_merger->chunksB );
			echo $e->getMessage();
			echo $e->getTraceAsString();
			// die();
			throw $e;
		}
	}

	public function cleanMergeCasesProvider() {
		return $this->getTestCasesFromDirectory( 'clean-resolution' );
	}

	private function getTestCasesFromDirectory( $subdirectoryName ) {
		$cases = [];

		// @TODO: Only test the block markup files, create a separate driver for
		//        merging markdown other formats. Or don't and just convert everything
		//        to block markup before merging.
		$testCases = glob( __DIR__ . '/test-data/' . $subdirectoryName . '/*_parent.*' );
		foreach ( $testCases as $parentFile ) {
			$caseId = preg_replace( '/_parent\.txt$/', '', basename( $parentFile ) );

			$parent   = file_get_contents( $parentFile );
			$changeA  = file_get_contents( str_replace( '_parent.', '_changeA.', $parentFile ) );
			$changeB  = file_get_contents( str_replace( '_parent.', '_changeB.', $parentFile ) );
			$expected = file_get_contents( str_replace( '_parent.', '_merge_result.', $parentFile ) );

			$cases[ $caseId ] = [
				'parent'   => $parent,
				'changeA'  => $changeA,
				'changeB'  => $changeB,
				'expected' => $expected,
			];
		}

		return $cases;
	}

}

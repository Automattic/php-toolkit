<?php

namespace WordPress\Merge\Tests;

use WordPress\Merge\BlockDiffMergeDriver;
use WordPress\Merge\DiffUtils;
use WordPress\Merge\MergeConflictException;

class BlockDiffMergeDriverTest extends \PHPUnit\Framework\TestCase {

	/**
	 * @dataProvider corruptedResolutionCasesProvider 
	 */
	public function test_corrupted_block_markup($parent, $changeA, $changeB) {
        $this->expectException(MergeConflictException::class);
		$driver = new BlockDiffMergeDriver();
        $chunks = $driver->three_way_diff($parent, $changeA, $changeB);
        $driver->three_way_merge($chunks);
	}

	public function corruptedResolutionCasesProvider() {
        return $this->getTestCasesFromDirectory('corrupted-resolution');
	}

	/**
	 * @dataProvider corruptedMergeResultsProvider 
	 */
	public function test_assert_merge_result_is_structurally_sound($result) {
        $this->expectException(MergeConflictException::class);
		$driver = new BlockDiffMergeDriver();
        $driver->assert_merge_result_is_structurally_sound($result);
	}

	public function corruptedMergeResultsProvider() {
        $testCases = [];
		$testCasesPaths = glob(__DIR__ . '/test-data/corrupted-merge-results/*');
        foreach($testCasesPaths as $path) {
            $testCases[basename($path)] = [
                file_get_contents($path)
            ];
        }
        return $testCases;
	}

	/**
	 * @dataProvider conflictingMergeCasesProvider 
	 */
	public function test_conflicting_merge_cases($parent, $changeA, $changeB) {
        $this->expectException(MergeConflictException::class);
		$driver = new BlockDiffMergeDriver();
        $chunks = $driver->three_way_diff($parent, $changeA, $changeB);
        $driver->three_way_merge($chunks);
	}

	public function conflictingMergeCasesProvider() {
        return $this->getTestCasesFromDirectory('conflicts-during-resolution');
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
	public function test_clean_merge_cases($parent, $changeA, $changeB, $expected) {
        try {
            $driver = new BlockDiffMergeDriver();
            $chunks = $driver->three_way_diff($parent, $changeA, $changeB);
            $merged = $driver->three_way_merge($chunks);
            $this->assertEquals($expected, $merged);
        } catch(MergeConflictException $e) {
            DiffUtils::print_diff_chunks($chunks);
            echo $e->getMessage();
            echo $e->getTraceAsString();
            die();
            throw $e;
        }
	}

	public function cleanMergeCasesProvider() {
        return $this->getTestCasesFromDirectory('clean-resolution');
	}



    private function getTestCasesFromDirectory($subdirectoryName) {
		$cases = [];
		
        // @TODO: Only test the block markup files, create a separate driver for
        //        merging markdown other formats. Or don't and just convert everything
        //        to block markup before merging.
		$testCases = glob(__DIR__ . '/test-data/' . $subdirectoryName . '/*_parent.*');
		foreach ($testCases as $parentFile) {
			$caseId = preg_replace('/_parent\.txt$/', '', basename($parentFile));
			
			$parent = file_get_contents($parentFile);
			$changeA = file_get_contents(str_replace('_parent.', '_changeA.', $parentFile));
			$changeB = file_get_contents(str_replace('_parent.', '_changeB.', $parentFile)); 
			$expected = file_get_contents(str_replace('_parent.', '_merge_result.', $parentFile));

			$cases[$caseId] = [
				'parent' => $parent,
				'changeA' => $changeA,
				'changeB' => $changeB,
				'expected' => $expected
			];
		}

		return $cases;
	}

}

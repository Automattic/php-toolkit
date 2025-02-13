<?php

namespace WordPress\Git\Tests;

use WordPress\Git\Diff\BlockDiffMergeDriver;
use WordPress\Git\Diff\DiffUtils;
use WordPress\Git\Diff\MergeConflictException;

class BlockDiffMergeDriverTest extends \PHPUnit\Framework\TestCase {

	/**
	 * @dataProvider conflictingMergeCasesProvider 
	 */
	public function test_conflicting_merge_cases($parent, $changeA, $changeB) {
        $this->expectException(MergeConflictException::class);
		$driver = new BlockDiffMergeDriver();
        $chunks = $driver->three_way_diff($parent, $changeA, $changeB);
        $driver->three_way_merge($chunks);
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
            throw $e;
        }
	}

	public function cleanMergeCasesProvider() {
        return $this->getTestCasesFromDirectory('clean-merges');
	}

	public function conflictingMergeCasesProvider() {
        return $this->getTestCasesFromDirectory('conflicts');
	}

    private function getTestCasesFromDirectory($subdirectoryName) {
		$cases = [];
		
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

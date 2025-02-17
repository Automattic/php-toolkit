<?php

namespace WordPress\Merge\Tests;

use WordPress\Merge\TwoWayDiff;

class TwoWayDiffTest extends \PHPUnit\Framework\TestCase {

    public function test_lines_diff() {
		$base         = <<<EOT
        Line 1: The quick brown fox
        Line 2: jumps over the lazy dog.
        Line 4: consectetur adipiscing elit.
        EOT;

		$updated = <<<EOT
        Line 1: The quick brown fox
        Line 2: jumps over the lazy cat.
        Line 3: Lorem ipsum dolor sit amet,
        Line 4: consectetur adipiscing elit.
        EOT;

        $expected_diff = [
            [ TwoWayDiff::DIFF_EQUAL,   "Line 1: The quick brown fox\n" ],
            [ TwoWayDiff::DIFF_DELETE,  "Line 2: jumps over the lazy dog.\n" ],
            [ TwoWayDiff::DIFF_INSERT,  "Line 2: jumps over the lazy cat.\n" ],
            [ TwoWayDiff::DIFF_INSERT,  "Line 3: Lorem ipsum dolor sit amet,\n" ],
            [ TwoWayDiff::DIFF_EQUAL,   "Line 4: consectetur adipiscing elit.\n" ],
        ];
		$actual_diff = TwoWayDiff::lines_diff( $base, $updated );
		$this->assertEquals( $expected_diff, $actual_diff );
    }

    public function test_evaluate_diff() {
        $diff = [
            [ TwoWayDiff::DIFF_EQUAL,   "Line 1: The quick brown fox\n" ],
            [ TwoWayDiff::DIFF_DELETE,  "Line 2: jumps over the lazy dog.\n" ],
        ];

        $expected = "Line 1: The quick brown fox\n";
        $actual = TwoWayDiff::evaluate_diff( $diff );
        $this->assertEquals( $expected, $actual );
    }

}

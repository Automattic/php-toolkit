<?php

namespace WordPress\Git\Tests;

use WordPress\Git\Diff\MergeEngine;

class MergeEngineTest extends \PHPUnit\Framework\TestCase {

	public function test_apply_text_diff() {
		$merge_engine = new MergeEngine();
		$base         = <<<EOT
        Line 1: The quick brown fox
        Line 2: jumps over the lazy dog.
        Line 3: Lorem ipsum dolor sit amet,
        Line 4: consectetur adipiscing elit.
        Line 5: Sed do eiusmod tempor incididunt
        Line 6: ut labore et dolore magna aliqua.
        Line 7: Ut enim ad minim veniam,
        Line 8: quis nostrud exercitation ullamco
        Line 9: laboris nisi ut aliquip ex ea commodo
        Line 10: consequat. Duis aute irure dolor
        Line 11: in reprehenderit in voluptate velit
        Line 12: esse cillum dolore eu fugiat nulla pariatur.
        Line 13: Excepteur sint occaecat cupidatat non proident,
        Line 14: sunt in culpa qui officia deserunt mollit anim
        Line 15: id est laborum.
        EOT;

		$updated = <<<EOT
        Line 1: The quick brown fox
        Line 2: jumps over the lazy cat.
        Line 3: Lorem ipsum dolor sit amet,
        Line 4: consectetur adipiscing elit.
        Line 5: Sed do eiusmod tempor incididunt
        Line 6: ut labore et dolore magna aliqua.
        Line 7: Ut enim ad minim veniam,
        Line 8: quis nostrud exercitation ullamco
        Line 9: laboris nisi ut aliquip ex ea commodo
        Line 10: consequat. Duis aute irure dolor
        Line 11: in reprehenderit in voluptate velit
        Line 12: esse cillum dolore eu fugiat nulla pariatur.
        Line 13: Excepteur sint occaecat cupidatat non proident,
        Line 14: sunt in culpa qui officia deserunt mollit anim
        Line 15: id est laborum.
        Line 16: This is a new line added.
        EOT;

		$diff = $merge_engine->diff( $base, $updated );
		$this->assertEquals( $updated, $merge_engine->apply_text_diff( $base, $diff ) );
	}
}

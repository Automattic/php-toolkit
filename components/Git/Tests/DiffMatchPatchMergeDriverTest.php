<?php

namespace WordPress\Git\Tests;

use WordPress\Git\Diff\DiffMatchPatchMergeDriver;

class DiffMatchPatchMergeDriverTest extends \PHPUnit\Framework\TestCase {

	public function test_cleanly_applies_non_overlapping_changes_in_same_line() {
        $common_parent = <<<EOT
        <!-- wp:heading {"level":1} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $branch1 = <<<EOT
        <!-- wp:heading {"newattribute": "before", "level":1} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $branch2 = <<<EOT
        <!-- wp:heading {"level":2} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;

        $expected = <<<EOT
        <!-- wp:heading {"newattribute": "before", "level":2} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $driver = new DiffMatchPatchMergeDriver();
        $diff1 = $driver->diff($common_parent, $branch1);
        $diff2 = $driver->diff($common_parent, $branch2);
        
        $merged = $driver->three_way_merge_blob($common_parent, $diff1, $diff2);

        $this->assertEquals($expected, $merged);
	}

	public function test_cleanly_applies_overlapping_changes_in_same_line() {
        $common_parent = <<<EOT
        <!-- wp:heading {"level": 1, "id": "main-heading"} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $branch1 = <<<EOT
        <!-- wp:heading {"id": "main-heading", "level": 1} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $branch2 = <<<EOT
        <!-- wp:heading {"level": 2, "id": "main-heading"} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;

        $expected = <<<EOT
        <!-- wp:heading {"id": "main-heading", "level": 2} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $driver = new DiffMatchPatchMergeDriver();
        $diff1 = $driver->diff($common_parent, $branch1);
        $diff2 = $driver->diff($common_parent, $branch2);
        
        $merged = $driver->three_way_merge_blob($common_parent, $diff1, $diff2);

        $this->assertEquals($expected, $merged);
	}

	public function test_cleanly_applies_tricky_overlapping_changes_in_same_line() {
        $common_parent = <<<EOT
        <!-- wp:heading {"level": 1, "id": "main-heading"} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $branch1 = <<<EOT
        <!-- wp:heading {"id": "main-heading", "level": 1} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $branch2 = <<<EOT
        <!-- wp:heading {"level": 1} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;

        $expected = <<<EOT
        <!-- wp:heading {"id": "main-heading", "level": 2} -->
        <h1>A test note</h1>
        <!-- /wp:heading -->
        EOT;
        
        $driver = new DiffMatchPatchMergeDriver();
        $diff1 = $driver->diff($common_parent, $branch1);
        $diff2 = $driver->diff($common_parent, $branch2);
        
        $merged = $driver->three_way_merge_blob($common_parent, $diff1, $diff2);

        $this->assertEquals($expected, $merged);
	}

}

<?php

namespace WordPress\Git\Tests;

use WordPress\Git\Diff\DiffMatchPatchMergeDriver;

class DiffMatchPatchMergeDriverTest extends \PHPUnit\Framework\TestCase {
	/**
	 * @dataProvider threeWayMergeDataProvider
	 */
	public function test_three_way_merge( $common_parent, $branch1, $branch2, $options, $expected ) {
		$driver = new DiffMatchPatchMergeDriver();
		$merged = $driver->three_way_merge( $common_parent, $branch1, $branch2, $options );
		$this->assertEquals( $expected, $merged );
	}

	public function threeWayMergeDataProvider() {
		return [
			'Block attributes: (A) Adds new attribute (B) Updates an existing attribute' => [
				'parent' => <<<EOT
                <!-- wp:heading {"level":1} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'branch1' => <<<EOT
                <!-- wp:heading {"newattribute": "before", "level":1} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'branch2' => <<<EOT
                <!-- wp:heading {"level":2} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'options' => [],

				'expected' => <<<EOT
                <!-- wp:heading {"newattribute": "before", "level":2} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT
			],
			'Block attributes: (A) Reorders attributes (B) Updates an existing attribute' => [
				'parent' => <<<EOT
                <!-- wp:heading {"level": 1, "id": "main-heading"} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'branch1' => <<<EOT
                <!-- wp:heading {"id": "main-heading", "level": 1} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'branch2' => <<<EOT
                <!-- wp:heading {"level": 2, "id": "main-heading"} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'options' => [],

				'expected' => <<<EOT
                <!-- wp:heading {"id": "main-heading", "level": 2} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT
			],
			'Block attributes: (A) Reorders attributes (B) Removes an attribute' => [
				'parent' => <<<EOT
                <!-- wp:heading {"level": 1, "id": "main-heading"} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'branch1' => <<<EOT
                <!-- wp:heading {"id": "main-heading", "level": 1} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'branch2' => <<<EOT
                <!-- wp:heading {"level": 1} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT,

				'options' => ['rebase' => true],

				'expected' => <<<EOT
                <!-- wp:heading {"level": 1} -->
                <h1>A test note</h1>
                <!-- /wp:heading -->
                EOT
			],
		];
	}
}

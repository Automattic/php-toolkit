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
				'parent' => '{"level":1}',
				'branch1' => '{"newattribute": "before", "level":1}',
				'branch2' => '{"level":2}',
				'options' => ['rebase' => true],
				'expected' => '{"newattribute": "before", "level":2}'
			],
			'Block attributes: (A) Reorders attributes (B) Updates an existing attribute' => [
				'parent' => '{"level": 1, "id": "main-heading"}',
				'branch1' => '{"id": "main-heading", "level": 1}',
				'branch2' => '{"level": 2, "id": "main-heading"}',
				'options' => [],
				'expected' => '{"id": "main-heading", "level": 2}'
			],
			'Block attributes: (A) Prepends and attribute and pops an attribute (B) Updates an attribute' => [
				'parent' => '{"level": 100, "id": "main-heading"}',
				'branch1' => '{"third": "key", "level": 100}',
				'branch2' => '{"level": 2, "id": "main-heading"}',
				'options' => ['rebase' => true],
				'expected' => '{"third": "key", "level": 2}'
			],
			'Block attributes: (A) Swaps attributes order (B) Removes an attribute' => [
				'parent' => '{"level": 1, "id": "main-heading"}',
				'branch1' => '{"id": "main-heading", "level": 1}',
				'branch2' => '{"level": 1}',
				'options' => ['rebase' => true],
				'expected' => '{"level": 1}'
			],
		];
	}
}

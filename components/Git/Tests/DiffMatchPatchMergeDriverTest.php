<?php

namespace WordPress\Git\Tests;

use WordPress\Git\Diff\DiffMatchPatchMergeDriver;

class DiffMatchPatchMergeDriverTest extends \PHPUnit\Framework\TestCase {
	/**
	 * @dataProvider threeWayMergeDataProvider
	 */
	public function test_three_way_merge( $common_parent, $branch1, $branch2, $expected ) {
		$driver = new DiffMatchPatchMergeDriver();
		$merged = $driver->three_way_merge( $common_parent, $branch1, $branch2 );
		$this->assertEquals( $expected, $merged );
	}

	public function threeWayMergeDataProvider() {
		return [
			'Block attributes: (A) Adds new attribute (B) Updates an existing attribute' => [
				'parent' => '{"level":1}',
				'branch1' => '{"newattribute": "before", "level":1}',
				'branch2' => '{"level":2}',
				'expected' => '{"newattribute": "before", "level":2}'
			],
			'Block attributes: (A) Reorders attributes (B) Updates an existing attribute' => [
				'parent' => '{"level": 1, "id": "main-heading"}',
				'branch1' => '{"id": "main-heading", "level": 1}',
				'branch2' => '{"level": 2, "id": "main-heading"}',
				'expected' => '{"id": "main-heading", "level": 2}'
			],
			'Block attributes: (A) Prepends and attribute and pops an attribute (B) Updates an attribute' => [
				'parent' => '{"level": 100, "id": "main-heading"}',
				'branch1' => '{"third": "key", "level": 100}',
				'branch2' => '{"level": 2, "id": "main-heading"}',
				'expected' => '{"third": "key", "level": 2}'
			],
			'Block attributes: (A) Swaps attributes order (B) Removes an attribute' => [
				'parent' => '{"level": 1, "id": "main-heading"}',
				'branch1' => '{"id": "main-heading", "level": 1}',
				'branch2' => '{"level": 1}',
				'expected' => '{"level": 1}'
			],

			'Block attributes: (A) Adds a new attribute (B) Adds a different new attribute' => [
				'parent' => '{"level": 1}',
				'branch1' => '{"level": 1, "newattributeA": "valueA"}',
				'branch2' => '{"level": 1, "newattributeB": "valueB"}',
                // The resolved insertion order is arbitrary and may change
                // @TODO: Tolerate the fluctuating order in this test suite
				'expected' => '{"level": 1, "newattributeB": "valueB", "newattributeA": "valueA"}'
			],

			'Block attributes: (A) Deletes an attribute (B) Modifies the same attribute' => [
				'parent' => '{"level": 1, "id": "main-heading"}',
				'branch1' => '{"level": 1}',
				'branch2' => '{"level": 1, "id": "secondary-heading"}',
				'expected' => '{"level": 1}'
			],

			'Block attributes: (A) Changes attribute value to same-length value (B) Changes the same attribute to a different value' => [
				'parent' => '{"level": 1}',
				'branch1' => '{"level": 2}',
				'branch2' => '{"level": 3}',
				'expected' => '{"level": 3}'
			],

			'Block attributes: (A) Changes attribute value to a longer value (B) Changes the same attribute to a different value' => [
				'parent' => '{"level": 1}',
				'branch1' => '{"level": 20}',
				'branch2' => '{"level": 3}',
				'expected' => '{"level": 3}'
			],

			'Block attributes: (A) Adds a nested attribute (B) Modifies a different attribute' => [
				'parent' => '{"level": 1, "id": "main-heading"}',
				'branch1' => '{"level": 1, "id": "main-heading", "nested": {"key": "value"}}',
				'branch2' => '{"level": 2, "id": "main-heading"}',
				'expected' => '{"level": 2, "id": "main-heading", "nested": {"key": "value"}}'
			],
			'Block attributes: (A) Removes an attribute (B) Adds a new attribute' => [
				'parent' => '{"level": 1, "id": "main-heading"}',
				'branch1' => '{"level": 1}',
				'branch2' => '{"level": 1, "newattribute": "value"}',
                /*
                 * We're losing the attribute added in branch2.
                 *
                 * Mental model:
                 * * Branch 1 removed an attribute
                 * * Branch 2 changed that attribute
                 * * The removal wins
                 *
                 * Alternatively, we could proclaim it's an unresolvable conflict.
                 */
				'expected' => '{"level": 1}'
			],
            'Block attributes: (A) Deletes an attribute (B) Deletes the same attribute' => [
                'parent' => '{"level": 1, "id": "main-heading"}',
                'branch1' => '{"level": 1}',
                'branch2' => '{"level": 1}',
                'expected' => '{"level": 1}'
            ],
            'Block attributes: (A) Modifies an attribute to an empty string (B) Modifies the same attribute to a non-empty string' => [
                'parent' => '{"level": "high"}',
                'branch1' => '{"level": ""}',
                'branch2' => '{"level": "low"}',
                'expected' => '{"level": "low"}'
            ],
            'Block attributes: (A) Adds a deeply nested attribute (B) Modifies a top-level attribute' => [
                'parent' => '{"level": 1}',
                'branch1' => '{"level": 1, "nested": {"deep": {"key": "value"}}}',
                'branch2' => '{"level": 2}',
                'expected' => '{"level": 2, "nested": {"deep": {"key": "value"}}}'
            ],
            'Block attributes: (A) Removes all attributes (B) Adds a new attribute' => [
                'parent' => '{"level": 1, "id": "main-heading"}',
                'branch1' => '{}',
                'branch2' => '{"level": 1, "newattribute": "value"}',
                'expected' => '{}'
            ],
		];
	}
}

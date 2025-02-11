<?php

namespace WordPress\Git\Tests;

use WordPress\Git\Diff\BlockDiffMergeDriver;

class BlockDiffMergeDriverTest extends \PHPUnit\Framework\TestCase {
	/**
	 * @dataProvider threeWayMergeDataProvider
	 */
	public function test_three_way_merge( $common_parent, $branch1, $branch2, $expected ) {
		$driver = new BlockDiffMergeDriver();
		$merged = $driver->three_way_merge( $common_parent, $branch1, $branch2 );
		$this->assertEquals( $expected, $merged );
	}

	public function threeWayMergeDataProvider() {
		return [
			'Three identical documents' => [
                'parent' => <<<'HTML'
                    <!-- wp:paragraph {"class": "wp-bold"} -->
                    <p>Here's some text before the block.</p>
                    <!-- /wp:paragraph -->
                    
                    <!-- wp:paragraph -->
                    <p>First paragraph</p>
                    <!-- /wp:paragraph -->
            
                    <!-- wp:heading {"level": 2} -->
                    <h2>Hello, there</h2>
                    <!-- /wp:heading -->
                    
                    Ending words
                    HTML,
                'version_b' => <<<'HTML'
                    <!-- wp:paragraph {"class": "wp-bold"} -->
                    <p>Here's some text before the block.</p>
                    <!-- /wp:paragraph -->
                    
                    <!-- wp:paragraph -->
                    <p>First paragraph</p>
                    <!-- /wp:paragraph -->
            
                    <!-- wp:heading {"level": 2} -->
                    <h2>Hello, there</h2>
                    <!-- /wp:heading -->
                    
                    Ending words
                    HTML,
                'version_c' => <<<'HTML'
                    <!-- wp:paragraph {"class": "wp-bold"} -->
                    <p>Here's some text before the block.</p>
                    <!-- /wp:paragraph -->
                    
                    <!-- wp:paragraph -->
                    <p>First paragraph</p>
                    <!-- /wp:paragraph -->
            
                    <!-- wp:heading {"level": 2} -->
                    <h2>Hello, there</h2>
                    <!-- /wp:heading -->
                    
                    Ending words
                    HTML,
                'expected' => <<<'HTML'
                    <!-- wp:paragraph {"class": "wp-bold"} -->
                    <p>Here's some text before the block.</p>
                    <!-- /wp:paragraph -->
                    
                    <!-- wp:paragraph -->
                    <p>First paragraph</p>
                    <!-- /wp:paragraph -->
            
                    <!-- wp:heading {"level": 2} -->
                    <h2>Hello, there</h2>
                    <!-- /wp:heading -->
                    
                    Ending words
                    HTML,
            ],

			'(A) Removes a paragraph, (B) Updates a heading' => [
                'parent' => <<<'HTML'
                    <!-- wp:paragraph {"class": "wp-bold"} -->
                    <p>Here's some text before the block.</p>
                    <!-- /wp:paragraph -->
                    
                    <!-- wp:paragraph -->
                    <p>First paragraph</p>
                    <!-- /wp:paragraph -->
            
                    <!-- wp:heading {"level": 2} -->
                    <h2>Hello, there</h2>
                    <!-- /wp:heading -->
                    
                    Ending words
                    HTML,
                'version_b' => <<<'HTML'
                    <!-- wp:paragraph {"class": "wp-bold"} -->
                    <p>Here's some text before the block.</p>
                    <!-- /wp:paragraph -->
            
                    <!-- wp:heading {"level": 2} -->
                    <h2>Updated heading</h2>
                    <!-- /wp:heading -->
                    
                    Ending words
                    HTML,
                'version_c' => <<<'HTML'
                    <!-- wp:paragraph {"class": "wp-italics"} -->
                    <p>Here's some text before the block.</p>
                    <!-- /wp:paragraph -->
                    
                    <!-- wp:paragraph -->
                    <p>First paragraph</p>
                    <!-- /wp:paragraph -->
            
                    <!-- wp:heading {"level": 2} -->
                    <h2>Hello, there</h2>
                    <!-- /wp:heading -->
                    
                    Ending words
                    HTML,
				'expected' => <<<'HTML'
                    <!-- wp:paragraph {"class": "wp-italics"} -->
                    <p>Here's some text before the block.</p>
                    <!-- /wp:paragraph -->

                    <!-- wp:heading {"level": 2} -->
                    <h2>Updated heading</h2>
                    <!-- /wp:heading -->
                    
                    Ending words
                    HTML
			],
		];
	}
}

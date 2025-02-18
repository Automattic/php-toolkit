<?php

namespace WordPress\Merge;

use WordPress\Merge\Diff\Differ;
use WordPress\Merge\Merge\Merger;
use WordPress\Merge\Merge\MergeResult;
use WordPress\Merge\Validate\InvalidMergeException;
use WordPress\Merge\Validate\MergeValidator;

class MergeStrategy {
	private $differ;
	private $merger;
	private $validator;

	public function __construct( Differ $differ, Merger $merger, ?MergeValidator $validator = null ) {
		$this->differ    = $differ;
		$this->merger    = $merger;
		$this->validator = $validator;
	}

	/**
	 * Performs a three-way merge between a common base and two branches.
	 *
	 * @param  string  $base  The common base version
	 * @param  string  $branchA  First branch version
	 * @param  string  $branchB  Second branch version
	 *
	 * @return MergeResult The merged result
	 */
	public function merge( string $base, string $branchA, string $branchB ): MergeResult {
		// Compute diffs between base and each branch
		$diffAB = $this->differ->diff( $base, $branchA );
		$diffAC = $this->differ->diff( $base, $branchB );

		// Perform the merge using the configured merge strategy
		$merge_result = $this->merger->merge( $diffAB, $diffAC );

		if ( $merge_result->has_conflicts() ) {
			throw new InvalidMergeException( 'Merge resulted in conflicts', $merge_result );
		}

		if ( $this->validator ) {
			$this->validator->validate( $merge_result->get_merged_content() );
		}

		return $merge_result;
	}

}

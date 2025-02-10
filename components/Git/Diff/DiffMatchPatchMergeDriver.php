<?php

namespace WordPress\Git\Diff;

use DiffMatchPatch\Diff;
use DiffMatchPatch\DiffMatchPatch;
use DiffMatchPatch\PatchObject;
use WordPress\Git\GitException;

class DiffMatchPatchMergeDriver {
	// implements MergeDriver {

	private $dmp;

	public function __construct() {
		$this->dmp = new DiffMatchPatch();
	}

    /**
     * 
     */
	public function three_way_merge( $common_parent, $branch_a, $branch_b, $options = [] ) {
        $diff_a = $this->dmp->diff_main($common_parent, $branch_a);
        $this->dmp->diff_cleanupSemantic($diff_a);
        $this->dmp->diff_cleanupEfficiency($diff_a);

        $diff_b = $this->dmp->diff_main($common_parent, $branch_b);
        $this->dmp->diff_cleanupSemantic($diff_b);
        $this->dmp->diff_cleanupEfficiency($diff_b);

        $patch_a = $this->dmp->patch_make($common_parent, $diff_a);

        list($merged_a, $applied_a) = $this->dmp->patch_apply($patch_a, $common_parent);
        if ( ! $applied_a ) {
            throw new GitException( 'Diff failed to apply cleanly onto common parent' );
        }

        if(isset($options['rebase']) && $options['rebase']) {
            $diff_b = $this->rebase_diff($diff_a, $diff_b, $merged_a);
        }

        $patch_b = $this->dmp->patch_make($common_parent, $diff_b);
        list($merged_b, $applied_b) = $this->dmp->patch_apply($patch_b, $merged_a);
        if ( ! $applied_b ) {
            throw new GitException( 'Diff failed to apply cleanly onto common parent' );
        }
        return $merged_b;
    }

	public function apply_diff( $text, $diff ) {
        $patch = $this->dmp->patch_make($text, $diff);
		return $this->dmp->patch_apply( $patch, $text );
	}

	public function diff( $old_string, $new_string ) {
		return $this->dmp->diff_main( $old_string, $new_string );
	}

    public function rebase_diff( $base_diff, $rebased_diff, $document_after_base_diff ) {
        // Convert the diffs to format that makes rebasing easier
        $diff_a = self::dmp_diff_to_annotated_diff($base_diff);
        $diff_b = self::dmp_diff_to_annotated_diff($rebased_diff);

        // Do the rebase
        $i_a = 0;
        $i_b = 0;

        $rebased_diff_b = [];
        $accumulated_shift = 0;
        while($i_b < count($diff_b)) {
            $change_b = $diff_b[$i_b];
            $change_b['start'] += $accumulated_shift;
            if(!isset($diff_a[$i_a])) {
                $rebased_diff_b[] = $change_b;
                $i_b++;
                continue;
            }

            $change_a = $diff_a[$i_a];
            if($change_a['start'] === $change_b['start']) {
                throw new MergeConflictException('Two changes at the same start position');
            } else if($change_b['start'] < $change_a['start']) {
                $rebased_diff_b[] = $change_b;
                $i_b++;
            } else {
                switch($change_a['type']) {
                    case Diff::INSERT:
                        $accumulated_shift += $change_a['length'];
                        break;
                    case Diff::DELETE:
                        if($change_a['start'] + $change_a['length'] > $change_b['start']) {
                            if($change_b['type'] === Diff::INSERT) {
                                // @TODO: Tolerate that if the b change is a compatible deletion
                                throw new MergeConflictException('Deletion in A overlaps with an insertion in B');
                            }
                        }
                        // Shift by the number of characters that are being deleted, but
                        // only up to the point where the a deletion starts.
                        $accumulated_shift -= min(
                            $change_a['length'],
                            $change_b['start'] - $change_a['start']
                        );
                        break;
                }
                $i_a++;
            }
        }
        
        // Convert the rebased diff back to DMP format
        $cursor = 0;
        $dmp_diff = [];
        $final_document_length = strlen($document_after_base_diff) + $accumulated_shift;

        foreach($rebased_diff_b as $change) {
            if($change['start'] > $cursor) {
                $dmp_diff[] = [
                    Diff::EQUAL,
                    substr($document_after_base_diff, $cursor, $change['start'] - $cursor),
                ];
            }
            switch($change['type']) {
                case Diff::INSERT:
                    $dmp_diff[] = [
                        Diff::INSERT,
                        $change['string'],
                    ];
                    break;
                case Diff::DELETE:
                    $dmp_diff[] = [
                        Diff::DELETE,
                        substr($document_after_base_diff, $change['start'], $change['length']),
                    ];
                    break;
            }
            $cursor = $change['start'] + $change['length'];
        }
        
        if($cursor < $final_document_length) {
            $dmp_diff[] = [
                Diff::EQUAL,
                substr($document_after_base_diff, $cursor, $final_document_length - $cursor),
            ];
        }
        return $dmp_diff;
    }

    private static function dmp_diff_to_annotated_diff($diff) {
        $cursor = 0;
        $annotated_diff = [];
        foreach($diff as $change) {
            switch($change[0]) {
                case Diff::EQUAL:
                    $cursor += mb_strlen($change[1]);
                    break;
                case Diff::INSERT:
                    $annotated_change = [
                        'type' => Diff::INSERT,
                        'start' => $cursor,
                        'length' => 0,
                        'string' => $change[1],
                    ];
                    $annotated_diff[] = $annotated_change;
                    break;
                case Diff::DELETE:
                    $annotated_change = [
                        'type' => Diff::DELETE,
                        'start' => $cursor,
                        'length' => mb_strlen($change[1])
                    ];
                    $cursor += $annotated_change['length'];
                    $annotated_diff[] = $annotated_change;
                    break;
            }
        }
        return $annotated_diff;
    }

}

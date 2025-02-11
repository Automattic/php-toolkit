<?php

namespace WordPress\Git\Diff;

use DiffMatchPatch\Diff;
use DiffMatchPatch\DiffMatchPatch;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use WordPress\Git\GitException;

/**
 * A simple three-way merge driver for reconciling **non-conflicting**
 * diverging updates to block markup.
 * 
 * The three-way merge algorithm for (P)arent, (A) and (B) is:
 * 
 * 1. Diff from (P) to (A)
 * 2. Diff from (P) to (B)
 * 3. Rebase the diff from (P) to (B) onto the diff from (A) to (B)
 * 4. Apply the diff from (P) to (A)
 * 5. Apply the diff from (P) to (B)
 * 6. Validate the result
 * 
 * Conflicts and invalid block markup in any of the steps thrash the entire
 * reconciliation and throw a MergeConflictException. The API consumer would
 * then need to use another reconciliation algorithm, e.g. add A as a history
 * entry and set the latest version to B.
 * 
 * ## Goals and non-goals
 * 
 * Goal: Helping people collaborate on the same documents in typical situations.
 * 
 * Automated merging is not needed most of the time. You'll typically have an
 * internet connection and save your changes to the server as you make them.
 * The next person will then apply the changes to the latest version of the document.
 * 
 * Even when you're offline and another person happened to edit the same document,
 * your changes will not clash and can be safely merged without any advanced algorithms.
 * This is what this class is for.
 * 
 * Imagine you board a plane, have no wifi, and you add closing thoughts to
 * your report. At the same time, your colleague sits in the office and corrects
 * the financial numbers in the same report.
 * 
 * Since you've worked in non-overlapping regions, this class will automatically
 * merge your changes. If there are any conflicts, it will bale out and leave the
 * resolution to you.
 * 
 * Non-goals:
 * 
 * * Solving general HTML-to-HTML reconciliation.
 * * Live collaboration, CRDT, advanced conflict resolution.
 * 
 * ## Implementation details
 * 
 * * Diffing is done using the diff-match-patch library for speed and human
 *   readability.
 * * Rebasing the diff is done by shifting the start offset of each P -> B change
 *   by the number of characters that were deleted or inserted before it in the
 *   A -> B diff. Overlapping insertions and deletions are treated as conflicts.
 * * Patch application is naive and exact. It intentionally does not rely on
 *   the fuzzy matching capabilities of the diff-match-patch library. They're great
 *   for text, but not for structured data. Reconciling even simple diffs often
 *   leads to block markup syntax errors [1].
 * 
 * ## Alternatives explored
 * 
 * * Semantic tree diffing. Basically: Parse the block markup into a tree and
 *   use a structured/semantic reconciliation algorithm. It doesn't seem viable
 *   without unique IDs for each block. Let's revisit after the introduction of
 *   CRDTs in the collaborative editing project [2].
 * * Use the diff-match-patch library to generate patches and apply them.
 *   This would have the advantage of being able to detect conflicts and resolve
 *   them. However, the diff-match-patch library is not designed for structured
 *   data, and would introduce block markup syntax errors in the output.
 * 
 * [1] https://github.com/google/diff-match-patch/wiki/Plain-Text-vs.-Structured-Content
 * [2] https://github.com/WordPress/gutenberg/discussions/65012#discussioncomment-10801420
 */
class BlockDiffMergeDriver {
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
        // $this->dmp->diff_cleanupSemantic($diff_a);
        // $this->dmp->diff_cleanupEfficiency($diff_a);
        // $diff_a = $this->diff_cleanup_block_boundaries($common_parent, $branch_a, $diff_a);

        $diff_b = $this->dmp->diff_main($common_parent, $branch_b);
        // $this->dmp->diff_cleanupSemantic($diff_b);
        // $this->dmp->diff_cleanupEfficiency($diff_b);
        // $diff_b = $this->diff_cleanup_block_boundaries($common_parent, $branch_b, $diff_b);

        $patch_a = $this->dmp->patch_make($common_parent, $diff_a);
        list($merged_a, $applied_a) = $this->dmp->patch_apply($patch_a, $common_parent);
        if ( ! $applied_a ) {
            throw new GitException( 'Diff failed to apply cleanly onto common parent' );
        }

        $mode = $options['mode'] ?? 'fallback';
        if($mode === 'dmp') {
            $patch_b = $this->dmp->patch_make($common_parent, $diff_b);
            $merged_b = $this->apply_patch($merged_a, $patch_b);
        } else {
            try {
                // Always try rebasing the patch
                $diff_b = $this->rebase_diff($diff_a, $diff_b, $merged_a);
                $patch_b = $this->dmp->patch_make($merged_a, $diff_b);
                $merged_b = $this->apply_patch($merged_a, $patch_b);        
            } catch(MergeConflictException $e) {
                if($mode === 'rebase') {
                    throw $e;
                }
                // If the rebasing failed, fall back to fuzzy diff-match-patch merging
                $patch_b = $this->dmp->patch_make($common_parent, $diff_b);
                $merged_b = $this->apply_patch($merged_a, $patch_b);
            }
        }

        return $merged_b;
    }

    // @TODO: Cleanup all this messy internal state. Or not. Internal can be messy.
    public function diff_cleanup_block_boundaries( $document_a, $document_b, $diff ) {
        $annotated_diff = [];
        $cursor_in_original_doc = 0;
        $cursor_in_updated_doc = 0;
        foreach($diff as $change) {
            $length = mb_strlen($change[1]);
            switch($change[0]) {
                case Diff::EQUAL:
                    $annotated_diff[] = [
                        'type' => $change[0],
                        'string' => $change[1],
                        'original_doc' => [
                            'start' => $cursor_in_original_doc,
                            'length' => $length,
                        ],
                        'updated_doc' => [
                            'start' => $cursor_in_updated_doc,
                            'length' => $length,
                        ],
                    ];
                    $cursor_in_original_doc += $length;
                    $cursor_in_updated_doc += $length;
                    break;
                case Diff::INSERT:
                    $annotated_diff[] = [
                        'type' => $change[0],
                        'string' => $change[1],
                        'original_doc' => [
                            'start' => $cursor_in_original_doc,
                            'length' => 0,
                        ],
                        'updated_doc' => [
                            'start' => $cursor_in_updated_doc,
                            'length' => $length,
                        ],
                    ];
                    $cursor_in_updated_doc += $length;
                    break;
                case Diff::DELETE:
                    $annotated_diff[] = [
                        'type' => $change[0],
                        'string' => $change[1],
                        'original_doc' => [
                            'start' => $cursor_in_original_doc,
                            'length' => $length,
                        ],
                        'updated_doc' => [
                            'start' => $cursor_in_updated_doc,
                            'length' => 0,
                        ],
                    ];
                    $cursor_in_original_doc += $length;
                    break;
            }
        }

        // usort($annotated_diff, function($a, $b) {
        //     if ($a['original_doc']['start'] == $b['original_doc']['start']) {
        //         if ($a['type'] == Diff::INSERT && $b['type'] == Diff::DELETE) {
        //             return -1;
        //         } elseif ($a['type'] == Diff::DELETE && $b['type'] == Diff::INSERT) {
        //             return 1;
        //         }
        //     }
        //     return $a['original_doc']['start'] <=> $b['original_doc']['start'];
        // });

        $doc_a_processor = new BlockMarkupProcessor($document_a);
        $doc_b_processor = new BlockMarkupProcessor($document_b);
        $has_delimiter_a = $doc_a_processor->next_block_delimiter();
        $has_delimiter_b = $doc_b_processor->next_block_delimiter();
        if(!$has_delimiter_a && !$has_delimiter_b) {
            return $diff;
        }
        $delimiter_a = $doc_a_processor->get_block_delimiter_span();
        $delimiter_idx = 0;

        $delimiter_b = $doc_b_processor->get_block_delimiter_span();
        $delimiter_idx = 0;

        $split_diff = [];
        while(count($annotated_diff)) {
            $change = array_shift($annotated_diff);

            // If the current change is after the block delimiter,
            // skip until the next relevant block delimiter.
            while(
                $delimiter_a &&
                $change['original_doc']['start'] >= $delimiter_a->start + $delimiter_a->length
            ) {
                $doc_a_processor->next_block_delimiter();
                $delimiter_a = $doc_a_processor->get_block_delimiter_span();
            }

            while(
                $delimiter_b &&
                $change['updated_doc']['start'] >= $delimiter_b->start + $delimiter_b->length
            ) {
                $doc_b_processor->next_block_delimiter();
                $delimiter_b = $doc_b_processor->get_block_delimiter_span();
            }

            if($delimiter_a && ($change['type'] === Diff::DELETE || $change['type'] === Diff::EQUAL)) {
                $intersects_block_delimiter = (
                    // Ends before the block delimiter starts
                    $change['original_doc']['start'] + $change['original_doc']['length'] < $delimiter_a->start ^
                    // Starts after the block delimiter ends
                    $delimiter_a->start + $delimiter_a->length > $change['original_doc']['start']
                );

                if($intersects_block_delimiter) {
                    $block_delimiter_start_offset = max(0, $delimiter_a->start - $change['original_doc']['start']);
                    $before_block_delimiter = mb_substr($change['string'], 0, $block_delimiter_start_offset);

                    $block_delimeter_last_char_offset = $delimiter_a->start + $delimiter_a->length - $change['original_doc']['start'];
                    $inside_block_delimiter = mb_substr($change['string'], $block_delimiter_start_offset, $block_delimeter_last_char_offset - $block_delimiter_start_offset);

                    $after_block_delimiter = mb_substr($change['string'], mb_strlen($before_block_delimiter) + mb_strlen($inside_block_delimiter));
                    // print_r([
                    //     'change start' => $change['original_doc']['start'],
                    //     'change end' => $change['original_doc']['start'] + $change['original_doc']['length'],
                    //     'block delimiter start' => $delimiter_a->start,
                    //     'block delimiter end' => $delimiter_a->start + $delimiter_a->length,
                    //     '$block_delimiter_start_offset' => $block_delimiter_start_offset,
                    //     '$block_delimeter_last_char_offset' => $block_delimeter_last_char_offset,
                    //     'before' => $before_block_delimiter,
                    //     'inside' => $inside_block_delimiter,
                    //     'after' => $after_block_delimiter,
                    // ]);

                    // Store the part before the block delimiter
                    if(mb_strlen($before_block_delimiter)) {
                        $split_diff[] = [$change['type'], $before_block_delimiter, null];
                        ++$delimiter_idx;
                    }
                    if(mb_strlen($inside_block_delimiter)) {
                        $split_diff[] = [$change['type'], $inside_block_delimiter, $delimiter_idx];
                    }

                    // Keep the rest of it for processing in the next iteration
                    if(mb_strlen($after_block_delimiter) > 0) {
                        array_unshift($annotated_diff, [
                            'type' => $change['type'],
                            'string' => $after_block_delimiter,
                            'original_doc' => [
                                'start' => $change['original_doc']['start'] + $block_delimeter_last_char_offset,
                                'length' => $change['original_doc']['length'] > 0 ? mb_strlen($after_block_delimiter) : 0,
                            ],
                            'updated_doc' => [
                                'start' => $change['updated_doc']['start'],
                                'length' => $change['updated_doc']['length'] > 0 ? mb_strlen($after_block_delimiter) : 0,
                            ],
                        ]);
                    }
                    continue;
                }
            } else if($delimiter_b && $change['type'] === Diff::INSERT) {
                $intersects_block_delimiter = (
                    // Ends before the block delimiter starts
                    $change['updated_doc']['start'] + $change['updated_doc']['length'] < $delimiter_b->start ^
                    // Starts after the block delimiter ends
                    $delimiter_b->start + $delimiter_b->length > $change['updated_doc']['start']
                );

                // var_dump($intersects_block_delimiter);

                if($intersects_block_delimiter) {
                    $block_delimiter_start_offset = max(0, $delimiter_b->start - $change['updated_doc']['start']);
                    $before_block_delimiter = mb_substr($change['string'], 0, $block_delimiter_start_offset);

                    $block_delimeter_last_char_offset = $delimiter_b->start + $delimiter_b->length - $change['updated_doc']['start'];
                    $inside_block_delimiter = mb_substr($change['string'], $block_delimiter_start_offset, $block_delimeter_last_char_offset - $block_delimiter_start_offset);

                    $after_block_delimiter = mb_substr($change['string'], mb_strlen($before_block_delimiter) + mb_strlen($inside_block_delimiter));
                    // print_r([
                    //     'change start' => $change['updated_doc']['start'],
                    //     'change end' => $change['updated_doc']['start'] + $change['updated_doc']['length'],
                    //     'block delimiter start' => $delimiter_b->start,
                    //     'block delimiter end' => $delimiter_b->start + $delimiter_b->length,
                    //     '$block_delimiter_start_offset' => $block_delimiter_start_offset,
                    //     '$block_delimeter_last_char_offset' => $block_delimeter_last_char_offset,
                    //     'delimiter_idx' => $delimiter_idx,
                    //     'before' => $before_block_delimiter,
                    //     'inside' => $inside_block_delimiter,
                    //     'after' => $after_block_delimiter,
                    // ]);

                    // Store the part before the block delimiter
                    if(mb_strlen($before_block_delimiter)) {
                        $split_diff[] = [$change['type'], $before_block_delimiter, null];
                    }
                    if(mb_strlen($inside_block_delimiter)) {
                        $split_diff[] = [$change['type'], $inside_block_delimiter, $delimiter_idx];
                    }

                    // Keep the rest of it for processing in the next iteration
                    if(mb_strlen($after_block_delimiter) > 0) {
                        array_unshift($annotated_diff, [
                            'type' => $change['type'],
                            'string' => $after_block_delimiter,
                            'original_doc' => [
                                'start' => $change['original_doc']['start'],
                                'length' => $change['original_doc']['length'] > 0 ? mb_strlen($after_block_delimiter) : 0,
                            ],
                            'updated_doc' => [
                                'start' => $change['updated_doc']['start'] + $block_delimeter_last_char_offset,
                                'length' => $change['updated_doc']['length'] > 0 ? mb_strlen($after_block_delimiter) : 0,
                            ],
                        ]);
                    }
                    continue;
                }
            }

            $split_diff[] = [$change['type'], $change['string'], null];
        }

        // var_dump($split_diff);
        // die("^ split_diff");

        // Merge entries related to the same block delimiter
        $reduced_split_diff = [];
        $prev_delimiter = '';
        $new_delimiter = '';
        for ($i=0;$i<count($split_diff);$i++) {
            $entry = $split_diff[$i];
            $delimiter_idx = $entry[2] ?? null;
            $next_delimiter_idx = $split_diff[$i+1][2] ?? null;

            if(null === $delimiter_idx) {
                $reduced_split_diff[] = $entry;
                continue;
            }

            switch($entry[0]) {
                case Diff::EQUAL:
                    $prev_delimiter .= $entry[1];
                    $new_delimiter .= $entry[1];
                    break;
                case Diff::INSERT:
                    $new_delimiter .= $entry[1];
                    break;
                case Diff::DELETE:
                    $prev_delimiter .= $entry[1];
                    break;
            }

            if($delimiter_idx !== $next_delimiter_idx) {
                if($prev_delimiter || $new_delimiter) {
                    $reduced_split_diff[] = [Diff::DELETE, $prev_delimiter];
                    $reduced_split_diff[] = [Diff::INSERT, $new_delimiter];
                }
                $prev_delimiter = '';
                $new_delimiter = '';                
            }
        }
        var_dump($reduced_split_diff);
        return $reduced_split_diff;
    }

    private function apply_patch( $text, $patch ) {
		list($merged, $changes_applied) = $this->dmp->patch_apply( $patch, $text );
        // @TODO: Reason about $changes_applied. Sometimes it contains
        //        false entries when the $merged value looks great.
        return $merged;
    }

	public function apply_diff( $text, $diff ) {
        $patch = $this->dmp->patch_make($text, $diff);
		return $this->dmp->patch_apply( $patch, $text );
	}

	public function diff( $old_string, $new_string ) {
		return $this->dmp->diff_main( $old_string, $new_string );
	}

    public function rebase_diff( $base_diff, $diff_to_rebase, $document_after_base_diff ) {
        // Convert the diffs to format that makes rebasing easier
        $diff_a = self::dmp_diff_to_annotated_diff($base_diff);
        $diff_b = self::dmp_diff_to_annotated_diff($diff_to_rebase);

        // Do the rebase
        $i_a = 0;
        $i_b = 0;

        $rebased_diff = [];
        $accumulated_shift = 0;
        while($i_b < count($diff_b)) {
            $change_b = $diff_b[$i_b];
            $change_b['start'] += $accumulated_shift;
            if(!isset($diff_a[$i_a])) {
                $rebased_diff[] = $change_b;
                $i_b++;
                continue;
            }

            $change_a = $diff_a[$i_a];

            if($change_a['start'] === $change_b['start']) {
                /**
                 * version a: {"level": 1}
                 * version b: {"level": 20}
                 * patch b:   =10\t-1\t+20
                 * 
                 * version c: {"level": 3}
                 * patch c:   =10\t-1\t+3
                 * 
                 * If we apply insertions from both patches, we'll get {"level": 320}
                 * which is not what we want. Let's throw and fall back to the fuzzy
                 * merging from diff-match-patch.
                 */
                if($change_a['type'] === Diff::INSERT && $change_b['type'] === Diff::INSERT) {
                    throw new MergeConflictException('Two insertions at the same start position');
                }
            }
            
            if($change_b['start'] < $change_a['start']) {
                $rebased_diff[] = $change_b;
                $i_b++;
            } else {
                switch($change_a['type']) {
                    case Diff::INSERT:
                        $accumulated_shift += $change_a['length'];
                        break;
                    case Diff::DELETE:
                        if($change_a['start'] + $change_a['length'] > $change_b['start']) {
                            switch($change_b['type']) {
                                case Diff::INSERT:
                                    if($change_a['start'] !== $change_b['start']) {
                                        throw new MergeConflictException('Deletion in A intersects with an insertion in B');
                                    }
                                    break;
                                case Diff::DELETE:
                                    if($change_b['start'] + $change_b['length'] <= $change_a['start'] + $change_a['length']) {
                                        // If deletion B is contained within deletion A, we can just ignore it
                                        $i_b++;
                                        // var_dump([
                                        //     'change_a' => $change_a,
                                        //     'change_b' => $change_b,
                                        // ]);
                                        if($change_b['start'] === $change_a['start']) {
                                            // Diff b already accounts for the shift from this change, let's add it to
                                            // the accumulator to make sure we won't count it twice.
                                            $accumulated_shift += $change_b['length'];
                                        }
                                        continue 3;
                                    } else {
                                        // Otherwise we can merge the two deletions
                                        $merged_deletion = [
                                            'type' => Diff::DELETE,
                                            'start' => $change_a['start'],
                                            'length' => $change_b['length'] + ($change_b['start'] - $change_b['start']),
                                        ];
                                        // Store the deleted substring for debugging
                                        $merged_deletion['string'] = mb_substr($document_after_base_diff, $merged_deletion['start'], $merged_deletion['length']);
                                        $rebased_diff[] = $merged_deletion;
                                        // Move past both deletions
                                        $i_b++;
                                        $i_a++;
                                    }
                                    break;
                            }
                        }

                        // Shift by the number of characters that are being deleted, but
                        // only up to the point where deletion A starts.
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
        $dmp_diff = [];
        $cursor_in_original_doc = 0;
        foreach($rebased_diff as $change) {
            if($change['start'] > $cursor_in_original_doc) {
                $length = $change['start'] - $cursor_in_original_doc;
                $dmp_diff[] = [
                    Diff::EQUAL,
                    substr($document_after_base_diff, $cursor_in_original_doc, $length),
                ];
                $cursor_in_original_doc += $length;
            }
            switch($change['type']) {
                case Diff::INSERT:
                    $dmp_diff[] = [
                        Diff::INSERT,
                        $change['string'],
                    ];
                    break;
                case Diff::DELETE:
                    $length = $change['start'] + $change['length'] - $cursor_in_original_doc;
                    $dmp_diff[] = [
                        Diff::DELETE,
                        substr($document_after_base_diff, $cursor_in_original_doc, $length),
                    ];
                    $cursor_in_original_doc += $length;
                    break;
            }
        }

        if($cursor_in_original_doc < strlen($document_after_base_diff)) {
            $dmp_diff[] = [
                Diff::EQUAL,
                substr($document_after_base_diff, $cursor_in_original_doc, strlen($document_after_base_diff) - $cursor_in_original_doc),
            ];
        }

        print_r([
            'diff' => $base_diff,
            'diff_a' => $this->diff_as_delta($base_diff),
            'diff_b' => $this->diff_as_delta($diff_to_rebase),
            'diff_r' => $this->diff_as_delta($dmp_diff),
        ]);
        return $dmp_diff;
    }

    public function diff_as_delta($diff) {
        $delta = [];
        foreach($diff as $change) {
            switch($change[0]) {
                case Diff::EQUAL:
                    $delta[] = '=' . strlen($change[1]);
                    break;
                case Diff::INSERT:
                    $delta[] = '+' . $change[1];
                    break;
                case Diff::DELETE:
                    $delta[] = '-' . strlen($change[1]);
            }
        }
        return implode('|', $delta);
    }

    private static function dmp_diff_to_annotated_diff($diff) {
        $cursor_in_original_doc = 0;
        $annotated_diff = [];
        foreach($diff as $change) {
            switch($change[0]) {
                case Diff::EQUAL:
                    $cursor_in_original_doc += mb_strlen($change[1]);
                    break;
                case Diff::INSERT:
                    $annotated_change = [
                        'type' => Diff::INSERT,
                        'start' => $cursor_in_original_doc,
                        'length' => mb_strlen($change[1]),
                        'string' => $change[1],
                    ];
                    $cursor_in_original_doc += $annotated_change['length'];
                    $annotated_diff[] = $annotated_change;
                    break;
                case Diff::DELETE:
                    $annotated_change = [
                        'type' => Diff::DELETE,
                        'start' => $cursor_in_original_doc,
                        'length' => mb_strlen($change[1]),
                        'string' => $change[1],
                    ];
                    $cursor_in_original_doc += $annotated_change['length'];
                    $annotated_diff[] = $annotated_change;
                    break;
            }
        }
        return $annotated_diff;
    }

}

class ChangeStack {
    private $changes;
    private $cursor_in_original_doc = 0;

    public function __construct() {
        $this->changes = [];
        $this->cursor_in_original_doc = 0;
    }

    public function next_change() {
        $change = $this->changes[$this->cursor_in_original_doc];
        $this->cursor_in_original_doc++;
        return $change;
    }
    
}
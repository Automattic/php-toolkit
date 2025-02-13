<?php

namespace WordPress\Git\Diff;

use DiffMatchPatch\Diff;
use DiffMatchPatch\DiffMatchPatch;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use \WP_HTML_Processor;

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
 * ## Usage
 * 
 * For best results, feed this class block markup where:
 *
 * * All the HTML tags are closed
 * * All the named character references are expanded, e.g. it contains ' and not &apos;
 * * Block openers and closers are stored in their own lines
 * * Top-level tag openers and closers in every block are stored in their own lines
 * * There are no spaces or tabs at the beginning of the line
 * * Long fragments of text are stored as single, long lines without breaking them
 *   into multiple lines
 * 
 * You don't have to go through these steps, but they seem to reduce changes of getting
 * a MergeConflictException.
 * 
 * From there, you can run:
 * 
 * try {
 *     $diff = $driver->three_way_diff( $parent, $changeA, $changeB )
 *     $merged = $driver->three_way_merge( $diff );
 * } catch ( MergeConflictException $e ) {
 *     // Handle merge conflict – choose a different merge strategy or
 *     // add $changeA as a history entry and set the latest version to
 *     // $changeB.
 * }
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
 * ## TODOs
 * 
 * * @TODO Swap the Diff-Match-Patch implementation to a faster one. The library
 *   used right now can take a second on about 9000 characters of text.
 * 
 * ## Future work
 * 
 * * Explore low-cost techniques of matching block IDs and performing the fuzzy
 *   diff on their textual content when all the trees are similar.
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
	public function three_way_merge( $chunks ) {
        try {
            $merge_result = DiffUtils::three_way_merge_chunks( $chunks );
            $this->assert_merge_result_is_structurally_sound( $merge_result );
            return $merge_result;
        } catch(MergeConflictException $e) {
            throw $e;
        } catch(\Exception $e) {
            throw new MergeConflictException('Merge resulted in an error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function assert_merge_result_is_structurally_sound( $html ) {
        /**
         * Validate the block markup. We treat any warning during parsing
         * as a failure.
         */
        $block_markup_processor = new BlockMarkupProcessor($html);
        while($block_markup_processor->next_token()) {
            $error = $block_markup_processor->get_last_error();
            if($error) {
                throw new MergeConflictException( 'Merge resulted in invalid block markup', 0, $error );
            }
        }

        if(count($block_markup_processor->get_block_breadcrumbs()) > 0) {
            throw new MergeConflictException(sprintf(
                'Merge resulted in an unclosed blocks: %s',
                implode(' > ', $block_markup_processor->get_block_breadcrumbs())
            ));
        }

        /**
         * Validate the resulting HTML
         */

        // Validate the entire document
        $this->assert_html_is_structurally_sound($html);

        // Validate the inner HTML of each block separately in case
        // there's a structural error spanning the block boundary.
        $block_markup_processor = new BlockMarkupProcessor($html);
        while($block_markup_processor->next_token()) {
            if($block_markup_processor->get_token_type() !== '#block-comment') {
                continue;
            }
            $inner_html = $block_markup_processor->skip_and_get_block_inner_html();
            $this->assert_html_is_structurally_sound($inner_html);
        }
    }

    private function assert_html_is_structurally_sound( $html ) {
        $html .= '<TERMINATE-PROCESSING>';
        $html_processor = WP_HTML_Processor::create_fragment($html);
        $seen_terminate_tag = false;
        while($html_processor->next_token()) {
            $error = $html_processor->get_last_error();
            if($error) {
                throw new MergeConflictException(
                    'Merge resulted in invalid block markup',
                    0,
                    $html_processor->get_unsupported_exception()
                );
            }

            /**
             * If merging three normative HTML documents yields a non-normative HTML
             * document with virtual tags, the structure is likely corrupted.
             * 
             * @TODO: is_virtual() is private. Let's review this with Dennis Snell.
             */
            if($html_processor->is_virtual()) {
                throw new MergeConflictException(<<<MESSAGE
                    "Merge resulted in a non-normative block markup. The inputs are assumed to be normative,
                    which means the merge result is likely corrupted.
                MESSAGE );
            }

            /**
             * Workaround to let us inspect the stack of open elements right before
             * the HTML processor implicitly generates virtual closers for the open
             * elements.
             * 
             * @TODO Remove the synthetic <TERMINATE-PROCESSING> tag once the HTML
             * processor supports streaming. We'll be able to communicate we're
             * still waiting for more input and do not wish to close open elements
             * just because we've processed the entire HTML chunk.
             */
            if($html_processor->get_tag() === 'TERMINATE-PROCESSING') {
                $seen_terminate_tag = true;
                $breadcrumbs = $html_processor->get_breadcrumbs();
                if($breadcrumbs !== ['HTML', 'BODY', 'TERMINATE-PROCESSING']) {
                    array_pop($breadcrumbs);
                    throw new MergeConflictException(sprintf(
                        'Merge resulted in unclosed tags – the document likely got corrupted: %s',
                        implode(' > ', $breadcrumbs)
                    ));
                }
                break;
            }
        }
        
        /**
         * If we haven't stopped at <TERMINATE-PROCESSING>, it means the merged document
         * ended with RCData, unfinished tag opener, or another type of HTML syntax that
         * prevented the processor from recognizing the tag. This is a structural error
         * and we won't let the caller consume that document.
         */
        if(!$seen_terminate_tag) {
            throw new MergeConflictException( 'Merging resulted in a structurally corrupted document.' );
        }
    }

    /**
     * 
     */
    public function three_way_diff( $common_parent, $version_b, $version_c ) {
        $a = $common_parent;
        $b = $version_b;
        $c = $version_c;

        $diff_ab = $this->diff($a, $b);
        $diff_ac = $this->diff($a, $c);

        $boundaries = DiffUtils::extractBoundaries($diff_ab, $diff_ac);
        $diff_ab = DiffUtils::resliceDiff($diff_ab, $boundaries);
        $diff_ac = DiffUtils::resliceDiff($diff_ac, $boundaries);

        $chunksA = DiffUtils::convertDiffToChunks($diff_ab);
        $chunksB = DiffUtils::convertDiffToChunks($diff_ac);

        return [$chunksA, $chunksB];
    }

	public function apply_diff( $text, $diff ) {
        return DiffUtils::get_final_text($diff);
	}

	public function diff( $old_string, $new_string ) {
		$diff = $this->dmp->diff_main( $old_string, $new_string, false );
        $this->dmp->diff_cleanupSemantic($diff);
        $this->dmp->diff_cleanupEfficiency($diff);
        return $diff;
	}

    private function diff_cleanup_block_boundaries( $document_a, $document_b, $diff ) {
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

}

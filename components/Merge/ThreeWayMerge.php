<?php

namespace WordPress\Merge;

use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use WordPress\Merge\MergeConflictException;
use WP_HTML_Processor;

class ThreeWayMerge {

    static public function merge_as_chunks($diff_ab, $diff_ac): string {
        try {
            list($chunksA, $chunksB) = self::ensure_chunks($diff_ab, $diff_ac);
    
            $result = "";

            $n = max(count($chunksA), count($chunksB));

            for ($i = 0; $i < $n; $i++) {
                $chunkA = $chunksA[$i] ?? ["base" => null, "deleted" => false, "inserted" => ""];
                $chunkB = $chunksB[$i] ?? ["base" => null, "deleted" => false, "inserted" => ""];

                if ($chunkA["inserted"] !== "" && $chunkB["inserted"] !== "" && $chunkA["inserted"] !== $chunkB["inserted"]) {
                    throw new MergeConflictException(sprintf(
                        "Conflicting insertions at the same index at chunk %d. A: %s B: %s",
                        $i,
                        $chunkA["inserted"],
                        $chunkB["inserted"]
                    ));
                }

                if ($chunkA["base"] === null || $chunkB["base"] === null) {
                    // Only one operation has a base text.
                    if ($chunkA["base"] !== null) {
                        $result .= $chunkA["base"] . $chunkA["inserted"];
                    } elseif ($chunkB["base"] !== null) {
                        $result .= $chunkB["base"] . $chunkB["inserted"];
                    }
                    continue;
                }
                
                if ($chunkA["base"] !== $chunkB["base"]) {
                    // Make sure the base texts match.
                    throw new MergeConflictException("Base texts do not match at chunk $i.");
                }
                
                if ( $chunkA["deleted"] || $chunkB["deleted"] ) {
                    // DELETE resolution

                    /**
                     * Trade-off not taken – skipping every chunk deleted
                     * in either A or B.
                     * 
                     * // continue;
                     * 
                     * Why not?
                     * 
                     * Say we work with a 5 paragraph article where both branches
                     * modified something in each of the paragraphs. Depending on
                     * the diff representation, we might end up deleting 4 of the
                     * paragraphs. That's never what we want so let's avoid such
                     * eager deletion.
                     * 
                     * Also, imagine the following structured data scenario:
                     * 
                     * A: <p class="wp-paragraph">
                     * B: <p class="wp-bold wp-paragraph">
                     * C: <p class="wp-italics wp-paragraph">
                     * 
                     * Also, assume we'll get the following chunks:
                     * 
                     * AB: [
                     *  "base" => "paragraph",
                     *  "deleted" => true,
                     *  "inserted" => "bold"
                     * ]
                     * 
                     * AC: [
                     *  "base" => "paragraph",
                     *  "deleted" => false,
                     *  "inserted" => "italics"
                     * ]
                     * 
                     * If we just skipped over this pair of chunks,
                     * we'd end up with a corrupted class name:
                     * 
                     * <p class="wp- wp-paragraph">
                     */
                    
                    // Forget all the chunks deleted in both versions
                    if($chunkA["deleted"] && $chunkB["deleted"]) {
                        continue;
                    }

                    /**
                     * Exactly one of the chunks implies a deletion of the
                     * base text. It means we have:
                     * 
                     * * A chunk that's either a deletion or a replacement
                     * * A chunk that's either an insertion or unchanged
                     * 
                     * We need to handle 2x2 = 4 cases here.
                     */

                    // First, let's identify the deletion chunk.
                    $deletion = $chunkA["deleted"] ? $chunkA : $chunkB;
                    $non_deletion = $chunkA["deleted"] ? $chunkB : $chunkA;

                    if($deletion["inserted"]) {
                        if($non_deletion["inserted"] !== "") {
                            // Replacement and insertion: Merge conflict.
                            // We don't have a good way of deciding which
                            // changes to preserve and which to discard.
                            throw new MergeConflictException("Replacement and insertion at chunk $i.");
                        } else {
                            // Replacement and unchanged: perform the replacement.
                            $result .= $deletion["inserted"];
                        }
                    } else {
                        if($non_deletion["inserted"] !== "") {
                            // Deletion and insertion: the deletion wins.
                            // One of the branches modified a part of the document
                            // the other branch discarded. We're assuming this region
                            // was intended for deletion and removing it from the
                            // result.
                            continue;
                        } else {
                            // Deletion and unchanged: the deletion wins.
                            // We skip over both chunks.
                            continue;
                        }
                    }
                    continue;
                }

                // Both chunks refer to the same base text and neither
                // is a deletion. Let's add that base to the merge result.
                $result .= $chunkA["base"];

                // We have at most one inserted text at this point. Let's
                // add it to the merge result.
                $only_insertion = $chunkA["inserted"] !== "" ? $chunkA["inserted"] : $chunkB["inserted"];
                $result .= $only_insertion;
            }

            return $result;
        } catch(MergeConflictException $e) {
            throw $e;
        } catch(\Exception $e) {
            throw new MergeConflictException('Merge resulted in an error: ' . $e->getMessage(), 0, $e);
        }
    }

    static public function assert_block_markup_merge_is_structurally_sound( $html ) {
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
        self::assert_html_is_structurally_sound($html);

        // Validate the inner HTML of each block separately in case
        // there's a structural error spanning the block boundary.
        $block_markup_processor = new BlockMarkupProcessor($html);
        while($block_markup_processor->next_token()) {
            if($block_markup_processor->get_token_type() !== '#block-comment') {
                continue;
            }
            $inner_html = $block_markup_processor->skip_and_get_block_inner_html();
            self::assert_html_is_structurally_sound($inner_html);
        }
    }

    static private function assert_html_is_structurally_sound( $html ) {
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

    static public function ensure_chunks( $diff_ab_or_chunk_ab, $diff_ac_or_chunk_ac ) {
        if(isset($diff_ab_or_chunk_ab[0]['base']) || isset($diff_ac_or_chunk_ac[0]['base'])) {
            return [$diff_ab_or_chunk_ab, $diff_ac_or_chunk_ac];
        }
        $diff_ab = $diff_ab_or_chunk_ab;
        $diff_ac = $diff_ac_or_chunk_ac;
        $boundaries = self::extractBoundaries($diff_ab, $diff_ac);
        $diff_ab = self::resliceDiff($diff_ab, $boundaries);
        $diff_ac = self::resliceDiff($diff_ac, $boundaries);

        $chunksA = self::convertDiffToChunks($diff_ab);
        $chunksB = self::convertDiffToChunks($diff_ac);

        return [$chunksA, $chunksB];
    }

    static private function convertDiffToChunks(array $diff): array {
        $chunks = [];
        $current = ["base" => null, "deleted" => false, "inserted" => ""];

        foreach ($diff as $part) {
            list($op, $text) = $part;

            if ($op === -1 || $op === 0) {
                if ($current["base"] !== null || $current["inserted"] !== "") {
                    $chunks[] = $current;
                    $current = ["base" => null, "deleted" => false, "inserted" => ""];
                }
                $current["base"] = $text;
                $current["deleted"] = ($op === -1);
            } elseif ($op === 1) {
                $current["inserted"] .= $text;
            }
        }

        if ($current["base"] !== null || $current["inserted"] !== "") {
            $chunks[] = $current;
        }

        return $chunks;
    }

    static private function extractBoundaries(array $diffA, array $diffB): array {
        $boundaries = [];
        foreach ([$diffA, $diffB] as $diff) {
            $offset = 0;
            foreach ($diff as [$op, $text]) {
                if ($op === 1) {
                    // Don't include insertion points, just base text
                    continue;
                }
                if($offset !== 0) {
                    $boundaries[$offset] = true;
                }
                $offset += strlen($text);
            }
        }
        $boundaries = array_keys($boundaries);
        sort($boundaries);
        return $boundaries;
    }
    
    static private function resliceDiff(array $diff, array $boundaries): array {
        $boundaries = array_values($boundaries);
        $resliced = [];
        $baseCursor = 0;
        $boundaryIndex = 0;
    
        foreach ($diff as $k=>[$op, $text]) {
            if(!$text) {
                continue;
            }
            if($op === 1) {
                $resliced[] = [$op, $text];
                continue;
            }
    
            $textLength = strlen($text);
            $startOffset = $baseCursor;
    
            while (
                $boundaryIndex < count($boundaries) && 
                $boundaries[$boundaryIndex] <= $startOffset
            ) {
                $boundaryIndex++;
            }
    
            while (
                $boundaryIndex < count($boundaries) && 
                $boundaries[$boundaryIndex] <= $startOffset + $textLength
            ) {
                $boundary = $boundaries[$boundaryIndex];
    
                $sliceLength = $boundary - $startOffset;
                if($sliceLength > 0 && strlen($text) > 0) {
                    $sliceText = substr($text, 0, $sliceLength);
                    $resliced[] = [$op, $sliceText];
                }
    
                $text = substr($text, $sliceLength);
                $startOffset += $sliceLength;
                $boundaryIndex++;
                if(!$text) {
                    break;
                }
            }
    
            if ($text !== '') {
                $resliced[] = [$op, $text];
            }
    
            $baseCursor += $textLength;
        }
    
        return $resliced;
    }

}
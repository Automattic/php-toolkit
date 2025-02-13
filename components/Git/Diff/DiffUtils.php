<?php

namespace WordPress\Git\Diff;

class DiffUtils {

    static public function extractBoundaries(array $diffA, array $diffB): array {
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
    
    static public function resliceDiff(array $diff, array $boundaries): array {
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

    static public function get_final_text( $diff ) {
        $merged_c = [];
        foreach($diff as $change) {
            switch($change[0]) {
                case 0:
                    $merged_c[] = $change[1];
                    break;
                case 1:
                    $merged_c[] = $change[1];
                    break;
            }
        }
        return implode('', $merged_c);
    }


    static public function convertDiffToChunks(array $diff): array {
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

    static public function print_diff_chunks(array $chunks): void {
        list($chunks_a, $chunks_b) = $chunks;

        $width = (int) shell_exec('tput cols') - 20;
        $half_width = (int) ($width / 2);
        $empty_line = str_repeat(" ", $half_width);

        echo "\n";
        $headerA = str_pad("Version A", $half_width, " ", STR_PAD_BOTH);
        $headerB = str_pad("Version B", $half_width, " ", STR_PAD_BOTH);
        echo "     \033[1m" . $headerA . " | " . $headerB . "\033[0m\n";
        echo str_repeat("-", $width) . "\n";
        
        $n = max(count($chunks_a), count($chunks_b));
        for ($i = 0; $i < $n; $i++) {
            $chunk_a = $chunks_a[$i];
            $chunk_b = $chunks_b[$i];
            
            $left_lines = explode("\n", format_chunk_side($chunk_a, $half_width));
            $right_lines = explode("\n", format_chunk_side($chunk_b, $half_width));

            $max_lines = max(count($left_lines), count($right_lines));
            for($j = 0; $j < $max_lines; $j++) {
                printf(
                    "%3d: %s | %s\n",
                    $i,
                    $left_lines[$j] ?? $empty_line,
                    $right_lines[$j] ?? $empty_line
                );
            }
        }
    }

    static public function three_way_merge_chunks(array $chunks): string {
        list($chunksA, $chunksB) = $chunks;
 
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
    }


}

function mb_wordwrap(string $text, int $width, string $break = "\n", bool $cut = true, string $encoding = "UTF-8"): array {
    // Split text into words while keeping unprintable characters
    $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

    $lines = [];
    $current_line = "";

    for ($i=0;$i<count($words);$i++) {
        $word = $words[$i];
        if(str_contains($word, "\n")) {
            $offset = strpos($word, "\n");
            // Slice until the newline character while keeping the number of
            // characters the same.
            $before = substr($word, 0, $offset) . ' ';
            $after = substr($word, $offset + 1);
            array_splice($words, $i, 1, [$before, $after]);
            $i--;
            continue;
        }
        // Strip unprintable characters for length calculation
        $length = mb_strlen($word, $encoding);
        
        // Handle cases where a single word is longer than the width
        if ($cut && $length > $width) {
            if (!empty($current_line)) {
                $lines[] = $current_line;
                $current_line = "";
            }
            while ($length > $width) {
                $chunk = mb_substr($word, 0, $width, $encoding);
                $word = mb_substr($word, $width, null, $encoding);
                $length = mb_strlen($word, $encoding);
                $lines[] = $chunk;
            }
            if ($length > 0) {
                $current_line = $word;
            }
            continue;
        }

        // Check if adding the next word exceeds the width
        $current_length = mb_strlen($current_line, $encoding);

        if ($current_length + $length >= $width) {
            $lines[] = rtrim($current_line, "\n");
            $current_line = $word;
        } else {
            $current_line .= $word;
        }
    }

    if (!empty($current_line)) {
        $lines[] = rtrim($current_line, "\n");
    }

    return $lines;
}

function format_chunk_side(array $chunk, $width): string {
    $text = $chunk["base"] . $chunk["inserted"];
    $ansi_segments = [
        [
            "color" => $chunk["deleted"] ? "\033[101m" : "\033[37m",
            "start" => 0,
            "end" => mb_strlen($chunk["base"])
        ],
        [
            "color" => $chunk["inserted"] ? "\033[102m" : "",
            "start" => mb_strlen($chunk["base"]),
            "end" => mb_strlen($chunk["base"]) + mb_strlen($chunk["inserted"])
        ]
    ];

    $cursor = 0;
    $wrapped = mb_wordwrap($text, $width);
    $next_ansi_segment = array_shift($ansi_segments);
    foreach ($wrapped as $k => $line) {
        $line_start = $cursor;
        $line_end = $line_start + mb_strlen($line);
        $line_shift = 0;
        $padding_length = $width - mb_strlen($line);
        while($next_ansi_segment && !($next_ansi_segment["end"] < $line_start || $next_ansi_segment["start"] >= $line_end)) {
            $start_offset = max(0, $next_ansi_segment["start"] - $cursor) + $line_shift;
            $end_offset = min($line_end, $next_ansi_segment["end"]) + $line_shift;
            $wrapped[$k] = (
                mb_substr(
                    $wrapped[$k],
                    0,
                    $start_offset
                ) .
                $next_ansi_segment["color"] . 
                mb_substr(
                    $wrapped[$k],
                    $start_offset,
                    $end_offset - $start_offset
                ) . 
                "\033[0m" .
                mb_substr(
                    $wrapped[$k],
                    $end_offset
                )
            );
            $line_shift = mb_strlen($next_ansi_segment["color"] . "\033[0m");
            if($next_ansi_segment["end"] <= $line_end) {
                do {
                    $next_ansi_segment = array_shift($ansi_segments);
                } while($next_ansi_segment && $next_ansi_segment['start'] === $next_ansi_segment['end']);
            } else {
                break;
            }
        }
        $cursor = $line_end + 1;

        if($padding_length > 0) {
            // Tab characters have variable length in terminal which breaks the side-by-side formatting.
            // We cannot easily preserve them and display nice diff columns. At the same time, removing
            // them in favor of spaces may confuse the viewer – "why are spaces replaced with spaces here?"
            //
            // @TODO: Investigate how other diff tools solve that problem and find a useful and established
            //        pattern. Perhaps display UTF-8 arrows instead of tabs and dots instead of spaces?
            $wrapped[$k] = str_replace("\t", " ", $wrapped[$k]);
            $wrapped[$k] = trim($wrapped[$k]) . str_repeat(" ", $padding_length);
        }
    }
    return implode("\n", $wrapped);
}

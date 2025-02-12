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

    static public function three_way_merge_chunked(array $a, array $b): string {
        $chunksA = self::convertDiffToChunks($a);
        $chunksB = self::convertDiffToChunks($b);
        $result = "";

        $n = max(count($chunksA), count($chunksB));

        for ($i = 0; $i < $n; $i++) {
            $chunkA = $chunksA[$i] ?? ["base" => null, "deleted" => false, "inserted" => ""];
            $chunkB = $chunksB[$i] ?? ["base" => null, "deleted" => false, "inserted" => ""];

            if ($chunkA["inserted"] !== "" && $chunkB["inserted"] !== "" && $chunkA["inserted"] !== $chunkB["inserted"]) {
                throw new MergeConflictException("Conflicting insertions at chunk $i.");
            }

            $mergedInserted = $chunkA["inserted"] !== "" ? $chunkA["inserted"] : $chunkB["inserted"];

            $baseA = $chunkA["base"];
            $baseB = $chunkB["base"];

            if ($baseA === null && $baseB === null) {
                $result .= $mergedInserted;
                continue;
            } elseif ($baseA === null) {
                $baseText = $baseB;
            } elseif ($baseB === null) {
                $baseText = $baseA;
            } else {
                if ($chunkA["deleted"]) {
                    if($chunkA["inserted"] !== "") {
                        $result .= $chunkA["inserted"];
                    }
                    continue;
                }
                if ($chunkB["deleted"]) {
                    if($chunkB["inserted"] !== "") {
                        $result .= $chunkB["inserted"];
                    }
                    continue;
                }
                if ($baseA !== $baseB) {
                    throw new MergeConflictException("Base texts do not match at chunk $i.");
                }
                $baseText = $baseA;
            }

            $result .= $baseText . $mergedInserted;
        }

        return $result;
    }


}
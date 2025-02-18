<?php

namespace WordPress\Merge\Merge;

use WordPress\Merge\Diff\Diff;

use function WordPress\Merge\Merge\Strategy\sort;

class ChunkMerger implements Merger {

    public $chunksA;
    public $chunksB;

    public function merge(Diff $diffAB, Diff $diffAC): MergeResult {
        list($chunksA, $chunksB) = $this->ensureChunks($diffAB->get_changes(), $diffAC->get_changes());
        $this->chunksA = $chunksA;
        $this->chunksB = $chunksB;

        $results = [];
        $n = max(count($chunksA), count($chunksB));
        for ($i = 0; $i < $n; $i++) {
            $chunkA = $chunksA[$i] ?? ["base" => null, "deleted" => false, "inserted" => ""];
            $chunkB = $chunksB[$i] ?? ["base" => null, "deleted" => false, "inserted" => ""];

            if ($chunkA["inserted"] !== "" && $chunkB["inserted"] !== "" && $chunkA["inserted"] !== $chunkB["inserted"]) {
                $results[] = new MergeConflict(
                    $chunkA["inserted"],
                    $chunkB["inserted"],
                    [
                        'message' => 'Conflicting insertions'
                    ]
                );
                continue;
            }

            if ($chunkA["base"] === null || $chunkB["base"] === null) {
                if ($chunkA["base"] !== null) {
                    $results[] = $chunkA["base"] . $chunkA["inserted"];
                } elseif ($chunkB["base"] !== null) {
                    $results[] = $chunkB["base"] . $chunkB["inserted"];
                }
                continue;
            }

            if ($chunkA["base"] !== $chunkB["base"]) {
                $results[] = new MergeConflict(
                    $chunkA["base"],
                    $chunkB["base"],
                    [
                        'message' => 'Mismatched base lines'
                    ]
                );
                continue;
            }

            if ($chunkA["deleted"] || $chunkB["deleted"]) {
                if ($chunkA["deleted"] && $chunkB["deleted"]) {
                    continue;
                }

                $deletion = $chunkA["deleted"] ? $chunkA : $chunkB;
                $nonDeletion = $chunkA["deleted"] ? $chunkB : $chunkA;

                if ($deletion["inserted"]) {
                    if ($nonDeletion["inserted"] !== "") {
                        $results[] = new MergeConflict(
                            $deletion["inserted"],
                            $nonDeletion["inserted"],
                            [
                                'message' => 'Deletion with conflicting insertion'
                            ]
                        );
                        continue;
                    } else {
                        $results[] = $deletion["inserted"];
                    }
                }
                continue;
            }

            $results[] = $chunkA["base"];
            $onlyInsertion = $chunkA["inserted"] !== "" ? $chunkA["inserted"] : $chunkB["inserted"];
            $results[] = $onlyInsertion;
        }

        return new MergeResult($results);
    }

    static public function ensureChunks(array $diffAB, array $diffAC): array {
        if (isset($diffAB[0]['base']) || isset($diffAC[0]['base'])) {
            return [$diffAB, $diffAC];
        }

        $boundaries = self::extractBoundaries($diffAB, $diffAC);
        $diffAB = self::resliceDiff($diffAB, $boundaries);
        $diffAC = self::resliceDiff($diffAC, $boundaries);

        $chunksA = self::convertDiffToChunks($diffAB);
        $chunksB = self::convertDiffToChunks($diffAC);

        return [$chunksA, $chunksB];
    }

    static private function convertDiffToChunks(array $diff): array {
        $chunks = [];
        $current = ["base" => null, "deleted" => false, "inserted" => ""];

        foreach ($diff as $part) {
            list($op, $text) = $part;

            if ( $op === Diff::DIFF_DELETE || $op === Diff::DIFF_EQUAL) {
                if ($current["base"] !== null || $current["inserted"] !== "") {
                    $chunks[] = $current;
                    $current = ["base" => null, "deleted" => false, "inserted" => ""];
                }
                $current["base"] = $text;
                $current["deleted"] = ( $op === Diff::DIFF_DELETE);
            } elseif ( $op === Diff::DIFF_INSERT) {
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
                if ( $op === Diff::DIFF_INSERT) {
                    continue;
                }
                if ($offset !== 0) {
                    $boundaries[$offset] = true;
                }
                $offset += strlen($text);
            }
        }
        $boundaries = array_keys($boundaries);
        \sort($boundaries);
        return $boundaries;
    }

    static private function resliceDiff(array $diff, array $boundaries): array {
        $boundaries = array_values($boundaries);
        $resliced = [];
        $baseCursor = 0;
        $boundaryIndex = 0;

        foreach ($diff as [$op, $text]) {
            if (!$text) {
                continue;
            }
            if ( $op === Diff::DIFF_INSERT) {
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
                if ($sliceLength > 0 && strlen($text) > 0) {
                    $sliceText = substr($text, 0, $sliceLength);
                    $resliced[] = [$op, $sliceText];
                }

                $text = substr($text, $sliceLength);
                $startOffset += $sliceLength;
                $boundaryIndex++;
                if (!$text) {
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

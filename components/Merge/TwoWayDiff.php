<?php

namespace WordPress\Merge;

use DiffMatchPatch\DiffMatchPatch;

class TwoWayDiff {

    const DIFF_DELETE = -1;
    const DIFF_INSERT = 1;
    const DIFF_EQUAL  = 0;

    const DIFF_OLD_VERSION = 'old-version';
    const DIFF_NEW_VERSION = 'new-version';

    static public function evaluate_diff( $diff, $to_version = self::DIFF_NEW_VERSION ) {
        $merged_c = [];
        foreach($diff as $change) {
            if($change[0] === self::DIFF_EQUAL) {
                $merged_c[] = $change[1];
            } else if ($change[0] === self::DIFF_DELETE && $to_version === self::DIFF_OLD_VERSION) {
                $merged_c[] = $change[1];
            } else if ($change[0] === self::DIFF_INSERT && $to_version === self::DIFF_NEW_VERSION) {
                $merged_c[] = $change[1];
            }
        }
        return implode('', $merged_c);
    }

	static public function myers_diff( $old_string, $new_string ) {
		$dmp = new DiffMatchPatch();
		$diff = $dmp->diff_main( $old_string, $new_string, false );
        $dmp->diff_cleanupSemantic($diff);
        $dmp->diff_cleanupEfficiency($diff);
        return $diff;
	}

	static public function lines_diff( $old_string, $new_string ) {
		$old_lines = explode( "\n", $old_string );
		$new_lines = explode( "\n", $new_string );

		$lcs = self::longest_common_subsequence( $old_lines, $new_lines );

		$old_index = 0;
		$new_index = 0;
		$diff   = array();
		foreach ( $lcs as $match ) {
			while ( $old_index < $match['old_index'] || $new_index < $match['new_index'] ) {
				if ( $old_index < $match['old_index'] ) {
					$diff[] = array(
						self::DIFF_DELETE,
						$old_lines[ $old_index ] . "\n"
					);
					++$old_index;
				}
				if ( $new_index < $match['new_index'] ) {
					$diff[] = array(
						self::DIFF_INSERT,
						$new_lines[ $new_index ] . "\n"
					);
					++$new_index;
				}
			}

			// Add matching line as context
			if ( $old_index < count( $old_lines ) && $new_index < count( $new_lines ) ) {
                $diff[] = array(
                    self::DIFF_EQUAL,
                    $old_lines[ $old_index ] . "\n"
                );
				++$old_index;
				++$new_index;
			}
		}

		// Add remaining lines
		while ( $old_index < count( $old_lines ) ) {
            $diff[] = array(
                self::DIFF_DELETE,
                $old_lines[ $old_index ] . "\n"
            );
			++$old_index;
		}
		while ( $new_index < count( $new_lines ) ) {
            $diff[] = array(
                self::DIFF_INSERT,
                $new_lines[ $new_index ] . "\n"
            );
			++$new_index;
		}

		return $diff;
	}

	static private function longest_common_subsequence( $old_lines, $new_lines ) {
		$old_len   = count( $old_lines );
		$new_len   = count( $new_lines );
		$lcsMatrix = array_fill( 0, $old_len + 1, array_fill( 0, $new_len + 1, 0 ) );

		// Build the LCS matrix
		for ( $i = 1; $i <= $old_len; $i++ ) {
			for ( $j = 1; $j <= $new_len; $j++ ) {
				if ( $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
					$lcsMatrix[ $i ][ $j ] = $lcsMatrix[ $i - 1 ][ $j - 1 ] + 1;
				} else {
					$lcsMatrix[ $i ][ $j ] = max( $lcsMatrix[ $i - 1 ][ $j ], $lcsMatrix[ $i ][ $j - 1 ] );
				}
			}
		}

		// Backtrack to find the LCS
		$lcs = array();
		$i   = $old_len;
		$j   = $new_len;
		while ( $i > 0 && $j > 0 ) {
			if ( $old_lines[ $i - 1 ] === $new_lines[ $j - 1 ] ) {
				$lcs[] = array(
					'old_index' => $i - 1,
					'new_index' => $j - 1,
				);
				--$i;
				--$j;
			} elseif ( $lcsMatrix[ $i - 1 ][ $j ] >= $lcsMatrix[ $i ][ $j - 1 ] ) {
				--$i;
			} else {
				--$j;
			}
		}

		return array_reverse( $lcs );
	}

	static public function format_as_git_patch( $diff, $options = array() ) {
		$options['contextLines'] ??= 3;
		$options['a_source']     ??= 'a/string';
		$options['b_source']     ??= 'b/string';

		// Format the diff to Git-style with context
		$formatted_diff  = 'diff --git ' . $options['a_source'] . ' ' . $options['b_source'] . "\n";
		$formatted_diff .= '--- ' . $options['a_source'] . "\n";
		$formatted_diff .= '+++ ' . $options['b_source'] . "\n";

		$changed_blocks = array();
		$current_block  = array();

		$last_changed_lineno = null;
		foreach ( $diff as $lineno => $change ) {
            $type = $change[0];
            $line = $change[1];
			if ( $type === self::DIFF_EQUAL ) {
				if ( empty( $current_block ) ) {
					continue;
				}
				if ( $lineno - $last_changed_lineno > $options['contextLines'] ) {
					$changed_blocks[] = $current_block;
					$current_block    = array();
					continue;
				}
			} elseif ( empty( $current_block ) ) {
				$offset        = max( 0, $lineno - $options['contextLines'] - 1 );
				$length        = min( $options['contextLines'], count( $diff ) - $offset ) - 1;
				$current_block = array_slice( $diff, $offset, $length );
			}

			$current_block[] = $line;

			if ( $type !== self::DIFF_EQUAL ) {
				$last_changed_lineno = $lineno;
			}
		}

		if ( ! empty( $current_block ) ) {
			$changed_blocks[] = $current_block;
		}

		foreach ( $changed_blocks as $changes ) {
			$block     = '';
			$old_start = null;
			$new_start = null;
			$oldCount  = 0;
			$newCount  = 0;

			foreach ( $changes as $change ) {
				if ( $change['type'] !== '+' ) {
					if ( $old_start === null ) {
						$old_start = $change['old_index'];
					}
					++$oldCount;
				}
				if ( $change['type'] !== '-' ) {
					if ( $new_start === null ) {
						$new_start = $change['new_index'];
					}
					++$newCount;
				}
			}

			$old_start = $old_start !== null ? $old_start + 1 : 0;
			$new_start = $new_start !== null ? $new_start + 1 : 0;

			$block .= sprintf( '@@ -%d,%d +%d,%d @@', $old_start, $oldCount, $new_start, $newCount );

			foreach ( $changes as $change ) {
				$block .= $change['type'] . ' ' . $change['line'];
			}

			$formatted_diff .= $block;
		}

		return $formatted_diff;
	}

    static public function format_as_delta($diff) {
        $delta = [];
        foreach($diff as $change) {
            switch($change[0]) {
                case self::DIFF_EQUAL:
                    $delta[] = '=' . strlen($change[1]);
                    break;
                case self::DIFF_INSERT:
                    $delta[] = '+' . $change[1];
                    break;
                case self::DIFF_DELETE:
                    $delta[] = '-' . strlen($change[1]);
            }
        }
        return implode('|', $delta);
    }

}

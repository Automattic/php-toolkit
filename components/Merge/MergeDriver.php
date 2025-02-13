<?php

namespace WordPress\Merge;

interface MergeDriver {

	public function three_way_merge_blob( $common_parent, $diff1, $diff2 );
	public function apply_diff( $text, $diff );
	public function diff( $old_string, $new_string );
}

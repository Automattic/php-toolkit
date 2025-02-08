<?php

namespace WordPress\Git\Diff;

use DiffMatchPatch\DiffMatchPatch;
use WordPress\Git\GitException;

class DiffMatchPatchMergeDriver { // implements MergeDriver {

    private $dmp;

    public function __construct() {
        $this->dmp = new DiffMatchPatch();
    }

	public function three_way_merge_blob( $common_parent, $diff1, $diff2 ) {
        list($merged, $applied) = $this->apply_diff($common_parent, $diff1);
        if(!$applied) {
            throw new GitException('Diff failed to apply cleanly onto common parent');
        }

        list($merged, $applied) = $this->apply_diff($merged, $diff2);
        if(!$applied) {
            throw new GitException('Diff2 failed to apply cleanly after applying diff1');
        }

		return $merged;
	}

	public function apply_diff( $text, $diff ) {
		return $this->dmp->patch_apply($diff, $text);
	}

	public function diff( $old_string, $new_string ) {
        return $this->dmp->patch_make($old_string, $new_string);
	}

}

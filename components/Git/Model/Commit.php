<?php

namespace WordPress\Git\Model;

use WordPress\Git\GitException;

class Commit {

	public const NULL_HASH = '0000000000000000000000000000000000000000';

	/**
	 * The commit hash
	 *
	 * @var string
	 */
	public $hash;

	/**
	 * The tree hash this commit points to
	 *
	 * @var string
	 */
	public $tree;

	/**
	 * Array of parent commit hashes
	 *
	 * @var array
	 */
	public $parents = array();

	/**
	 * The commit author details
	 *
	 * @var string
	 */
	public $author;

	/**
	 * The author date
	 *
	 * @var string
	 */
	public $author_date;

	/**
	 * The committer details
	 *
	 * @var string
	 */
	public $committer;

	/**
	 * The committer date
	 *
	 * @var string
	 */
	public $committer_date;

	/**
	 * The commit message
	 *
	 * @var string
	 */
	public $message;

	/**
	 * The GPG signature
	 *
	 * @var string
	 */
	public $gpgsig;

	public static function is_null_hash( $oid ) {
		return $oid === null || $oid === self::NULL_HASH;
	}

	public function __construct( $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				throw new GitException( "Invalid commit property: $key" );
			}
			$this->$key = $value;
		}
	}

	public function get_first_parent_hash() {
		if ( is_array( $this->parents ) ) {
			return $this->parents[0];
		}
		return $this->parents;
	}
}

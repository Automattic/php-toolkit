<?php

namespace WordPress\Filesystem\Layer;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\Filesystem\Filesystem;

use function WordPress\Filesystem\wp_canonicalize_path;
use function WordPress\Filesystem\wp_join_paths;

/**
 * A filesystem wrapper that chroot's the filesystem to a specific path.
 */
class ChrootLayer extends Layer {

	/**
	 * @var string
	 */
	private $chroot;

	/**
	 * @param Filesystem $fs The filesystem to chroot.
	 * @param string     $root The root path to chroot to.
	 */
	function __construct( Filesystem $fs, $chroot ) {
		$this->chroot = rtrim( $chroot, '/' );
		parent::__construct( $fs );
	}

	/**
	 * Transforms an absolute path or relative path to be contained within a chroot.
	 *
	 * @param string $path The path to normalize.
	 * @return string The normalized path.
	 */
	public function normalize_path( $path ) {
		return wp_join_paths( $this->chroot, wp_canonicalize_path( $path ) );
	}

	public function exists( $path ) {
		$path = $this->normalize_path( $path );
		return $this->fs->exists( $path );
	}

	public function is_file( $path ) {
		$path = $this->normalize_path( $path );
		return $this->fs->is_file( $path );
	}

	public function is_dir( $path ) {
		$path = $this->normalize_path( $path );
		return $this->fs->is_dir( $path );
	}

	public function mkdir( $path, $options = array() ) {
		$path = $this->normalize_path( $path );
		return $this->fs->mkdir( $path, $options );
	}

	public function rm( $path, $options = array() ) {
		$path = $this->normalize_path( $path );
		return $this->fs->rm( $path, $options );
	}

	public function rmdir( $path, $options = array() ) {
		$path = $this->normalize_path( $path );
		return $this->fs->rmdir( $path, $options );
	}

	public function ls( $path = '/' ) {
		$path = $this->normalize_path( $path );
		return $this->fs->ls( $path );
	}

	public function open_read_stream( $path ): ByteReadStream {
		$path = $this->normalize_path( $path );
		return $this->fs->open_read_stream( $path );
	}

	public function open_write_stream( $path ): ByteWriteStream {
		$path = $this->normalize_path( $path );
		return $this->fs->open_write_stream( $path );
	}

	public function copy( $source, $destination, $options = array() ) {
		$source      = $this->normalize_path( $source );
		$destination = $this->normalize_path( $destination );
		return $this->fs->copy( $source, $destination, $options );
	}

	public function rename( $source, $destination, $options = array() ) {
		$source      = $this->normalize_path( $source );
		$destination = $this->normalize_path( $destination );
		return $this->fs->rename( $source, $destination, $options );
	}

	public function get_contents( $path ) {
		$path = $this->normalize_path( $path );
		return $this->fs->get_contents( $path );
	}

	public function put_contents( $path, $contents, $options = array() ) {
		$path = $this->normalize_path( $path );
		return $this->fs->put_contents( $path, $contents, $options );
	}
}

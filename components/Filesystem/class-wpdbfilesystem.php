<?php

namespace WordPress\Filesystem;

// Table names are configured per-instance and cannot be passed through
// $wpdb->prepare(). All user-supplied values use placeholders.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

use Exception;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\Mixin\BufferedWriteStreamViaPutContents;
use WordPress\Filesystem\Mixin\CopyRecursiveViaStreaming;
use WordPress\Filesystem\Mixin\MkdirRecursive;

/**
 * Stores files in WordPress database tables via the global wpdb instance.
 *
 * Mirrors SQLiteFilesystem's schema and semantics, but writes to two
 * MySQL tables managed through the wpdb instance handed to it. Lets a
 * GitRepository (or any other Filesystem consumer) persist its on-disk
 * layout in WordPress without ever touching the host filesystem.
 *
 * The class deliberately holds no behaviour beyond translating the
 * Filesystem interface into SQL — all WordPress integration concerns
 * (table prefixing, lifecycle, multisite scoping) are pushed up to the
 * caller via the table-prefix argument.
 */
class WpdbFilesystem implements Filesystem {

	use BufferedWriteStreamViaPutContents;
	use MkdirRecursive;
	use CopyRecursiveViaStreaming;

	/**
	 * @var object wpdb-compatible instance.
	 */
	private $wpdb;
	private $files_table;
	private $entries_table;
	private $transaction_level = 0;

	/**
	 * Create a chrooted WpdbFilesystem rooted at "/".
	 *
	 * @param object $wpdb         The wpdb instance to use.
	 * @param string $table_prefix Prefix for the two backing tables, e.g.
	 *                             "{$wpdb->prefix}pmd_". Two tables are
	 *                             created: "{$prefix}files" and
	 *                             "{$prefix}directory_entries".
	 */
	public static function create( $wpdb, $table_prefix ) {
		$fs = new self( $wpdb, $table_prefix );
		$fs->install_schema();

		return new ChrootLayer( $fs, '/' );
	}

	/**
	 * Drop the two backing tables. Used by callers that want to wipe
	 * the filesystem entirely — e.g. retrying a botched initial
	 * import. The next call to `create()` will re-create the schema.
	 */
	public static function drop_tables( $wpdb, $table_prefix ) {
		$files_table   = $table_prefix . 'files';
		$entries_table = $table_prefix . 'directory_entries';
		$wpdb->query( "DROP TABLE IF EXISTS {$files_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$entries_table}" );
	}

	private function __construct( $wpdb, $table_prefix ) {
		$this->wpdb          = $wpdb;
		$this->files_table   = $table_prefix . 'files';
		$this->entries_table = $table_prefix . 'directory_entries';
	}

	public function get_root(): string {
		return '/';
	}

	private function install_schema() {
		$charset_collate = '';
		if ( method_exists( $this->wpdb, 'get_charset_collate' ) ) {
			$charset_collate = $this->wpdb->get_charset_collate();
		}

		// CREATE TABLE IF NOT EXISTS keeps the call idempotent and avoids
		// requiring a separate activation hook. Schema mirrors
		// SQLiteFilesystem so behaviour stays in lockstep.
		$this->wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$this->files_table} (
				path VARCHAR(191) NOT NULL,
				type VARCHAR(8) NOT NULL,
				contents LONGBLOB NULL,
				PRIMARY KEY (path)
			) {$charset_collate}"
		);
		$this->wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$this->entries_table} (
				parent_path VARCHAR(191) NOT NULL,
				name VARCHAR(191) NOT NULL,
				PRIMARY KEY (parent_path, name)
			) {$charset_collate}"
		);

		$root_exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT 1 FROM {$this->files_table} WHERE path = %s",
				'/'
			)
		);
		if ( ! $root_exists ) {
			$this->replace_file_record( '/', 'dir', null );
		}
	}

	public function ls( $path = '/' ) {
		$rows = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT name FROM {$this->entries_table} WHERE parent_path = %s",
				$path
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function is_dir( $path ) {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT 1 FROM {$this->files_table} WHERE path = %s AND type = %s",
				$path,
				'dir'
			)
		);

		return null !== $found;
	}

	public function is_file( $path ) {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT 1 FROM {$this->files_table} WHERE path = %s AND type = %s",
				$path,
				'file'
			)
		);

		return null !== $found;
	}

	public function exists( $path ) {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT 1 FROM {$this->files_table} WHERE path = %s",
				$path
			)
		);

		return null !== $found;
	}

	public function open_read_stream( $path ): ByteReadStream {
		return new MemoryPipe( $this->get_contents( $path ) );
	}

	public function get_contents( $path ) {
		if ( ! $this->is_file( $path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $path )
			);
		}

		return $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT contents FROM {$this->files_table} WHERE path = %s",
				$path
			)
		);
	}

	public function rename( $from_path, $to_path, $options = array() ) {
		if ( ! $this->exists( $from_path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $from_path )
			);
		}

		$parent = wp_unix_dirname( $to_path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $from_path, $to_path ) {
					// Make the rename idempotent. Git's atomic-write
					// pattern (write to .tmp, rename to the
					// content-addressed final path) can replay the same
					// final path many times — once per identical blob.
					// Without this DELETE the UPDATE would hit a PK
					// collision and silently leave the .tmp row in
					// place, corrupting the object store.
					if ( $from_path !== $to_path ) {
						$this->wpdb->delete(
							$this->files_table,
							array( 'path' => $to_path ),
							array( '%s' )
						);
					}

					$this->wpdb->update(
						$this->files_table,
						array( 'path' => $to_path ),
						array( 'path' => $from_path ),
						array( '%s' ),
						array( '%s' )
					);

					$old_parent = wp_unix_dirname( $from_path );
					$new_parent = wp_unix_dirname( $to_path );

					$this->wpdb->delete(
						$this->entries_table,
						array(
							'parent_path' => $old_parent,
							'name'        => basename( $from_path ),
						),
						array( '%s', '%s' )
					);
					$this->wpdb->replace(
						$this->entries_table,
						array(
							'parent_path' => $new_parent,
							'name'        => basename( $to_path ),
						),
						array( '%s', '%s' )
					);
				}
			);

			return true;
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to rename file: %s to %s', $from_path, $to_path ),
				0,
				$e
			);
		}
	}

	public function mkdir_single( $path, $options = array() ) {
		if ( $this->exists( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Directory already exists: %s', $path )
			);
		}

		$parent = wp_unix_dirname( $path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $path ) {
					$parent = wp_unix_dirname( $path );

					$this->replace_file_record( $path, 'dir', null );
					$this->assert_db_result(
						$this->wpdb->insert(
							$this->entries_table,
							array(
								'parent_path' => $parent,
								'name'        => basename( $path ),
							),
							array( '%s', '%s' )
						),
						'Failed to create directory entry: ' . $path
					);
				}
			);

			return true;
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to create directory: %s', $path ),
				0,
				$e
			);
		}
	}

	public function rm( $path ) {
		if ( ! $this->is_file( $path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $path )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $path ) {
					$parent = wp_unix_dirname( $path );

					$this->wpdb->delete(
						$this->files_table,
						array( 'path' => $path ),
						array( '%s' )
					);
					$this->wpdb->delete(
						$this->entries_table,
						array(
							'parent_path' => $parent,
							'name'        => basename( $path ),
						),
						array( '%s', '%s' )
					);
				}
			);
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to remove file: %s', $path ),
				0,
				$e
			);
		}
	}

	public function rmdir( $path, $options = array() ) {
		$recursive = isset( $options['recursive'] ) ? $options['recursive'] : false;
		if ( ! $this->is_dir( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Directory not found: %s', $path )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $path, $options, $recursive ) {
					if ( $recursive ) {
						$path = rtrim( $path, '/' );
						foreach ( $this->ls( $path ) as $child ) {
							$child_path = wp_join_unix_paths( $path, $child );
							if ( $this->is_dir( $child_path ) ) {
								$this->rmdir( $child_path, $options );
							} else {
								$this->rm( $child_path );
							}
						}
					}

					$parent = wp_unix_dirname( $path );

					$this->wpdb->delete(
						$this->files_table,
						array( 'path' => $path ),
						array( '%s' )
					);
					$this->wpdb->delete(
						$this->entries_table,
						array(
							'parent_path' => $parent,
							'name'        => basename( $path ),
						),
						array( '%s', '%s' )
					);
				}
			);
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to remove directory: %s', $path ),
				0,
				$e
			);
		}
	}

	public function put_contents( $path, $data, $options = array() ) {
		$parent = wp_unix_dirname( $path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $path, $data ) {
					$parent = wp_unix_dirname( $path );

					$this->replace_file_record( $path, 'file', $data );
					$this->assert_db_result(
						$this->wpdb->replace(
							$this->entries_table,
							array(
								'parent_path' => $parent,
								'name'        => basename( $path ),
							),
							array( '%s', '%s' )
						),
						'Failed to write directory entry: ' . $path
					);
				}
			);

			return true;
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to put contents: %s', $path ),
				0,
				$e
			);
		}
	}

	private function replace_file_record( $path, $type, $contents ) {
		$contents_sql = null === $contents ? 'NULL' : "X'" . bin2hex( $contents ) . "'";
		$result       = $this->wpdb->query(
			$this->wpdb->prepare(
				"REPLACE INTO {$this->files_table} (path, type, contents) VALUES (%s, %s, {$contents_sql})",
				$path,
				$type
			)
		);

		$this->assert_db_result(
			$result,
			'Failed to write file record: ' . $path
		);
	}

	private function assert_db_result( $result, $message ) {
		if ( false !== $result ) {
			return;
		}

		if ( isset( $this->wpdb->last_error ) && '' !== $this->wpdb->last_error ) {
			$message .= ': ' . $this->wpdb->last_error;
		}

		throw new FilesystemException( $message );
	}

	private function in_transaction( $callback ) {
		$current_level = $this->transaction_level++;
		try {
			if ( 0 === $current_level ) {
				$this->wpdb->query( 'START TRANSACTION' );
				try {
					$callback();
					$this->wpdb->query( 'COMMIT' );
				} catch ( Exception $e ) {
					$this->wpdb->query( 'ROLLBACK' );
					throw $e;
				}
			} else {
				$savepoint = 'level_' . $current_level;
				$this->wpdb->query( 'SAVEPOINT ' . $savepoint );
				try {
					$callback();
					$this->wpdb->query( 'RELEASE SAVEPOINT ' . $savepoint );
				} catch ( Exception $e ) {
					$this->wpdb->query( 'ROLLBACK TO SAVEPOINT ' . $savepoint );
					throw $e;
				}
			}
		} finally {
			--$this->transaction_level;
		}
	}

	public function get_meta(): array {
		return array(
			'files_table'   => $this->files_table,
			'entries_table' => $this->entries_table,
		);
	}
}

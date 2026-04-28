<?php

/**
 * Minimal SQLite-backed wpdb shim for unit-testing WpdbFilesystem.
 *
 * Only implements the wpdb surface that WpdbFilesystem touches: prepare,
 * query, get_var, get_col, insert, replace, update, delete. Translates
 * the few MySQL-isms WpdbFilesystem uses (LONGBLOB, VARCHAR(N),
 * START TRANSACTION, INSERT IGNORE) into their SQLite equivalents so
 * the schema and transaction semantics match.
 *
 * Binary data is bound with SQLITE3_BLOB so Git objects round-trip
 * byte-for-byte through put_contents/get_contents.
 */
class FakeWpdb {

	public $prefix = 'wp_';
	public $last_error = '';
	public $last_query = '';

	private $db;

	public function __construct() {
		$this->db = new SQLite3( ':memory:' );
		$this->db->enableExceptions( true );
	}

	public function get_charset_collate() {
		return '';
	}

	public function query( $sql ) {
		$this->last_query = $sql;
		$translated       = $this->translate_sql( $sql );

		try {
			return $this->db->exec( $translated ) ? 1 : 0;
		} catch ( Exception $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	public function prepare( $sql, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		// WpdbFilesystem only ever uses %s placeholders.
		$parts  = explode( '%s', $sql );
		$result = $parts[0];
		$count  = count( $args );
		for ( $i = 0; $i < $count; $i++ ) {
			$result .= "'" . SQLite3::escapeString( (string) $args[ $i ] ) . "'";
			if ( isset( $parts[ $i + 1 ] ) ) {
				$result .= $parts[ $i + 1 ];
			}
		}

		return $result;
	}

	public function get_var( $sql ) {
		$this->last_query = $sql;
		$result           = $this->db->query( $this->translate_sql( $sql ) );
		if ( ! $result ) {
			return null;
		}
		$row = $result->fetchArray( SQLITE3_NUM );

		return false === $row ? null : $row[0];
	}

	public function get_col( $sql ) {
		$this->last_query = $sql;
		$result           = $this->db->query( $this->translate_sql( $sql ) );
		$column           = array();
		if ( ! $result ) {
			return $column;
		}
		while ( $row = $result->fetchArray( SQLITE3_NUM ) ) {
			$column[] = $row[0];
		}

		return $column;
	}

	public function insert( $table, $data, $format = null ) {
		return $this->run_prepared(
			'INSERT INTO ' . $table,
			$data
		);
	}

	public function replace( $table, $data, $format = null ) {
		return $this->run_prepared(
			'INSERT OR REPLACE INTO ' . $table,
			$data
		);
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set_columns   = array();
		$where_columns = array();
		foreach ( array_keys( $data ) as $col ) {
			$set_columns[] = "$col = ?";
		}
		foreach ( array_keys( $where ) as $col ) {
			$where_columns[] = "$col = ?";
		}
		$sql  = 'UPDATE ' . $table . ' SET ' . implode( ', ', $set_columns )
			. ' WHERE ' . implode( ' AND ', $where_columns );
		$stmt = $this->db->prepare( $sql );
		$i    = 1;
		foreach ( $data as $value ) {
			$this->bind_value( $stmt, $i++, $value );
		}
		foreach ( $where as $value ) {
			$this->bind_value( $stmt, $i++, $value );
		}

		return false === $stmt->execute() ? false : 1;
	}

	public function delete( $table, $where, $where_format = null ) {
		$where_columns = array();
		foreach ( array_keys( $where ) as $col ) {
			$where_columns[] = "$col = ?";
		}
		$sql  = 'DELETE FROM ' . $table . ' WHERE ' . implode( ' AND ', $where_columns );
		$stmt = $this->db->prepare( $sql );
		$i    = 1;
		foreach ( $where as $value ) {
			$this->bind_value( $stmt, $i++, $value );
		}

		return false === $stmt->execute() ? false : 1;
	}

	private function run_prepared( $verb_with_table, $data ) {
		$columns      = array_keys( $data );
		$placeholders = array_fill( 0, count( $data ), '?' );
		$sql          = $verb_with_table
			. ' (' . implode( ', ', $columns ) . ')'
			. ' VALUES (' . implode( ', ', $placeholders ) . ')';
		$stmt         = $this->db->prepare( $sql );
		$i            = 1;
		foreach ( $data as $value ) {
			$this->bind_value( $stmt, $i++, $value );
		}

		return false === $stmt->execute() ? false : 1;
	}

	private function bind_value( SQLite3Stmt $stmt, $position, $value ) {
		if ( null === $value ) {
			$stmt->bindValue( $position, null, SQLITE3_NULL );

			return;
		}
		if ( is_int( $value ) ) {
			$stmt->bindValue( $position, $value, SQLITE3_INTEGER );

			return;
		}
		// PHP's SQLite3 driver truncates SQLITE3_TEXT bindings at the
		// first NUL, so values containing NULs must bind as BLOB to
		// round-trip byte-for-byte. Path/name columns never contain
		// NULs and benefit from TEXT binding so SQLite's type-affinity
		// comparisons (`WHERE path = '/'`) match TEXT-affinity columns.
		$type = ( false === strpos( $value, "\0" ) ) ? SQLITE3_TEXT : SQLITE3_BLOB;
		$stmt->bindValue( $position, $value, $type );
	}

	private function translate_sql( $sql ) {
		$sql = preg_replace( '/\bLONGBLOB\b/i', 'BLOB', $sql );
		$sql = preg_replace( '/\bVARCHAR\(\d+\)/i', 'TEXT', $sql );
		$sql = str_ireplace( 'START TRANSACTION', 'BEGIN', $sql );
		$sql = str_ireplace( 'INSERT IGNORE', 'INSERT OR IGNORE', $sql );

		return $sql;
	}
}

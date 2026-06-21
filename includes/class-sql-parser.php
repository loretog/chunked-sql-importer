<?php
/**
 * Streaming SQL file parser.
 *
 * @package ChunkedSqlImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads SQL dumps incrementally and extracts individual statements.
 */
class CSI_Sql_Parser {

	const READ_BUFFER = 8192;

	/**
	 * Parse the next chunk of a SQL file.
	 *
	 * @param string $file_path   Absolute file path.
	 * @param int    $byte_offset Resume offset.
	 * @param int    $start_seq   Next sequence number.
	 * @param int    $max_items   Max statements per batch.
	 * @param int    $time_limit  Seconds budget.
	 * @param array  $options     Parser options.
	 * @return array{
	 *   statements: array<int, array{seq:int,type:string,table:?string,sql:string}>,
	 *   byte_offset: int,
	 *   eof: bool,
	 *   next_seq: int
	 * }
	 */
	public static function parse_chunk( $file_path, $byte_offset, $start_seq, $max_items = 100, $time_limit = 20, array $options = array() ) {
		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			throw new RuntimeException( __( 'Unable to open SQL file.', 'chunked-sql-importer' ) );
		}

		if ( $byte_offset > 0 ) {
			fseek( $handle, $byte_offset );
		}

		$started_at   = microtime( true );
		$statements   = array();
		$seq          = $start_seq;
		$buffer       = '';
		$in_single    = false;
		$in_double    = false;
		$in_backtick  = false;
		$in_line_comment = false;
		$in_block_comment = false;
		$current      = '';

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::READ_BUFFER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$length = strlen( $chunk );
			for ( $i = 0; $i < $length; $i++ ) {
				$char      = $chunk[ $i ];
				$next_char = ( $i + 1 < $length ) ? $chunk[ $i + 1 ] : '';

				if ( $in_line_comment ) {
					$current .= $char;
					if ( "\n" === $char ) {
						$in_line_comment = false;
					}
					continue;
				}

				if ( $in_block_comment ) {
					$current .= $char;
					if ( '*' === $char && '/' === $next_char ) {
						$current .= $next_char;
						$in_block_comment = false;
						++$i;
					}
					continue;
				}

				if ( ! $in_single && ! $in_double && ! $in_backtick ) {
					if ( '-' === $char && '-' === $next_char ) {
						$in_line_comment = true;
						$current        .= $char;
						continue;
					}
					if ( '#' === $char ) {
						$in_line_comment = true;
						$current        .= $char;
						continue;
					}
					if ( '/' === $char && '*' === $next_char ) {
						$in_block_comment = true;
						$current         .= $char;
						continue;
					}
				}

				if ( "'" === $char && ! $in_double && ! $in_backtick ) {
					if ( $in_single && "'" === $next_char ) {
						$current .= "''";
						++$i;
						continue;
					}
					$in_single = ! $in_single;
					$current  .= $char;
					continue;
				}

				if ( '"' === $char && ! $in_single && ! $in_backtick ) {
					if ( $in_double && '"' === $next_char ) {
						$current .= '""';
						++$i;
						continue;
					}
					$in_double = ! $in_double;
					$current  .= $char;
					continue;
				}

				if ( '`' === $char && ! $in_single && ! $in_double ) {
					$in_backtick = ! $in_backtick;
					$current    .= $char;
					continue;
				}

				if ( ';' === $char && ! $in_single && ! $in_double && ! $in_backtick ) {
					$statement = self::normalize_statement( $current );
					$current   = '';

					if ( '' !== $statement && ! self::should_skip( $statement, $options ) ) {
						$meta         = self::classify_statement( $statement );
						$statements[] = array(
							'seq'   => $seq,
							'type'  => $meta['type'],
							'table' => $meta['table'],
							'sql'   => $statement,
						);
						++$seq;

						if ( count( $statements ) >= $max_items ) {
							$new_offset = ftell( $handle ) - ( $length - $i - 1 );
							fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

							return array(
								'statements'  => $statements,
								'byte_offset' => (int) $new_offset,
								'eof'         => false,
								'next_seq'    => $seq,
							);
						}

						if ( ( microtime( true ) - $started_at ) >= $time_limit ) {
							$new_offset = ftell( $handle ) - ( $length - $i - 1 );
							fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

							return array(
								'statements'  => $statements,
								'byte_offset' => (int) $new_offset,
								'eof'         => false,
								'next_seq'    => $seq,
							);
						}
					}

					continue;
				}

				$current .= $char;
			}
		}

		$new_offset = (int) ftell( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$tail = self::normalize_statement( $current );
		if ( '' !== $tail && ! self::should_skip( $tail, $options ) ) {
			$meta         = self::classify_statement( $tail );
			$statements[] = array(
				'seq'   => $seq,
				'type'  => $meta['type'],
				'table' => $meta['table'],
				'sql'   => $tail,
			);
			++$seq;
		}

		return array(
			'statements'  => $statements,
			'byte_offset' => $new_offset,
			'eof'         => true,
			'next_seq'    => $seq,
		);
	}

	/**
	 * Trim and collapse whitespace in a statement.
	 *
	 * @param string $sql Raw SQL fragment.
	 * @return string
	 */
	private static function normalize_statement( $sql ) {
		$sql = trim( $sql );
		if ( '' === $sql ) {
			return '';
		}

		return preg_replace( '/\s+/', ' ', $sql );
	}

	/**
	 * Determine whether a statement should be skipped.
	 *
	 * @param string $sql     SQL statement.
	 * @param array  $options Parser options.
	 * @return bool
	 */
	private static function should_skip( $sql, array $options ) {
		$upper = strtoupper( ltrim( $sql ) );

		$always_skip = array(
			'LOCK TABLES',
			'UNLOCK TABLES',
		);

		foreach ( $always_skip as $prefix ) {
			if ( 0 === strpos( $upper, $prefix ) ) {
				return true;
			}
		}

		if ( ! empty( $options['skip_drop'] ) && 0 === strpos( $upper, 'DROP ' ) ) {
			return true;
		}

		if ( ! empty( $options['skip_create'] ) && 0 === strpos( $upper, 'CREATE ' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Classify a SQL statement.
	 *
	 * @param string $sql SQL statement.
	 * @return array{type: string, table: ?string}
	 */
	private static function classify_statement( $sql ) {
		$upper = strtoupper( ltrim( $sql ) );

		$type = 'other';
		if ( preg_match( '/^(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|SET|USE|REPLACE)\b/', $upper, $matches ) ) {
			$type = strtolower( $matches[1] );
		}

		$table = null;
		if ( preg_match( '/^(?:INSERT(?:\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|REPLACE\s+INTO)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $matches ) ) {
			$table = $matches[1];
		}

		return array(
			'type'  => $type,
			'table' => $table,
		);
	}
}

<?php
/**
 * Generate a large .sql file for testing chunked uploads/imports.
 *
 * Usage:
 *   php generate-test-sql.php [target-mb] [output-file]
 *
 * Examples:
 *   php generate-test-sql.php 50
 *   php generate-test-sql.php 100 ../../uploads/sql-imports/inbox/large-test-100mb.sql
 */

$target_mb = isset( $argv[1] ) ? max( 1, (int) $argv[1] ) : 50;
$output    = isset( $argv[2] ) ? $argv[2] : dirname( __DIR__, 3 ) . '/uploads/sql-imports/inbox/large-test-' . $target_mb . 'mb.sql';

$target_bytes = $target_mb * 1024 * 1024;
$dir          = dirname( $output );

if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
	fwrite( STDERR, "Could not create directory: {$dir}\n" );
	exit( 1 );
}

$handle = fopen( $output, 'wb' );
if ( ! $handle ) {
	fwrite( STDERR, "Could not open output file: {$output}\n" );
	exit( 1 );
}

$header = <<<'SQL'
-- Chunked SQL Importer test file (generated)
-- Safe to import: uses a dedicated test table only.

DROP TABLE IF EXISTS `csi_import_test_rows`;

CREATE TABLE `csi_import_test_rows` (
  `id` bigint(20) unsigned NOT NULL,
  `batch_id` int(10) unsigned NOT NULL,
  `payload` varchar(512) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SQL;

fwrite( $handle, $header );
$written = strlen( $header );

$row_id   = 1;
$batch_id = 1;
$payload  = str_repeat( 'x', 400 );

while ( $written < $target_bytes ) {
	$line = sprintf(
		"INSERT INTO `csi_import_test_rows` (`id`, `batch_id`, `payload`, `created_at`) VALUES (%d, %d, '%s', '2026-06-18 12:00:00');\n",
		$row_id,
		$batch_id,
		$payload
	);

	fwrite( $handle, $line );
	$written += strlen( $line );
	++$row_id;

	if ( 0 === $row_id % 10000 ) {
		++$batch_id;
	}
}

fclose( $handle );

$size_human = round( filesize( $output ) / 1024 / 1024, 2 );

echo "Generated: {$output}\n";
echo "Size: {$size_human} MB\n";
echo "Rows: " . ( $row_id - 1 ) . "\n";

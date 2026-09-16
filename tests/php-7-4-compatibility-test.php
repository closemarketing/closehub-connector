<?php

declare( strict_types=1 );

function closehub_php74_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

$plugin_dir = dirname( __DIR__ );
$composer    = json_decode( file_get_contents( $plugin_dir . '/composer.json' ), true );

closehub_php74_assert( '^7.4 || ^8.0' === $composer['require']['php'], 'Composer must support PHP 7.4.' );

$plugin_header = file_get_contents( $plugin_dir . '/closehub-connector.php' );
$readme        = file_get_contents( $plugin_dir . '/readme.txt' );

closehub_php74_assert( false !== strpos( $plugin_header, 'Requires PHP:      7.4' ), 'The plugin header must require PHP 7.4.' );
closehub_php74_assert( false !== strpos( $readme, 'Requires PHP: 7.4' ), 'The readme must require PHP 7.4.' );

$source_files = array_merge(
	[ $plugin_dir . '/closehub-connector.php', $plugin_dir . '/uninstall.php' ],
	glob( $plugin_dir . '/includes/*.php' )
);

foreach ( $source_files as $source_file ) {
	$source = file_get_contents( $source_file );
	$name   = basename( $source_file );

	closehub_php74_assert( ! preg_match( '/\)\s*:\s*[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*\|/', $source ), $name . ' must not use PHP 8 union return types.' );
	closehub_php74_assert( ! preg_match( '/function\s+\w+\s*\([^)]*\bmixed\s+\$|\)\s*:\s*mixed\b/', $source ), $name . ' must not use the PHP 8 mixed type.' );
	closehub_php74_assert( false === strpos( $source, 'str_starts_with(' ), $name . ' must not use PHP 8 string helpers.' );
	closehub_php74_assert( ! preg_match( '/function\s+__construct\s*\([^)]*\b(?:public|protected|private)\b/', $source ), $name . ' must not use PHP 8 constructor property promotion.' );
}

echo "PHP 7.4 compatibility checks passed.\n";

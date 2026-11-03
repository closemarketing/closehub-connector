<?php

declare( strict_types=1 );

$closehub_abspath = sys_get_temp_dir() . '/closehub-unused-' . uniqid() . '/';
mkdir( $closehub_abspath . 'wp-admin/includes', 0777, true );
touch( $closehub_abspath . 'wp-admin/includes/plugin.php' );
define( 'ABSPATH', $closehub_abspath );

class WP_Error {}

$GLOBALS['closehub_test_multisite'] = true;
function is_multisite(): bool { return $GLOBALS['closehub_test_multisite']; }
function get_option( string $name, mixed $default = false ): mixed { return [ 'main/main.php' ]; }
function get_site_option( string $name, mixed $default = false ): mixed { return [ 'network/network.php' => 1 ]; }
function get_sites( array $args ): array { return [ 1, 2, 3 ]; }
function get_blog_option( int $site_id, string $name, mixed $default = false ): mixed { return [ 2 => [ 'only-on-site-2/plugin.php' ] ][ $site_id ] ?? []; }
function get_plugins(): array {
	$names = [ 'main/main.php', 'network/network.php', 'only-on-site-2/plugin.php', 'nobody/nobody.php' ];
	return array_combine( $names, array_map( static fn( string $file ): array => [ 'Name' => $file, 'Version' => '1.0' ], $names ) );
}

require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';

$result = CloseHub_Site_Abilities::list_unused_plugins();
$files  = array_column( $result['plugins'], 'file' );
unlink( ABSPATH . 'wp-admin/includes/plugin.php' );
rmdir( ABSPATH . 'wp-admin/includes' );
rmdir( ABSPATH . 'wp-admin' );
rmdir( ABSPATH );

if ( [ 'nobody/nobody.php' ] !== $files ) { fwrite( STDERR, 'Only a plugin active on no site at all may be listed as unused, got: ' . implode( ', ', $files ) . "\n" ); exit( 1 ); }

echo "Unused plugins multisite checks passed.\n";

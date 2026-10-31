<?php

defined( 'ABSPATH' ) || exit;

/**
 * Install or replace a plugin/theme from a zip on disk, the same way the
 * admin upload screens do (Plugin_Upgrader / Theme_Upgrader with
 * overwrite_package), plus what a deploy needs on top:
 *
 * - the zip must hold exactly one top-level folder, and it must match the
 *   expected slug, so one package can never overwrite a different one;
 * - the installed copy is zipped into a backup before it is replaced, and
 *   rollback() reinstalls any of the last BACKUPS_TO_KEEP backups;
 * - WP Rocket and OPcache are cleared after a change.
 *
 * Shared by the MCP abilities (base64 payload) and the multipart REST
 * routes in CloseHub_Package_REST (zip uploaded from disk with curl).
 */
class CloseHub_Package_Installer {
	public const TYPES           = [ 'plugin', 'theme' ];
	public const BACKUPS_TO_KEEP = 5;

	/**
	 * @param array{slug?: string, overwrite?: bool, activate?: bool, backup?: bool, clear_cache?: bool} $options
	 */
	public static function install( string $type, string $zip, array $options = [] ): array|WP_Error {
		$options += [ 'slug' => '', 'overwrite' => true, 'activate' => false, 'backup' => true, 'clear_cache' => true ];

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'closehub_package_invalid_type', 'type must be plugin or theme.', [ 'status' => 400 ] );
		}
		if ( '' !== $options['slug'] && ! self::valid_slug( $options['slug'] ) ) {
			return new WP_Error( 'closehub_package_invalid_slug', 'slug must be a plugin or theme folder name.', [ 'status' => 400 ] );
		}
		if ( ! wp_is_file_mod_allowed( 'closehub_package_install' ) ) {
			return new WP_Error( 'closehub_file_mods_disabled', 'File modifications are disabled on this site (DISALLOW_FILE_MODS).', [ 'status' => 403 ] );
		}
		if ( $options['activate'] && ! current_user_can( 'plugin' === $type ? 'activate_plugins' : 'switch_themes' ) ) {
			return new WP_Error( 'closehub_forbidden', 'plugin' === $type ? 'You cannot activate plugins.' : 'You cannot switch themes.', [ 'status' => 403 ] );
		}

		$slug = self::zip_root( $zip, $type );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		if ( '' !== $options['slug'] && $slug !== $options['slug'] ) {
			return new WP_Error( 'closehub_package_slug_mismatch', sprintf( 'The zip contains the "%1$s" folder, not the expected "%2$s".', $slug, $options['slug'] ), [ 'status' => 400 ] );
		}

		$before = self::installed( $type, $slug );
		if ( $before && ! $options['overwrite'] ) {
			return new WP_Error( 'closehub_package_exists', sprintf( 'The %1$s "%2$s" is already installed. Send overwrite=true to replace it.', $type, $slug ), [ 'status' => 409 ] );
		}

		if ( $before && ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
			return new WP_Error( 'closehub_forbidden', sprintf( 'Replacing an installed %1$s also requires the update_%1$ss capability.', $type ), [ 'status' => 403 ] );
		}

		$backup = null;
		if ( $before && $options['backup'] ) {
			$backup = self::backup( $type, $slug, $before['version'] );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = 'plugin' === $type ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $zip, [ 'overwrite_package' => (bool) $options['overwrite'] ] );
		$messages = array_values( array_map( static fn( $message ) => html_entity_decode( wp_strip_all_tags( (string) $message ), ENT_QUOTES, 'UTF-8' ), (array) $skin->get_upgrade_messages() ) );

		if ( is_wp_error( $result ) || ! $result ) {
			$errors  = $skin->get_errors();
			$message = is_wp_error( $result ) ? $result->get_error_message() : ( is_wp_error( $errors ) && $errors->has_errors() ? $errors->get_error_message() : '' );
			return new WP_Error(
				is_wp_error( $result ) ? $result->get_error_code() : 'closehub_package_install_failed',
				$message ?: ( implode( ' ', $messages ) ?: ucfirst( $type ) . ' installation failed.' ),
				[ 'status' => 500, 'upgrader_messages' => $messages, 'backup' => $backup ]
			);
		}

		'plugin' === $type ? wp_clean_plugins_cache( true ) : wp_clean_themes_cache();
		$after = self::installed( $type, $slug );
		if ( ! $after ) {
			return new WP_Error( 'closehub_package_install_failed', sprintf( 'The %1$s was installed but "%2$s" could not be read back.', $type, $slug ), [ 'status' => 500, 'upgrader_messages' => $messages, 'backup' => $backup ] );
		}

		$activation_error = null;
		if ( $options['activate'] && ! $after['active'] ) {
			$activation_error = self::activate( $type, $slug, $after );
			$after            = self::installed( $type, $slug ) ?? $after;
		}

		$output = [
			'type'              => $type,
			'slug'              => $slug,
			'name'              => $after['name'],
			'previous_version'  => $before['version'] ?? null,
			'new_version'       => $after['version'],
			'replaced'          => null !== $before,
			'active'            => $after['active'],
			'backup'            => $backup,
			'upgrader_messages' => $messages,
		];
		if ( $activation_error ) {
			$output['activation_error'] = $activation_error;
		}
		if ( $options['clear_cache'] ) {
			$output['cache'] = self::clear_caches();
		}
		return $output;
	}

	/** Reinstall a backup made by install(). Defaults to the most recent one. */
	public static function rollback( string $type, string $slug, string $backup = '' ): array|WP_Error {
		if ( ! in_array( $type, self::TYPES, true ) || ! self::valid_slug( $slug ) ) {
			return new WP_Error( 'closehub_package_invalid_slug', 'A valid type (plugin or theme) and slug are required.', [ 'status' => 400 ] );
		}
		$backups = self::backups( $type, $slug );
		if ( ! $backups ) {
			return new WP_Error( 'closehub_backup_not_found', sprintf( 'There is no backup of the %1$s "%2$s".', $type, $slug ), [ 'status' => 404 ] );
		}
		$file = '' === $backup ? $backups[0]['file'] : basename( $backup );
		if ( ! in_array( $file, array_column( $backups, 'file' ), true ) ) {
			return new WP_Error( 'closehub_backup_not_found', sprintf( 'Backup "%s" was not found.', $file ), [ 'status' => 404 ] );
		}

		// install() backs up the current copy and prunes old backups, which could
		// delete the one being restored, so install from a temporary copy.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$copy = wp_tempnam( $file );
		if ( ! $copy || ! copy( self::backup_dir( $type, $slug ) . '/' . $file, $copy ) ) {
			return new WP_Error( 'closehub_backup_copy_failed', 'Could not read the backup.', [ 'status' => 500 ] );
		}
		$result = self::install( $type, $copy, [ 'slug' => $slug, 'overwrite' => true ] );
		wp_delete_file( $copy );

		if ( is_array( $result ) ) {
			$result['restored_backup'] = $file;
		}
		return $result;
	}

	/** @return array{slug: string, name: string, version: string, active: bool, plugin_file?: string}|null */
	public static function installed( string $type, string $slug ): ?array {
		if ( 'plugin' === $type ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( get_plugins() as $file => $data ) {
				if ( str_starts_with( $file, $slug . '/' ) ) {
					return [ 'slug' => $slug, 'plugin_file' => $file, 'name' => (string) $data['Name'], 'version' => (string) $data['Version'], 'active' => is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ];
				}
			}
			return null;
		}

		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return null;
		}
		return [ 'slug' => $slug, 'name' => (string) $theme->get( 'Name' ), 'version' => (string) $theme->get( 'Version' ), 'active' => get_stylesheet() === $slug || get_template() === $slug ];
	}

	/** @return list<array{file: string, version: string, created: string, size: int}> Newest first. */
	public static function backups( string $type, string $slug ): array {
		$backups = [];
		foreach ( glob( self::backup_dir( $type, $slug ) . '/*.zip' ) ?: [] as $path ) {
			$backups[] = [ 'file' => basename( $path ), 'version' => explode( '--', basename( $path ) )[0], 'created' => gmdate( 'c', (int) filemtime( $path ) ), 'size' => (int) filesize( $path ) ];
		}
		usort( $backups, static fn( $a, $b ) => strcmp( $b['created'] . $b['file'], $a['created'] . $a['file'] ) );
		return $backups;
	}

	public static function clear_caches(): array {
		$cache = [ 'wp_rocket' => null, 'opcache' => null ];
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$cache['wp_rocket'] = CloseHub_Site_Abilities::purge_wp_rocket( 'all', [], true )['cleared'];
		}
		if ( function_exists( 'opcache_reset' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) ) {
			$cache['opcache'] = opcache_reset();
		}
		return $cache;
	}

	public static function valid_slug( string $slug ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) && ! str_contains( $slug, '..' );
	}

	/**
	 * The single top-level folder of the zip, after checking it looks like a
	 * plugin (a PHP file in that folder) or a theme (style.css in it).
	 */
	public static function zip_root( string $zip, string $type ): string|WP_Error {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'closehub_zip_unsupported', 'The PHP zip extension is required to check the package.', [ 'status' => 500 ] );
		}
		$archive = new ZipArchive();
		if ( true !== $archive->open( $zip ) ) {
			return new WP_Error( 'closehub_invalid_zip', 'The file is not a valid zip archive.', [ 'status' => 400 ] );
		}

		$roots      = [];
		$root_files = [];
		for ( $i = 0; $i < $archive->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name = str_replace( '\\', '/', (string) $archive->getNameIndex( $i ) );
			if ( '' === $name || str_starts_with( $name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
				$archive->close();
				return new WP_Error( 'closehub_invalid_zip', 'The zip contains unsafe paths.', [ 'status' => 400 ] );
			}
			if ( str_starts_with( $name, '__MACOSX/' ) ) {
				continue;
			}
			$parts = explode( '/', $name );
			if ( 1 === count( $parts ) ) {
				$archive->close();
				return new WP_Error( 'closehub_package_invalid_structure', sprintf( 'The zip must contain a single %s folder, but "%s" is at its root.', $type, $name ), [ 'status' => 400 ] );
			}
			$roots[ $parts[0] ] = true;
			if ( 2 === count( $parts ) ) {
				$root_files[] = $parts[1];
			}
		}
		$archive->close();

		if ( 1 !== count( $roots ) ) {
			return new WP_Error( 'closehub_package_invalid_structure', sprintf( 'The zip must contain exactly one %s folder; found %d.', $type, count( $roots ) ), [ 'status' => 400 ] );
		}
		$slug = (string) array_key_first( $roots );
		if ( ! self::valid_slug( $slug ) ) {
			return new WP_Error( 'closehub_package_invalid_structure', sprintf( '"%s" is not a valid folder name.', $slug ), [ 'status' => 400 ] );
		}
		$looks_right = 'theme' === $type
			? in_array( 'style.css', $root_files, true )
			: (bool) array_filter( $root_files, static fn( $file ) => str_ends_with( strtolower( $file ), '.php' ) );
		if ( ! $looks_right ) {
			return new WP_Error( 'closehub_package_invalid_structure', 'theme' === $type ? sprintf( 'The "%s" folder has no style.css, so it is not a theme.', $slug ) : sprintf( 'The "%s" folder has no PHP file, so it is not a plugin.', $slug ), [ 'status' => 400 ] );
		}
		return $slug;
	}

	/** uploads/closehub-backups, or the folder of one plugin/theme inside it. */
	public static function backup_dir( string $type = '', string $slug = '' ): string {
		$dir = untrailingslashit( wp_upload_dir( null, false )['basedir'] ) . '/closehub-backups';
		return '' === $type ? $dir : $dir . '/' . $type . '/' . $slug;
	}

	/** Zip the installed folder so install()/rollback() can reinstall it as is. */
	private static function backup( string $type, string $slug, string $version ): string|WP_Error {
		$source = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $slug : get_theme_root( $slug ) . '/' . $slug;
		$root   = self::backup_dir();
		$dir    = self::backup_dir( $type, $slug );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'closehub_backup_failed', 'Could not create the backup folder.', [ 'status' => 500 ] );
		}
		// Backups live in uploads: block direct downloads and directory listing.
		// The random suffix in the file name also covers servers that ignore .htaccess.
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		foreach ( [ $root, $root . '/' . $type, $dir ] as $folder ) {
			if ( ! file_exists( $folder . '/index.php' ) ) {
				file_put_contents( $folder . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

		$version = preg_replace( '/[^A-Za-z0-9._-]/', '', $version ) ?: 'unknown';
		$file    = sprintf( '%1$s--%2$s-%3$s.zip', $version, gmdate( 'Ymd-His' ), strtolower( wp_generate_password( 8, false ) ) );
		$archive = new ZipArchive();
		if ( true !== $archive->open( $dir . '/' . $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'closehub_backup_failed', 'Could not create the backup zip.', [ 'status' => 500 ] );
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		$archive->addEmptyDir( $slug );
		foreach ( $files as $path => $info ) {
			$relative = $slug . '/' . str_replace( '\\', '/', substr( (string) $path, strlen( $source ) + 1 ) );
			$info->isDir() ? $archive->addEmptyDir( $relative ) : $archive->addFile( (string) $path, $relative );
		}
		if ( ! $archive->close() ) {
			wp_delete_file( $dir . '/' . $file );
			return new WP_Error( 'closehub_backup_failed', 'Could not write the backup zip.', [ 'status' => 500 ] );
		}

		foreach ( array_slice( self::backups( $type, $slug ), self::BACKUPS_TO_KEEP ) as $old ) {
			wp_delete_file( $dir . '/' . $old['file'] );
		}
		return $file;
	}

	private static function activate( string $type, string $slug, array $installed ): ?string {
		if ( 'theme' === $type ) {
			switch_theme( $slug );
			return null;
		}
		$result = activate_plugin( $installed['plugin_file'] );
		return is_wp_error( $result ) ? $result->get_error_message() : null;
	}
}

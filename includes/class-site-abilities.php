<?php

defined( 'ABSPATH' ) || exit;

/**
 * MCP abilities for the CLOSE web go-live checklist (see GitHub issue #20):
 * operations that today are manual or wp-cli-only steps when publishing a
 * WordPress site, exposed the same way CloseHub_Content_Abilities exposes
 * post/order abilities.
 */
class CloseHub_Site_Abilities {
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
		add_filter( 'robots_txt', [ self::class, 'filter_virtual_robots_txt' ], 20 );
	}

	public static function register_category(): void {
		wp_register_ability_category( 'closehub-site', [
			'label'       => 'CloseHub Site',
			'description' => 'Manage WordPress site configuration for the CLOSE go-live checklist.',
		] );
	}

	public static function register_abilities(): void {
		self::ability( 'closehub/search-replace-domain', 'Search-replace domain', 'Replace one domain with another across post content, options, comments, and meta tables, handling serialized values safely. Skips the posts.guid column.', [ self::class, 'search_replace_domain' ], [ self::class, 'can_manage_site' ], false, true, [
			'old_domain' => [ 'type' => 'string' ], 'new_domain' => [ 'type' => 'string' ], 'dry_run' => [ 'type' => 'boolean', 'default' => false ],
		], [ 'old_domain', 'new_domain' ], true );

		self::ability( 'closehub/set-robots-txt', 'Set robots.txt content', 'Write robots.txt with the given content, physically if the site root is writable, otherwise served virtually.', [ self::class, 'set_robots_txt' ], [ self::class, 'can_manage_site' ], false, true, [
			'content' => [ 'type' => 'string' ],
		], [ 'content' ] );

		self::ability( 'closehub/set-post-noindex', 'Set post noindex', 'Set or clear the noindex robots meta for one post/page using the active SEO plugin (Rank Math or Yoast).', [ self::class, 'set_post_noindex' ], [ self::class, 'can_edit_post_meta' ], false, true, [
			'post_id' => [ 'type' => 'integer' ], 'noindex' => [ 'type' => 'boolean' ],
		], [ 'post_id', 'noindex' ] );

		self::ability( 'closehub/flush-permalinks', 'Flush permalinks', 'Regenerate the site\'s rewrite rules (equivalent to `wp rewrite flush`).', [ self::class, 'flush_permalinks' ], [ self::class, 'can_manage_site' ], false, true, [], [], true );

		self::ability( 'closehub/get-htaccess', 'Get .htaccess content', 'Read the current contents of the site\'s .htaccess file, if any.', [ self::class, 'get_htaccess' ], [ self::class, 'can_manage_site' ], true, true, [] );

		self::ability( 'closehub/set-site-settings', 'Set timezone and admin email', 'Update the site timezone and/or admin email address. At least one of the two must be given.', [ self::class, 'set_site_settings' ], [ self::class, 'can_manage_site' ], false, true, [
			'timezone' => [ 'type' => 'string' ], 'admin_email' => [ 'type' => 'string' ],
		] );

		self::ability( 'closehub/reassign-posts-author', 'Bulk reassign post author', 'Reassign every post by one author to another author.', [ self::class, 'reassign_posts_author' ], [ self::class, 'can_reassign_posts' ], false, true, [
			'from_author_id' => [ 'type' => 'integer' ], 'to_author_id' => [ 'type' => 'integer' ], 'post_type' => [ 'type' => 'string', 'default' => 'post' ],
		], [ 'from_author_id', 'to_author_id' ] );

		self::ability( 'closehub/install-wp-org-plugin', 'Install a WordPress.org plugin', 'Install (without activating) a plugin by slug from the WordPress.org plugin directory.', [ self::class, 'install_wp_org_plugin' ], [ self::class, 'can_install_plugins' ], false, false, [
			'slug' => [ 'type' => 'string' ],
		], [ 'slug' ] );

		self::ability( 'closehub/list-unused-plugins', 'List unused plugins', 'List installed plugins that are not currently active, as candidates for removal.', [ self::class, 'list_unused_plugins' ], [ self::class, 'can_view_plugins' ], true, true, [] );

		self::ability( 'closehub/update-plugins-and-core', 'Update plugins and core', 'Update every plugin with an available update and WordPress core to the latest available version.', [ self::class, 'update_plugins_and_core' ], [ self::class, 'can_update_site' ], false, false, [], [], true );

		self::ability( 'closehub/create-site-user', 'Create a client user', 'Create a WordPress user for the site\'s client with the Administrator or Editor role.', [ self::class, 'create_site_user' ], [ self::class, 'can_create_user' ], false, false, [
			'email' => [ 'type' => 'string' ], 'username' => [ 'type' => 'string' ], 'role' => [ 'type' => 'string', 'enum' => [ 'administrator', 'editor' ], 'default' => 'editor' ], 'send_notification' => [ 'type' => 'boolean', 'default' => true ],
		], [ 'email' ] );
	}

	private static function ability( string $id, string $label, string $description, array $execute, array $permission, bool $readonly, bool $idempotent, array $properties, array $required = [], bool $destructive = false ): void {
		wp_register_ability( $id, [
			'label' => $label, 'description' => $description, 'category' => 'closehub-site',
			'input_schema' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required ],
			'execute_callback' => $execute, 'permission_callback' => $permission,
			'meta' => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $idempotent ] ],
		] );
	}

	// ── Permission callbacks ───────────────────────────────────────────────────

	public static function can_manage_site(): bool { return current_user_can( 'manage_options' ); }
	public static function can_edit_post_meta( $input ): bool { return current_user_can( 'edit_post', absint( $input['post_id'] ?? 0 ) ); }
	public static function can_reassign_posts(): bool { return current_user_can( 'edit_others_posts' ); }
	public static function can_install_plugins(): bool { return current_user_can( 'install_plugins' ); }
	public static function can_view_plugins(): bool { return current_user_can( 'activate_plugins' ); }
	public static function can_update_site(): bool { return current_user_can( 'update_plugins' ) && current_user_can( 'update_core' ); }

	/** A caller may only request the administrator role if they can promote users to it. */
	public static function can_create_user( $input ): bool {
		$role = sanitize_key( (string) ( is_array( $input ) ? ( $input['role'] ?? 'editor' ) : 'editor' ) );
		return current_user_can( 'create_users' ) && ( 'administrator' !== $role || current_user_can( 'promote_users' ) );
	}

	// ── Domain search-replace ──────────────────────────────────────────────────

	/**
	 * There is no WordPress core API for a generic, serialization-safe
	 * search-replace across tables, so this reads/writes $wpdb directly
	 * (the same exception AGENTS.md documents for CloseHub_OAuth's tables).
	 * Table and column names below are fixed identifiers, never user input,
	 * so they are safe to interpolate; only values go through $wpdb->prepare().
	 */
	public static function search_replace_domain( $input ): array|WP_Error {
		global $wpdb;

		$from = trim( (string) ( $input['old_domain'] ?? '' ) );
		$to   = trim( (string) ( $input['new_domain'] ?? '' ) );
		if ( '' === $from || '' === $to ) {
			return new WP_Error( 'closehub_search_replace_invalid', 'old_domain and new_domain are required.', [ 'status' => 400 ] );
		}

		$dry_run = ! empty( $input['dry_run'] );
		$tables  = [
			$wpdb->options     => [ 'primary' => 'option_id', 'columns' => [ 'option_value' ] ],
			$wpdb->posts       => [ 'primary' => 'ID', 'columns' => [ 'post_content', 'post_content_filtered', 'post_title', 'post_excerpt' ] ], // guid intentionally skipped
			$wpdb->postmeta    => [ 'primary' => 'meta_id', 'columns' => [ 'meta_value' ] ],
			$wpdb->comments    => [ 'primary' => 'comment_ID', 'columns' => [ 'comment_content', 'comment_author_url' ] ],
			$wpdb->commentmeta => [ 'primary' => 'meta_id', 'columns' => [ 'meta_value' ] ],
			$wpdb->usermeta    => [ 'primary' => 'umeta_id', 'columns' => [ 'meta_value' ] ],
			$wpdb->termmeta    => [ 'primary' => 'meta_id', 'columns' => [ 'meta_value' ] ],
		];

		$report = [];
		foreach ( $tables as $table => $spec ) {
			$changed = 0;
			foreach ( $spec['columns'] as $column ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table/$column are fixed identifiers above, not user input.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$spec['primary']} AS row_id, {$column} AS value FROM {$table} WHERE {$column} LIKE %s", '%' . $wpdb->esc_like( $from ) . '%' ) );

				foreach ( $rows as $row ) {
					$new_value = self::replace_serialized_value( (string) $row->value, $from, $to );
					if ( $new_value === $row->value ) {
						continue;
					}
					++$changed;
					if ( ! $dry_run ) {
						$wpdb->update( $table, [ $column => $new_value ], [ $spec['primary'] => $row->row_id ] );
					}
				}
			}
			if ( $changed > 0 ) {
				$report[ $table ] = $changed;
			}
		}

		return [ 'dry_run' => $dry_run, 'changed_rows' => $report, 'total_changed_rows' => array_sum( $report ) ];
	}

	/** Replace $from with $to inside a value, re-serializing it if it was a serialized PHP value. */
	public static function replace_serialized_value( string $value, string $from, string $to ): string {
		if ( is_serialized( $value ) ) {
			$unserialized = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $unserialized || 'b:0;' === $value ) {
				return serialize( self::recursive_replace( $unserialized, $from, $to ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
		}
		return str_replace( $from, $to, $value );
	}

	private static function recursive_replace( mixed $data, string $from, string $to ): mixed {
		if ( is_string( $data ) ) {
			return str_replace( $from, $to, $data );
		}
		if ( is_array( $data ) ) {
			$out = [];
			foreach ( $data as $key => $value ) {
				$out[ is_string( $key ) ? str_replace( $from, $to, $key ) : $key ] = self::recursive_replace( $value, $from, $to );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = self::recursive_replace( $value, $from, $to );
			}
		}
		return $data;
	}

	// ── robots.txt ──────────────────────────────────────────────────────────────

	public static function set_robots_txt( $input ): array|WP_Error {
		$content = (string) ( $input['content'] ?? '' );
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'closehub_robots_txt_empty', 'content is required.', [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$path = trailingslashit( ABSPATH ) . 'robots.txt';
			if ( $wp_filesystem->is_writable( ABSPATH ) && $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
				return [ 'mode' => 'physical', 'path' => $path ];
			}
		}

		update_option( 'closehub_virtual_robots_txt', $content );
		return [ 'mode' => 'virtual' ];
	}

	/** Served via the robots_txt filter when no physical robots.txt could be written. */
	public static function filter_virtual_robots_txt( string $output ): string {
		$content = get_option( 'closehub_virtual_robots_txt' );
		return $content ? $content : $output;
	}

	// ── Per-page noindex ────────────────────────────────────────────────────────

	public static function set_post_noindex( $input ): array|WP_Error {
		$post_id = absint( $input['post_id'] ?? 0 );
		$noindex = ! empty( $input['noindex'] );

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'closehub_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $post_id, 'rank_math_robots', $noindex ? [ 'noindex' ] : [] );
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $noindex ? '1' : '0' );
		} else {
			return new WP_Error( 'closehub_seo_plugin_missing', 'No supported SEO plugin (Rank Math or Yoast) is active.', [ 'status' => 503 ] );
		}

		return [ 'post_id' => $post_id, 'noindex' => $noindex ];
	}

	// ── Permalinks ──────────────────────────────────────────────────────────────

	public static function flush_permalinks(): array {
		flush_rewrite_rules( false );
		return [ 'flushed' => true ];
	}

	// ── .htaccess ───────────────────────────────────────────────────────────────

	public static function get_htaccess(): array|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$path = trailingslashit( get_home_path() ) . '.htaccess';

		if ( ! file_exists( $path ) ) {
			return [ 'exists' => false, 'content' => null ];
		}
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'closehub_filesystem_unavailable', 'Could not access the filesystem.', [ 'status' => 500 ] );
		}

		global $wp_filesystem;
		return [ 'exists' => true, 'content' => $wp_filesystem->get_contents( $path ) ];
	}

	// ── Timezone / admin email ─────────────────────────────────────────────────

	public static function set_site_settings( $input ): array|WP_Error {
		$timezone    = trim( (string) ( $input['timezone'] ?? '' ) );
		$admin_email = trim( (string) ( $input['admin_email'] ?? '' ) );
		$result      = [];

		if ( '' !== $timezone ) {
			if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
				return new WP_Error( 'closehub_invalid_timezone', 'Invalid timezone identifier.', [ 'status' => 400 ] );
			}
			update_option( 'timezone_string', $timezone );
			update_option( 'gmt_offset', '' );
			$result['timezone'] = $timezone;
		}

		if ( '' !== $admin_email ) {
			if ( ! is_email( $admin_email ) ) {
				return new WP_Error( 'closehub_invalid_email', 'Invalid admin email address.', [ 'status' => 400 ] );
			}
			update_option( 'admin_email', $admin_email );
			$result['admin_email'] = $admin_email;
		}

		if ( ! $result ) {
			return new WP_Error( 'closehub_no_settings_provided', 'Provide at least a timezone or admin_email.', [ 'status' => 400 ] );
		}

		return $result;
	}

	// ── Bulk author reassign ───────────────────────────────────────────────────

	public static function reassign_posts_author( $input ): array|WP_Error {
		$from = absint( $input['from_author_id'] ?? 0 );
		$to   = absint( $input['to_author_id'] ?? 0 );
		if ( ! $from || ! $to ) {
			return new WP_Error( 'closehub_reassign_invalid', 'from_author_id and to_author_id are required.', [ 'status' => 400 ] );
		}
		if ( ! get_userdata( $to ) ) {
			return new WP_Error( 'closehub_reassign_target_missing', 'to_author_id does not match an existing user.', [ 'status' => 404 ] );
		}

		$post_ids = get_posts( [
			'author'      => $from,
			'post_type'   => sanitize_key( (string) ( $input['post_type'] ?? 'post' ) ),
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		] );

		$results = [];
		foreach ( $post_ids as $post_id ) {
			$updated   = wp_update_post( [ 'ID' => $post_id, 'post_author' => $to ], true );
			$results[] = [ 'post_id' => $post_id, 'success' => ! is_wp_error( $updated ) ];
		}

		return [
			'reassigned' => count( array_filter( $results, static fn( $r ) => $r['success'] ) ),
			'total'      => count( $results ),
			'results'    => $results,
		];
	}

	// ── WordPress.org plugin install ───────────────────────────────────────────

	public static function install_wp_org_plugin( $input ): array|WP_Error {
		$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'closehub_plugin_slug_required', 'slug is required.', [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$api = plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'closehub_plugin_install_failed', implode( ' ', $skin->get_upgrade_messages() ) ?: 'Plugin installation failed.', [ 'status' => 500 ] );
		}

		return [ 'slug' => $slug, 'plugin_file' => $upgrader->plugin_info(), 'installed' => true, 'activated' => false ];
	}

	// ── Unused plugins ──────────────────────────────────────────────────────────

	public static function list_unused_plugins(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$active = get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_unique( array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) );
		}

		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			if ( in_array( $file, $active, true ) ) {
				continue;
			}
			$plugins[] = [ 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'] ];
		}

		return [ 'plugins' => $plugins, 'total' => count( $plugins ) ];
	}

	// ── Plugin/core updates ─────────────────────────────────────────────────────

	public static function update_plugins_and_core(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		wp_update_plugins();
		$plugin_updates = get_plugin_updates();
		$plugin_results = [];

		if ( $plugin_updates ) {
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$files    = array_keys( $plugin_updates );
			$results  = $upgrader->bulk_upgrade( $files );

			foreach ( $files as $file ) {
				$plugin_results[] = [ 'plugin' => $file, 'success' => ! empty( $results[ $file ] ) && ! is_wp_error( $results[ $file ] ) ];
			}
		}

		wp_version_check();
		$core_updates = get_core_updates();
		$core_result  = [ 'attempted' => false, 'success' => null ];

		if ( $core_updates && ! empty( $core_updates[0]->response ) && 'latest' !== $core_updates[0]->response ) {
			$core_upgrader            = new Core_Upgrader( new Automatic_Upgrader_Skin() );
			$core_result['attempted'] = true;
			$upgrade_result           = $core_upgrader->upgrade( $core_updates[0] );
			$core_result['success']   = ! is_wp_error( $upgrade_result );
			if ( is_wp_error( $upgrade_result ) ) {
				$core_result['error'] = $upgrade_result->get_error_message();
			}
		}

		return [ 'plugins' => $plugin_results, 'core' => $core_result ];
	}

	// ── Client user creation ────────────────────────────────────────────────────

	public static function create_site_user( $input ): array|WP_Error {
		$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$role     = sanitize_key( (string) ( $input['role'] ?? 'editor' ) );
		$username = sanitize_user( (string) ( $input['username'] ?? '' ), true );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'closehub_invalid_email', 'A valid email is required.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $role, [ 'administrator', 'editor' ], true ) ) {
			return new WP_Error( 'closehub_invalid_role', 'role must be administrator or editor.', [ 'status' => 400 ] );
		}
		if ( '' === $username ) {
			$username = sanitize_user( strstr( $email, '@', true ) ?: $email, true );
		}
		if ( username_exists( $username ) || email_exists( $email ) ) {
			return new WP_Error( 'closehub_user_exists', 'A user with that username or email already exists.', [ 'status' => 409 ] );
		}

		$user_id = wp_insert_user( [
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => $role,
		] );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			add_user_to_blog( get_current_blog_id(), $user_id, $role );
		}

		if ( ! isset( $input['send_notification'] ) || $input['send_notification'] ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		return [ 'user_id' => $user_id, 'username' => $username, 'email' => $email, 'role' => $role ];
	}
}

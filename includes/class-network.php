<?php

defined( 'ABSPATH' ) || exit;

/**
 * Multisite scope layer for MCP abilities.
 *
 * An MCP client connects to one URL (the main site's MCP server) with the
 * network-wide API key, so on a multisite network every ability has to decide
 * which site(s) it acts on. Each ability has one of three scopes:
 *
 * - fanout:   runs on every site (or the `site_ids` given) and returns
 *             `{ "sites": [ { "site_id", "site_url", ...data | "error" } ] }`.
 * - targeted: acts on one site chosen with `site_id` — required on multisite,
 *             because post IDs, files and settings mean different things per
 *             site and must never be applied to the whole network by accident.
 * - once:     network-wide or server-wide operation (plugin install, updates);
 *             runs once on the current site.
 *
 * On a single site none of this applies: input and output are unchanged.
 * Capabilities are re-checked on every target site after switch_to_blog().
 */
class CloseHub_Network {
	public const FANOUT   = 'fanout';
	public const TARGETED = 'targeted';
	public const ONCE     = 'once';

	private const SCOPES = [
		'closehub/list-posts'              => self::FANOUT,
		'closehub/list-languages'          => self::FANOUT,
		'closehub/add-language'            => self::TARGETED,
		'closehub/set-default-language'    => self::TARGETED,
		'closehub/duplicate-post'          => self::TARGETED,
		'closehub/create-post'             => self::FANOUT,
		'closehub/get-order-summary'       => self::FANOUT,
		'closehub/flush-permalinks'        => self::FANOUT,
		'closehub/get-post'                => self::TARGETED,
		'closehub/update-post'             => self::TARGETED,
		'closehub/upload-media'            => self::TARGETED,
		'closehub/update-post-slug'        => self::TARGETED,
		'closehub/replace-gutenberg-block' => self::TARGETED,
		'closehub/trash-post'              => self::TARGETED,
		'closehub/link-translations'       => self::TARGETED,
		'closehub/unlink-translation'      => self::TARGETED,
		'closehub/search-replace-domain'   => self::TARGETED,
		'closehub/set-robots-txt'          => self::TARGETED,
		'closehub/set-post-noindex'        => self::TARGETED,
		'closehub/get-htaccess'            => self::TARGETED,
		'closehub/set-site-settings'       => self::TARGETED,
		'closehub/reassign-posts-author'   => self::TARGETED,
		'closehub/create-site-user'        => self::TARGETED,
		'closehub/clear-cache'             => self::TARGETED,
		'closehub/install-wp-org-plugin'   => self::ONCE,
		'closehub/list-unused-plugins'     => self::ONCE,
		'closehub/update-plugins-and-core' => self::ONCE,
	];

	/** Ability namespaces that wrap_external_ability() never touches. */
	private const UNWRAPPED_NAMESPACES = [ 'closehub/', 'mcp-adapter/', 'core/' ];

	private const MCP_TOOL_ABILITIES = [ 'closehub/list-sites' ];

	/** Targeted is the safe default for any ability not listed above. */
	public static function scope( string $ability_id ): string {
		return self::SCOPES[ $ability_id ] ?? self::TARGETED;
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
		add_filter( 'mcp_adapter_default_server_config', [ self::class, 'add_tools_to_default_server' ] );
		add_filter( 'wp_register_ability_args', [ self::class, 'wrap_external_ability' ], 20, 2 );
	}

	/**
	 * Make abilities registered by other plugins (Rank Math, WP Rocket, Imagify, WooCommerce…) multisite-aware.
	 *
	 * Those abilities only ever see the site the MCP endpoint lives on. On a network they are wrapped as
	 * `targeted`: a required `site_id` is added to the input schema and the callbacks run after switch_to_blog().
	 * Abilities from `closehub/` (already wrapped), `mcp-adapter/` and `core/` are left alone, as is everything
	 * on a single site. Return false from `closehub_network_wrap_ability` to opt an ability out.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 */
	public static function wrap_external_ability( array $args, string $ability_id ): array {
		if ( ! is_multisite() || self::TARGETED !== self::scope( $ability_id ) || isset( $args['meta']['closehub_network'] ) ) {
			return $args;
		}
		foreach ( self::UNWRAPPED_NAMESPACES as $namespace ) {
			if ( str_starts_with( $ability_id, $namespace ) ) {
				return $args;
			}
		}
		if ( ! apply_filters( 'closehub_network_wrap_ability', true, $ability_id ) ) {
			return $args;
		}
		if ( ! isset( $args['execute_callback'], $args['permission_callback'] ) || ! is_callable( $args['execute_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
			return $args;
		}

		$input_schema = $args['input_schema'] ?? [];
		if ( [] !== $input_schema && ! in_array( $input_schema['type'] ?? 'object', [ 'object' ], true ) ) {
			return $args; // Non-object input has nowhere to carry site_id.
		}

		$had_input  = [] !== $input_schema;
		$execute    = $args['execute_callback'];
		$permission = $args['permission_callback'];

		$args['input_schema']               = [ 'type' => 'object' ] + $input_schema;
		$args['input_schema']['properties'] = self::input_properties( $ability_id, (array) ( $input_schema['properties'] ?? [] ) );
		if ( ! empty( $args['output_schema'] ) ) {
			$args['output_schema'] = self::output_schema_with_site( $args['output_schema'] );
		}
		if ( isset( $args['description'] ) ) {
			$args['description'] = self::description( $ability_id, (string) $args['description'] );
		}
		$args['meta']['closehub_network'] = true;

		// Abilities without an input schema receive null from WordPress; keep that once site_id is stripped.
		$restore_null = static fn( $input ) => ( $had_input || ! empty( $input ) ) ? $input : null;
		$run_permission              = static fn( $input = null ) => $permission( $restore_null( $input ) );
		$args['execute_callback']    = self::execute( $ability_id, static fn( $input = null ) => $execute( $restore_null( $input ) ), $run_permission );
		$args['permission_callback'] = self::permission( $ability_id, $run_permission );

		return $args;
	}

	/** Output schema that also allows the `site_id` / `site_url` that a targeted result is tagged with. */
	private static function output_schema_with_site( array $schema ): array {
		$site = [
			'site_id'  => [ 'type' => 'integer' ],
			'site_url' => [ 'type' => 'string' ],
		];
		if ( 'object' === ( $schema['type'] ?? null ) ) {
			$schema['properties'] = (array) ( $schema['properties'] ?? [] ) + $site;
			return $schema;
		}
		return [ 'type' => 'object', 'properties' => $site + [ 'result' => $schema ] ];
	}

	/** Tag a targeted result with its site; results that are not an associative array go under `result`. */
	private static function tag_result( array $entry, mixed $data ): array {
		if ( is_array( $data ) && ( [] === $data || ! array_is_list( $data ) ) ) {
			return $entry + $data;
		}
		return $entry + [ 'result' => $data ];
	}

	public static function add_tools_to_default_server( array $config ): array {
		$config['tools'] = array_values( array_unique( array_merge( $config['tools'] ?? [], self::MCP_TOOL_ABILITIES ) ) );
		return $config;
	}

	public static function register_abilities(): void {
		wp_register_ability( 'closehub/list-sites', [
			'label'               => 'List sites',
			'description'         => 'List the sites this connection can manage (every site of the network on multisite, otherwise just this site). Use the returned site_id with abilities that take site_id or site_ids.',
			'category'            => 'closehub-site',
			'input_schema'        => [ 'type' => 'object', 'properties' => [] ],
			'execute_callback'    => [ self::class, 'list_sites' ],
			'permission_callback' => 'is_user_logged_in',
			'meta'                => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ] ],
		] );
	}

	/** Sites the acting user can see: the whole network for a super admin, otherwise the sites they belong to. */
	public static function list_sites(): array {
		if ( ! is_multisite() ) {
			return [ 'multisite' => false, 'sites' => [ [ 'site_id' => get_current_blog_id(), 'name' => get_bloginfo( 'name' ), 'url' => get_site_url() ] ] ];
		}

		$sites = [];
		foreach ( self::visible_sites() as $site ) {
			$sites[] = [ 'site_id' => (int) $site->blog_id, 'name' => (string) get_blog_option( (int) $site->blog_id, 'blogname' ), 'url' => get_home_url( (int) $site->blog_id ) ];
		}
		return [ 'multisite' => true, 'sites' => $sites ];
	}

	/** @return object[] */
	private static function visible_sites(): array {
		$sites = get_sites( [ 'number' => 0, 'deleted' => 0, 'spam' => 0, 'archived' => 0 ] );
		if ( is_super_admin() ) {
			return $sites;
		}
		$member_of = array_map( 'intval', array_keys( get_blogs_of_user( get_current_user_id() ) ) );
		return array_values( array_filter( $sites, static fn( $site ) => in_array( (int) $site->blog_id, $member_of, true ) ) );
	}

	// ── Registration helpers ───────────────────────────────────────────────────

	/** Add the multisite `site_id` / `site_ids` input an ability's scope needs. */
	public static function input_properties( string $ability_id, array $properties ): array {
		if ( ! is_multisite() ) {
			return $properties;
		}
		$scope = self::scope( $ability_id );
		if ( self::TARGETED === $scope ) {
			$properties['site_id'] = [ 'type' => 'integer', 'description' => 'Multisite only: ID of the site to act on (see closehub/list-sites). Required on a multisite network.' ];
		} elseif ( self::FANOUT === $scope ) {
			$properties['site_ids'] = [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Multisite only: limit to these site IDs. Defaults to every site in the network.' ];
		}
		return $properties;
	}

	public static function description( string $ability_id, string $description ): string {
		if ( ! is_multisite() ) {
			return $description;
		}
		return $description . match ( self::scope( $ability_id ) ) {
			self::FANOUT   => ' On this multisite network it runs on every site (or the site_ids given) and returns one result per site.',
			self::TARGETED => ' On this multisite network, site_id is required.',
			default        => ' On this multisite network it runs once for the whole server, not per site.',
		};
	}

	/** Wrap an ability's execute callback with the site selection its scope calls for. */
	public static function execute( string $ability_id, callable $callback, callable $permission ): callable {
		$scope = self::scope( $ability_id );

		return static function ( $input = null ) use ( $callback, $permission, $scope ) {
			if ( ! is_multisite() || self::ONCE === $scope ) {
				return $callback( $input );
			}

			$input    = is_array( $input ) ? $input : [];
			$site_ids = self::resolve_site_ids( $input, $scope );
			if ( is_wp_error( $site_ids ) ) {
				return $site_ids;
			}
			unset( $input['site_id'], $input['site_ids'] );

			$entries = [];
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				try {
					$entry = [ 'site_id' => $site_id, 'site_url' => get_site_url() ];
					$data  = $permission( $input )
						? $callback( $input )
						: new WP_Error( 'closehub_forbidden', 'You do not have permission to perform this action on this site.', [ 'status' => 403 ] );
				} finally {
					restore_current_blog();
				}

				if ( self::TARGETED === $scope ) {
					return is_wp_error( $data ) ? $data : self::tag_result( $entry, $data );
				}
				$entries[] = is_wp_error( $data ) ? $entry + [ 'error' => $data->get_error_message() ] : $entry + $data;
			}

			return [ 'sites' => $entries ];
		};
	}

	/** Permission check that passes if the user may run the ability on at least one target site. */
	public static function permission( string $ability_id, callable $permission ): callable {
		$scope = self::scope( $ability_id );

		return static function ( $input = null ) use ( $permission, $scope ) {
			if ( ! is_multisite() || self::ONCE === $scope ) {
				return $permission( $input );
			}

			$input    = is_array( $input ) ? $input : [];
			$site_ids = self::resolve_site_ids( $input, $scope );
			if ( is_wp_error( $site_ids ) ) {
				// Let execute() report the precise error (missing/unknown site) instead of a generic denial.
				return true;
			}
			unset( $input['site_id'], $input['site_ids'] );

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				try {
					$allowed = (bool) $permission( $input );
				} finally {
					restore_current_blog();
				}
				if ( $allowed ) {
					return true;
				}
			}
			return false;
		};
	}

	/** @return int[]|WP_Error */
	private static function resolve_site_ids( array $input, string $scope ): array|WP_Error {
		$available = array_map( static fn( $site ) => (int) $site->blog_id, get_sites( [ 'number' => 0, 'deleted' => 0, 'spam' => 0, 'archived' => 0 ] ) );

		if ( self::TARGETED === $scope ) {
			$site_id = absint( $input['site_id'] ?? 0 );
			if ( ! $site_id ) {
				return new WP_Error( 'closehub_site_id_required', 'site_id is required on a multisite network. Use closehub/list-sites to find it.', [ 'status' => 400 ] );
			}
			if ( ! in_array( $site_id, $available, true ) ) {
				return new WP_Error( 'closehub_site_not_found', sprintf( 'Site %d does not exist in this network.', $site_id ), [ 'status' => 404 ] );
			}
			return [ $site_id ];
		}

		if ( ! isset( $input['site_ids'] ) || [] === $input['site_ids'] ) {
			return $available;
		}
		$requested = array_values( array_unique( array_filter( array_map( 'absint', (array) $input['site_ids'] ) ) ) );
		$unknown   = array_diff( $requested, $available );
		if ( $unknown || ! $requested ) {
			return new WP_Error( 'closehub_site_not_found', sprintf( 'Unknown site IDs: %s.', implode( ', ', $unknown ?: [ 0 ] ) ), [ 'status' => 404 ] );
		}
		return $requested;
	}
}

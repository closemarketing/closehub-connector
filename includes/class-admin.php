<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_Admin {
	private const ENDPOINTS = [
		[ 'GET', '/closehub/v1/ping' ],
		[ 'POST', '/closehub/v1/posts' ],
		[ 'GET', '/closehub/v1/woocommerce/orders' ],
		[ 'GET', '/closehub/v1/gravity-forms/forms' ],
		[ 'GET', '/closehub/v1/gravity-forms/forms/{id}' ],
		[ 'GET', '/closehub/v1/gravity-forms/forms/{id}/entries' ],
	];

	public function register(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'add_network_menu' ] );
			add_action( 'admin_init', [ $this, 'handle_network_regenerate' ] );
			add_action( 'admin_init', [ $this, 'handle_network_regenerate_oauth_metadata' ] );
			add_action( 'admin_init', [ $this, 'handle_network_enable_managed_oauth_discovery' ] );
			return;
		}

		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_regenerate' ] );
		add_action( 'admin_init', [ $this, 'handle_regenerate_oauth_metadata' ] );
		add_action( 'admin_init', [ $this, 'handle_enable_managed_oauth_discovery' ] );
	}

	public function add_menu(): void {
		add_options_page(
			'CloseHub Connector',
			'CloseHub',
			'manage_options',
			'closehub-connector',
			[ $this, 'render_page' ]
		);
	}

	public function add_network_menu(): void {
		add_submenu_page(
			'settings.php',
			'CloseHub Connector',
			'CloseHub',
			'manage_network_options',
			'closehub-connector',
			[ $this, 'render_network_page' ]
		);
	}

	private static function settings_url( bool $network = false ): string {
		return $network ? network_admin_url( 'settings.php?page=closehub-connector' ) : menu_page_url( 'closehub-connector', false );
	}

	public function handle_regenerate(): void {
		if ( ! isset( $_POST['closehub_regenerate'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_regenerate_key' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		CloseHub_API_Key::regenerate();
		wp_safe_redirect( add_query_arg( 'closehub_notice', 'regenerated', self::settings_url() ) );
		exit;
	}

	public function handle_network_regenerate(): void {
		if ( ! is_network_admin() || ! isset( $_POST['closehub_regenerate'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_regenerate_key' );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		CloseHub_API_Key::regenerate();
		wp_safe_redirect( add_query_arg( 'closehub_notice', 'regenerated', self::settings_url( true ) ) );
		exit;
	}

	/** Regenerate the static OAuth discovery documents for this site. */
	public function handle_regenerate_oauth_metadata(): void {
		if ( ! isset( $_POST['closehub_regenerate_oauth_metadata'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_regenerate_oauth_metadata' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		if ( ! CloseHub_OAuth::initialize_filesystem( self::settings_url() ) ) {
			return;
		}
		$notice = CloseHub_OAuth::ensure_well_known_files() ? 'oauth_metadata_regenerated' : 'oauth_metadata_failed';
		wp_safe_redirect( add_query_arg( 'closehub_notice', $notice, self::settings_url() ) );
		exit;
	}

	/** Enable the Apache-only dynamic OAuth discovery mode. */
	public function handle_enable_managed_oauth_discovery(): void {
		if ( ! isset( $_POST['closehub_enable_managed_oauth_discovery'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_enable_managed_oauth_discovery' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		if ( ! CloseHub_OAuth::initialize_filesystem( self::settings_url(), 'closehub_enable_managed_oauth_discovery' ) ) {
			return;
		}
		$notice = CloseHub_OAuth::enable_managed_discovery() ? 'managed_oauth_discovery_enabled' : 'managed_oauth_discovery_failed';
		wp_safe_redirect( add_query_arg( 'closehub_notice', $notice, self::settings_url() ) );
		exit;
	}

	/** Regenerate the static OAuth discovery documents for the main network site. */
	public function handle_network_regenerate_oauth_metadata(): void {
		if ( ! is_network_admin() || ! isset( $_POST['closehub_regenerate_oauth_metadata'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_regenerate_oauth_metadata' );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		if ( ! CloseHub_OAuth::initialize_filesystem( self::settings_url( true ) ) ) {
			return;
		}
		$notice = CloseHub_OAuth::ensure_well_known_files() ? 'oauth_metadata_regenerated' : 'oauth_metadata_failed';
		wp_safe_redirect( add_query_arg( 'closehub_notice', $notice, self::settings_url( true ) ) );
		exit;
	}

	/** Enable dynamic OAuth discovery on the main site of this Apache network. */
	public function handle_network_enable_managed_oauth_discovery(): void {
		if ( ! is_network_admin() || ! isset( $_POST['closehub_enable_managed_oauth_discovery'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_enable_managed_oauth_discovery' );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		$url = self::settings_url( true );
		if ( ! CloseHub_OAuth::initialize_filesystem( $url, 'closehub_enable_managed_oauth_discovery' ) ) {
			return;
		}
		$notice = CloseHub_OAuth::enable_managed_discovery() ? 'managed_oauth_discovery_enabled' : 'managed_oauth_discovery_failed';
		wp_safe_redirect( add_query_arg( 'closehub_notice', $notice, $url ) );
		exit;
	}

	public function render_page(): void {
		if ( current_user_can( 'manage_options' ) ) {
			$this->render_settings( false );
		}
	}

	public function render_network_page(): void {
		if ( current_user_can( 'manage_network_options' ) ) {
			$this->render_settings( true );
		}
	}

	/**
	 * Return the default MCP Adapter server URL for the current site.
	 */
	public static function get_mcp_server_url(): string {
		return rest_url( 'mcp/mcp-adapter-default-server' );
	}

	/** Use WordPress query routing when the web server drops Authorization on pretty REST URLs. */
	public static function get_mcp_server_query_url(): string {
		return home_url( '/?rest_route=%2Fmcp%2Fmcp-adapter-default-server' );
	}

	/** Render a read-only value with a Copy button. */
	private function render_copy( string $value, string $label = '' ): void {
		?>
		<code><?php echo esc_html( '' !== $label ? $label : $value ); ?></code>
		<button type="button" class="button button-small" data-closehub-copy="<?php echo esc_attr( $value ); ?>"><?php esc_html_e( 'Copy', 'closehub-connector' ); ?></button>
		<?php
	}

	/**
	 * Single settings screen: one API key used by CloseHub (REST) and Claude (MCP).
	 */
	private function render_settings( bool $network ): void {
		$api_key      = CloseHub_API_Key::get();
		$base_url     = $network ? network_site_url() : get_site_url();
		$notice       = isset( $_GET['closehub_notice'] ) ? sanitize_key( $_GET['closehub_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$acts_as      = get_user_by( 'id', CloseHub_API_Key::get_user_id() );
		$abilities    = function_exists( 'wp_get_abilities' ) ? count( array_filter( wp_get_abilities(), static fn( $ability ) => str_starts_with( $ability->get_name(), 'closehub/' ) ) ) : 0;
		$managed      = CloseHub_OAuth::managed_discovery_is_enabled();
		$needs_regen  = ! $managed && CloseHub_OAuth::well_known_files_need_regeneration();
		$mcp_url      = self::get_mcp_server_query_url();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CloseHub Connector', 'closehub-connector' ); ?></h1>
			<p>
				<?php
				echo esc_html(
					$network
						? __( 'One API key connects both the CloseHub app and Claude (MCP) to every site in this network.', 'closehub-connector' )
						: __( 'One API key connects both the CloseHub app and Claude (MCP) to this site.', 'closehub-connector' )
				);
				?>
			</p>

			<?php $this->render_notices( $notice ); ?>
			<?php if ( $needs_regen ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'OAuth discovery metadata is missing, out of date, or cannot be updated. Regenerate it under Advanced before connecting an OAuth MCP client.', 'closehub-connector' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connection', 'closehub-connector' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="closehub-api-key"><?php esc_html_e( 'API Key', 'closehub-connector' ); ?></label></th>
					<td>
						<input type="password" id="closehub-api-key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text code" readonly />
						<button type="button" class="button" data-closehub-reveal="closehub-api-key" data-show="<?php echo esc_attr__( 'Show', 'closehub-connector' ); ?>" data-hide="<?php echo esc_attr__( 'Hide', 'closehub-connector' ); ?>"><?php esc_html_e( 'Show', 'closehub-connector' ); ?></button>
						<button type="button" class="button" data-closehub-copy="<?php echo esc_attr( $api_key ); ?>"><?php esc_html_e( 'Copy', 'closehub-connector' ); ?></button>
						<p class="description"><?php esc_html_e( 'Used by CloseHub and by Claude Team. Keep it secret — anyone with it can manage this site.', 'closehub-connector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'CloseHub endpoint', 'closehub-connector' ); ?></th>
					<td><?php $this->render_copy( $base_url . '/wp-json/closehub/v1/' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP server URL', 'closehub-connector' ); ?></th>
					<td>
						<?php $this->render_copy( $mcp_url ); ?>
						<?php if ( ! class_exists( '\\WP\\MCP\\Plugin' ) ) : ?>
							<p class="description"><?php esc_html_e( 'The MCP Adapter is not available. Reinstall the plugin package to include its Composer dependencies.', 'closehub-connector' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Acts as user', 'closehub-connector' ); ?></th>
					<td>
						<?php if ( $acts_as ) : ?>
							<strong><?php echo esc_html( $acts_as->user_login ); ?></strong>
							<span class="description">— <?php esc_html_e( 'MCP requests get this user’s capabilities.', 'closehub-connector' ); ?></span>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'No administrator found. Regenerate the key while logged in to assign one.', 'closehub-connector' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Connect Claude Team', 'closehub-connector' ); ?></h2>
			<ol>
				<li><?php echo wp_kses( __( 'In Claude, add a custom connector and paste the <strong>MCP server URL</strong>.', 'closehub-connector' ), [ 'strong' => [] ] ); ?></li>
				<li><?php echo wp_kses( __( 'Set Authentication to <strong>No sign-in</strong>.', 'closehub-connector' ), [ 'strong' => [] ] ); ?></li>
				<li>
					<?php esc_html_e( 'Add a request header with your API key:', 'closehub-connector' ); ?>
					<?php $this->render_copy( 'Bearer ' . $api_key, 'authorization: Bearer ' . substr( $api_key, 0, 8 ) . '…' ); ?>
				</li>
			</ol>

			<h2><?php esc_html_e( 'Regenerate API key', 'closehub-connector' ); ?></h2>
			<p><?php esc_html_e( 'The current key stops working immediately — CloseHub and every Claude user will be disconnected until you update them.', 'closehub-connector' ); ?></p>
			<form method="post" data-closehub-confirm="<?php echo esc_attr__( 'Disconnect CloseHub and Claude now?', 'closehub-connector' ); ?>">
				<?php wp_nonce_field( 'closehub_regenerate_key' ); ?>
				<input type="hidden" name="closehub_regenerate" value="1" />
				<?php submit_button( __( 'Regenerate Key', 'closehub-connector' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<details>
				<summary><strong><?php esc_html_e( 'What’s available', 'closehub-connector' ); ?></strong> <span class="description">— <?php echo esc_html( sprintf( /* translators: 1: endpoints, 2: abilities. */ __( '%1$d endpoints · %2$d abilities', 'closehub-connector' ), count( self::ENDPOINTS ), $abilities ) ); ?></span></summary>
				<h3><?php esc_html_e( 'REST endpoints — CloseHub', 'closehub-connector' ); ?></h3>
				<ul>
					<?php foreach ( self::ENDPOINTS as $endpoint ) : ?>
						<li><code><?php echo esc_html( $endpoint[0] ); ?></code> <code><?php echo esc_html( $endpoint[1] ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<h3><?php esc_html_e( 'MCP abilities — Claude', 'closehub-connector' ); ?></h3>
				<?php $this->render_abilities_status(); ?>
			</details>

			<details>
				<summary><strong><?php esc_html_e( 'Advanced: OAuth connection', 'closehub-connector' ); ?></strong> <span class="description">— <?php esc_html_e( 'Only if you prefer per-user sign-in', 'closehub-connector' ); ?></span></summary>
				<p><?php esc_html_e( 'Each Claude user signs in with their own WordPress account instead of the shared API key. In Claude choose “Always required” and “Use Anthropic’s hosted client metadata”.', 'closehub-connector' ); ?></p>
				<p><?php $this->render_copy( self::get_mcp_server_url() ); ?></p>
				<p class="description"><?php esc_html_e( 'If OAuth discovery fails because the server handles .well-known requests itself:', 'closehub-connector' ); ?></p>
				<?php if ( $managed ) : ?>
					<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Managed OAuth Discovery is enabled.', 'closehub-connector' ); ?></p>
				<?php else : ?>
					<form method="post" style="display:inline-block">
						<?php wp_nonce_field( 'closehub_enable_managed_oauth_discovery' ); ?>
						<input type="hidden" name="closehub_enable_managed_oauth_discovery" value="1" />
						<?php submit_button( __( 'Enable managed discovery (Apache)', 'closehub-connector' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
				<form method="post" style="display:inline-block">
					<?php wp_nonce_field( 'closehub_regenerate_oauth_metadata' ); ?>
					<input type="hidden" name="closehub_regenerate_oauth_metadata" value="1" />
					<?php submit_button( __( 'Regenerate OAuth metadata', 'closehub-connector' ), 'secondary', 'submit', false ); ?>
				</form>
			</details>

			<?php if ( $network ) : ?>
				<details>
					<summary><strong><?php esc_html_e( 'Sites in this Network', 'closehub-connector' ); ?></strong></summary>
					<table class="widefat striped" style="max-width:700px">
						<thead><tr><th><?php esc_html_e( 'Site ID', 'closehub-connector' ); ?></th><th><?php esc_html_e( 'URL', 'closehub-connector' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( get_sites( [ 'number' => 0 ] ) as $site ) : ?>
								<tr>
									<td><?php echo (int) $site->blog_id; ?></td>
									<td><code><?php echo esc_html( get_home_url( (int) $site->blog_id ) ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
		</div>
		<script>
		document.addEventListener( 'click', function ( event ) {
			var copy = event.target.closest( '[data-closehub-copy]' );
			if ( copy ) {
				navigator.clipboard.writeText( copy.getAttribute( 'data-closehub-copy' ) );
				return;
			}
			var reveal = event.target.closest( '[data-closehub-reveal]' );
			if ( reveal ) {
				var input = document.getElementById( reveal.getAttribute( 'data-closehub-reveal' ) );
				var show = 'password' === input.type;
				input.type = show ? 'text' : 'password';
				reveal.textContent = reveal.getAttribute( show ? 'data-hide' : 'data-show' );
			}
		} );
		document.addEventListener( 'submit', function ( event ) {
			var message = event.target.getAttribute( 'data-closehub-confirm' );
			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
		</script>
		<?php
	}

	private function render_notices( string $notice ): void {
		if ( 'regenerated' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'New API key generated. Update it in CloseHub and in your Claude connector.', 'closehub-connector' ) . '</p></div>';
		} elseif ( 'oauth_metadata_regenerated' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'OAuth discovery metadata regenerated successfully.', 'closehub-connector' ) . '</p></div>';
		} elseif ( 'managed_oauth_discovery_enabled' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Managed OAuth discovery is enabled. Apache now routes OAuth discovery requests through WordPress.', 'closehub-connector' ) . '</p></div>';
		} elseif ( 'managed_oauth_discovery_failed' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Managed OAuth discovery could not be enabled. Check the WordPress filesystem credentials and Apache rewrite permissions.', 'closehub-connector' ) . '</p></div>';
		} elseif ( 'oauth_metadata_failed' === $notice ) {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'OAuth discovery metadata could not be written. Check that the web server can write to the public .well-known directory, or upload the files through FTP.', 'closehub-connector' ); ?></p>
				<details>
					<summary><?php esc_html_e( 'Show files to upload manually', 'closehub-connector' ); ?></summary>
					<p><?php esc_html_e( 'Create these files inside the public .well-known directory. Do not change their names or contents.', 'closehub-connector' ); ?></p>
					<?php foreach ( CloseHub_OAuth::well_known_file_contents() as $filename => $contents ) : ?>
						<p><label for="closehub-oauth-<?php echo esc_attr( $filename ); ?>"><strong><code><?php echo esc_html( $filename ); ?></code></strong></label></p>
						<textarea id="closehub-oauth-<?php echo esc_attr( $filename ); ?>" class="large-text code" rows="12" readonly><?php echo esc_textarea( $contents ); ?></textarea>
					<?php endforeach; ?>
				</details>
			</div>
			<?php
		}
	}

	/**
	 * Show how many CloseHub abilities are actually registered right now, so
	 * a site owner can tell "MCP connects but has no tools" apart from
	 * "MCP won't connect at all" without needing server log access.
	 */
	private function render_abilities_status(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			echo '<p><span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
			esc_html_e( 'The WordPress Abilities API is not available on this site. Update WordPress core, or install the Abilities API feature plugin, then reactivate CloseHub Connector.', 'closehub-connector' );
			echo '</p>';
			return;
		}

		$abilities = wp_get_abilities();
		$closehub  = array_filter( $abilities, static function ( $ability ) {
			return str_starts_with( $ability->get_name(), 'closehub/' );
		} );

		if ( ! $closehub ) {
			echo '<p><span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
			esc_html_e( 'No CloseHub abilities are registered. Deactivate and reactivate the plugin; if the problem persists, check the site error log for a fatal error during plugin load.', 'closehub-connector' );
			echo '</p>';
			return;
		}

		echo '<p class="description">';
		printf(
			/* translators: %d: number of registered abilities. */
			esc_html( _n( '%d ability registered.', '%d abilities registered.', count( $closehub ), 'closehub-connector' ) ),
			count( $closehub )
		);
		echo '</p><p>';
		foreach ( $closehub as $ability ) {
			echo '<code title="' . esc_attr( $ability->get_name() ) . '">' . esc_html( $ability->get_label() ) . '</code> ';
		}
		echo '</p>';
	}
}

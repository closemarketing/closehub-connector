<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_Admin {

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

	public function handle_regenerate(): void {
		if ( ! isset( $_POST['closehub_regenerate'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_regenerate_key' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		CloseHub_API_Key::regenerate();
		wp_safe_redirect( add_query_arg( 'closehub_notice', 'regenerated', menu_page_url( 'closehub-connector', false ) ) );
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
		if ( ! CloseHub_OAuth::initialize_filesystem( menu_page_url( 'closehub-connector', false ) ) ) {
			return;
		}
		$notice = CloseHub_OAuth::ensure_well_known_files() ? 'oauth_metadata_regenerated' : 'oauth_metadata_failed';
		wp_safe_redirect( add_query_arg( 'closehub_notice', $notice, menu_page_url( 'closehub-connector', false ) ) );
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
		if ( ! CloseHub_OAuth::initialize_filesystem( menu_page_url( 'closehub-connector', false ), 'closehub_enable_managed_oauth_discovery' ) ) {
			return;
		}
		$notice = CloseHub_OAuth::enable_managed_discovery() ? 'managed_oauth_discovery_enabled' : 'managed_oauth_discovery_failed';
		wp_safe_redirect( add_query_arg( 'closehub_notice', $notice, menu_page_url( 'closehub-connector', false ) ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_key  = CloseHub_API_Key::get();
		$site_url = get_site_url();
		$notice   = isset( $_GET['closehub_notice'] ) ? sanitize_key( $_GET['closehub_notice'] ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CloseHub Connector', 'closehub-connector' ); ?></h1>

			<?php if ( 'regenerated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'API key regenerated successfully.', 'closehub-connector' ); ?></p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Use the credentials below to connect CloseHub to this WordPress site.', 'closehub-connector' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Site URL', 'closehub-connector' ); ?></th>
					<td>
						<code><?php echo esc_html( $site_url ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $site_url ); ?>')">
							<?php esc_html_e( 'Copy', 'closehub-connector' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Key', 'closehub-connector' ); ?></th>
					<td>
						<input
							type="text"
							id="closehub-api-key"
							value="<?php echo esc_attr( $api_key ); ?>"
							class="regular-text"
							readonly
							style="font-family:monospace"
						/>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('closehub-api-key').value)">
							<?php esc_html_e( 'Copy', 'closehub-connector' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Keep this key secret. Anyone with it can access your site data via CloseHub.', 'closehub-connector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Endpoint', 'closehub-connector' ); ?></th>
					<td>
						<code><?php echo esc_html( $site_url . '/wp-json/closehub/v1/' ); ?></code>
					</td>
				</tr>
			</table>

			<?php $this->render_mcp_section(); ?>

			<h2><?php esc_html_e( 'Regenerate API Key', 'closehub-connector' ); ?></h2>
			<p><?php esc_html_e( 'Regenerating the key will immediately invalidate the current one. You will need to update CloseHub with the new key.', 'closehub-connector' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'closehub_regenerate_key' ); ?>
				<input type="hidden" name="closehub_regenerate" value="1" />
				<?php submit_button( __( 'Regenerate Key', 'closehub-connector' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Available Endpoints', 'closehub-connector' ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Method', 'closehub-connector' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'closehub-connector' ); ?></th>
						<th><?php esc_html_e( 'Description', 'closehub-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/ping</code></td><td><?php esc_html_e( 'Verify connection', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>POST</code></td><td><code>/closehub/v1/posts</code></td><td><?php esc_html_e( 'Create a post', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/woocommerce/orders</code></td><td><?php esc_html_e( 'Fetch orders (WooCommerce)', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms</code></td><td><?php esc_html_e( 'List forms (Gravity Forms)', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms/{id}</code></td><td><?php esc_html_e( 'Get form details', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms/{id}/entries</code></td><td><?php esc_html_e( 'Count form entries', 'closehub-connector' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
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

	public function handle_network_regenerate(): void {
		if ( ! is_network_admin() || ! isset( $_POST['closehub_regenerate'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_regenerate_key' );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		CloseHub_API_Key::regenerate();
		wp_safe_redirect( add_query_arg( 'closehub_notice', 'regenerated', network_admin_url( 'settings.php?page=closehub-connector' ) ) );
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
		if ( ! CloseHub_OAuth::initialize_filesystem( network_admin_url( 'settings.php?page=closehub-connector' ) ) ) {
			return;
		}
		$notice = CloseHub_OAuth::ensure_well_known_files() ? 'oauth_metadata_regenerated' : 'oauth_metadata_failed';
		wp_safe_redirect( add_query_arg( 'closehub_notice', $notice, network_admin_url( 'settings.php?page=closehub-connector' ) ) );
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
		$url = network_admin_url( 'settings.php?page=closehub-connector' );
		if ( ! CloseHub_OAuth::initialize_filesystem( $url, 'closehub_enable_managed_oauth_discovery' ) ) {
			return;
		}
		$notice = CloseHub_OAuth::enable_managed_discovery() ? 'managed_oauth_discovery_enabled' : 'managed_oauth_discovery_failed';
		wp_safe_redirect( add_query_arg( 'closehub_notice', $notice, $url ) );
		exit;
	}

	public function render_network_page(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$api_key     = CloseHub_API_Key::get();
		$network_url = network_site_url();
		$notice      = isset( $_GET['closehub_notice'] ) ? sanitize_key( $_GET['closehub_notice'] ) : '';
		$sites       = get_sites( [ 'number' => 0 ] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CloseHub Connector', 'closehub-connector' ); ?></h1>

			<?php if ( 'regenerated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'API key regenerated successfully.', 'closehub-connector' ); ?></p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'This key is shared by every site in the network. CloseHub authenticates once and the endpoints below automatically return combined results for all sites.', 'closehub-connector' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Network API Key', 'closehub-connector' ); ?></th>
					<td>
						<input
							type="text"
							id="closehub-api-key"
							value="<?php echo esc_attr( $api_key ); ?>"
							class="regular-text"
							readonly
							style="font-family:monospace"
						/>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('closehub-api-key').value)">
							<?php esc_html_e( 'Copy', 'closehub-connector' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Keep this key secret. Anyone with it can access data across every site in this network via CloseHub.', 'closehub-connector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Endpoint', 'closehub-connector' ); ?></th>
					<td>
						<code><?php echo esc_html( $network_url . '/wp-json/closehub/v1/' ); ?></code>
					</td>
				</tr>
			</table>

			<?php $this->render_mcp_section(); ?>

			<h2><?php esc_html_e( 'Regenerate API Key', 'closehub-connector' ); ?></h2>
			<p><?php esc_html_e( 'Regenerating the key will immediately invalidate the current one for every site in the network.', 'closehub-connector' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'closehub_regenerate_key' ); ?>
				<input type="hidden" name="closehub_regenerate" value="1" />
				<?php submit_button( __( 'Regenerate Key', 'closehub-connector' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Available Endpoints', 'closehub-connector' ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Method', 'closehub-connector' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'closehub-connector' ); ?></th>
						<th><?php esc_html_e( 'Description', 'closehub-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/ping</code></td><td><?php esc_html_e( 'Verify connection to every site', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>POST</code></td><td><code>/closehub/v1/posts</code></td><td><?php esc_html_e( 'Create a post on every site', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/woocommerce/orders</code></td><td><?php esc_html_e( 'Fetch orders for every site (WooCommerce)', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms</code></td><td><?php esc_html_e( 'List forms for every site (Gravity Forms)', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms/{id}</code></td><td><?php esc_html_e( 'Get form details for every site', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms/{id}/entries</code></td><td><?php esc_html_e( 'Count form entries for every site', 'closehub-connector' ); ?></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Sites in this Network', 'closehub-connector' ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site ID', 'closehub-connector' ); ?></th>
						<th><?php esc_html_e( 'URL', 'closehub-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sites as $site ) : ?>
						<tr>
							<td><?php echo (int) $site->blog_id; ?></td>
							<td><code><?php echo esc_html( get_home_url( (int) $site->blog_id ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Return the default MCP Adapter server URL for the current site.
	 */
	public static function get_mcp_server_url(): string {
		return rest_url( 'mcp/mcp-adapter-default-server' );
	}

	/**
	 * Render the MCP connection details shared by the site and network settings pages.
	 */
	private function render_mcp_section(): void {
		$mcp_url           = self::get_mcp_server_url();
		$adapter_available = class_exists( '\\WP\\MCP\\Plugin' );
		$managed_discovery_enabled = CloseHub_OAuth::managed_discovery_is_enabled();
		$metadata_needs_regeneration = ! $managed_discovery_enabled && CloseHub_OAuth::well_known_files_need_regeneration();
		$notice = isset( $_GET['closehub_notice'] ) ? sanitize_key( $_GET['closehub_notice'] ) : '';
		?>
		<h2><?php esc_html_e( 'MCP', 'closehub-connector' ); ?></h2>
		<?php if ( 'oauth_metadata_regenerated' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'OAuth discovery metadata regenerated successfully.', 'closehub-connector' ); ?></p></div>
		<?php endif; ?>
		<?php if ( 'oauth_metadata_failed' === $notice ) : ?>
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
		<?php endif; ?>
		<?php if ( 'managed_oauth_discovery_enabled' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Managed OAuth discovery is enabled. Apache now routes OAuth discovery requests through WordPress.', 'closehub-connector' ); ?></p></div>
		<?php endif; ?>
		<?php if ( 'managed_oauth_discovery_failed' === $notice ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Managed OAuth discovery could not be enabled. Check the WordPress filesystem credentials and Apache rewrite permissions.', 'closehub-connector' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $metadata_needs_regeneration ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'OAuth discovery metadata is missing, out of date, or cannot be updated. Regenerate it below before connecting an MCP client.', 'closehub-connector' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Use this URL to connect an MCP client to this WordPress site.', 'closehub-connector' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'MCP Server URL', 'closehub-connector' ); ?></th>
				<td>
					<input
						type="text"
						id="closehub-mcp-server-url"
						value="<?php echo esc_attr( $mcp_url ); ?>"
						class="large-text code"
						readonly
					/>
					<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('closehub-mcp-server-url').value)">
						<?php esc_html_e( 'Copy', 'closehub-connector' ); ?>
					</button>
					<p class="description">
						<?php
						if ( $adapter_available ) {
							esc_html_e( 'The MCP Adapter is available. Authenticate with a WordPress user account that has the required capabilities.', 'closehub-connector' );
						} else {
							esc_html_e( 'The MCP Adapter is not available. Reinstall the plugin package to include its Composer dependencies.', 'closehub-connector' );
						}
						?>
					</p>
					<p class="description">
						<?php esc_html_e( 'When adding this server in Claude, select “Always required” for Authentication and “Use Anthropic’s hosted client metadata” for OAuth client.', 'closehub-connector' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<h3><?php esc_html_e( 'Registered Abilities', 'closehub-connector' ); ?></h3>
		<?php $this->render_abilities_status(); ?>
		<h3><?php esc_html_e( 'OAuth Discovery Metadata', 'closehub-connector' ); ?></h3>
		<h4><?php esc_html_e( 'Managed OAuth Discovery (Apache)', 'closehub-connector' ); ?></h4>
		<p><?php esc_html_e( 'Use this mode when Apache serves .well-known requests before WordPress. It adds a limited rule for the two OAuth metadata paths while preserving the existing .htaccess contents.', 'closehub-connector' ); ?></p>
		<?php if ( $managed_discovery_enabled ) : ?>
			<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Managed OAuth Discovery is enabled.', 'closehub-connector' ); ?></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'closehub_enable_managed_oauth_discovery' ); ?>
				<input type="hidden" name="closehub_enable_managed_oauth_discovery" value="1" />
				<?php submit_button( __( 'Enable Managed OAuth Discovery', 'closehub-connector' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<p><?php esc_html_e( 'Regenerate the .well-known OAuth metadata files when your web server serves them directly instead of routing them through WordPress.', 'closehub-connector' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'closehub_regenerate_oauth_metadata' ); ?>
			<input type="hidden" name="closehub_regenerate_oauth_metadata" value="1" />
			<?php submit_button( __( 'Regenerate OAuth Metadata', 'closehub-connector' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
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

		echo '<p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ';
		printf(
			/* translators: %d: number of registered abilities. */
			esc_html( _n( '%d ability registered.', '%d abilities registered.', count( $closehub ), 'closehub-connector' ) ),
			count( $closehub )
		);
		echo '</p>';

		echo '<ul style="list-style:disc;margin-left:20px">';
		foreach ( $closehub as $ability ) {
			echo '<li><code>' . esc_html( $ability->get_name() ) . '</code> — ' . esc_html( $ability->get_label() ) . '</li>';
		}
		echo '</ul>';
	}
}

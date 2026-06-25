<?php
/**
 * API key management admin page.
 *
 * Renders the API Keys tab inside the Tools page. Allows administrators
 * to generate, view, and revoke API keys for the KonX Affiliates REST API.
 *
 * Plaintext keys are shown exactly once at creation and never stored.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Api_Keys_Page
 */
class Konx_Api_Keys_Page {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_konx_generate_api_key', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_konx_revoke_api_key', array( __CLASS__, 'handle_revoke' ) );
	}

	// ------------------------------------------------------------------
	// Render
	// ------------------------------------------------------------------

	/**
	 * Render the API Keys tab content (called by Konx_Tools_Page).
	 */
	public static function render_content() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$keys     = Konx_Api_Helper::get_all_keys();
		$feedback = self::get_feedback();
		$new_key  = get_transient( 'konx_new_api_key_' . get_current_user_id() );

		// Clear the one-time key display transient after reading.
		if ( $new_key ) {
			delete_transient( 'konx_new_api_key_' . get_current_user_id() );
		}

		?>
		<!-- Newly created key — shown once -->
		<?php if ( $new_key ) : ?>
			<div class="konx-card" style="border-left:4px solid #00a32a;margin-bottom:20px;background:#edfaef;">
				<h3 style="margin-top:0;color:#00a32a;">
					<span class="dashicons dashicons-yes-alt" style="margin-right:6px;"></span>
					<?php esc_html_e( 'API Key Created', 'konx-affiliate-dashboard' ); ?>
				</h3>
				<p style="margin:0 0 8px;color:#d63638;font-weight:600;">
					<?php esc_html_e( 'Copy this API key now. It will not be shown again.', 'konx-affiliate-dashboard' ); ?>
				</p>
				<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
					<input type="text" id="konx-new-api-key" value="<?php echo esc_attr( $new_key ); ?>"
						readonly style="font-family:monospace;font-size:14px;width:100%;max-width:500px;padding:8px;">
					<button type="button" class="button" onclick="konxCopyApiKey()" id="konx-copy-key-btn">
						<?php esc_html_e( 'Copy', 'konx-affiliate-dashboard' ); ?>
					</button>
				</div>
				<p class="description" style="margin:0;">
					<?php esc_html_e( 'Send this key to PowerOf10 for their .env configuration. Use the X-KONX-API-Key header in API requests.', 'konx-affiliate-dashboard' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $feedback ) : ?>
			<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible" style="margin:0 0 16px;">
				<p><?php echo esc_html( $feedback['message'] ); ?></p>
			</div>
		<?php endif; ?>

		<!-- Generate new key form -->
		<div class="konx-card" style="margin-bottom:20px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Generate New API Key', 'konx-affiliate-dashboard' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
				<input type="hidden" name="action" value="konx_generate_api_key">
				<?php wp_nonce_field( 'konx_generate_api_key', 'konx_api_key_nonce' ); ?>
				<div>
					<label for="konx-key-name" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">
						<?php esc_html_e( 'Key Name', 'konx-affiliate-dashboard' ); ?>
					</label>
					<input type="text" id="konx-key-name" name="key_name" required
						placeholder="<?php esc_attr_e( 'e.g. PowerOf10 Production', 'konx-affiliate-dashboard' ); ?>"
						style="width:280px;">
				</div>
				<?php submit_button( __( 'Generate Key', 'konx-affiliate-dashboard' ), 'primary', '', false ); ?>
			</form>
		</div>

		<!-- Existing keys table -->
		<div class="konx-card">
			<h3 style="margin-top:0;"><?php esc_html_e( 'API Keys', 'konx-affiliate-dashboard' ); ?></h3>
			<?php if ( empty( $keys ) ) : ?>
				<p class="description"><?php esc_html_e( 'No API keys have been generated yet.', 'konx-affiliate-dashboard' ); ?></p>
			<?php else : ?>
				<div class="konx-table-wrap">
					<table class="widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Name', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:100px;"><?php esc_html_e( 'Key Prefix', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:90px;"><?php esc_html_e( 'Status', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:130px;"><?php esc_html_e( 'Created', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:130px;"><?php esc_html_e( 'Last Used', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:90px;"><?php esc_html_e( 'Actions', 'konx-affiliate-dashboard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $keys as $key ) : ?>
								<?php
								$is_revoked = ! empty( $key->revoked_at );
								$created_by = get_userdata( $key->created_by );
								?>
								<tr<?php echo $is_revoked ? ' style="opacity:0.5;"' : ''; ?>>
									<td>
										<strong><?php echo esc_html( $key->key_name ); ?></strong>
										<?php if ( $created_by ) : ?>
											<br><span class="description"><?php printf( esc_html__( 'by %s', 'konx-affiliate-dashboard' ), esc_html( $created_by->display_name ) ); ?></span>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $key->key_prefix ); ?>...</code></td>
									<td>
										<?php if ( $is_revoked ) : ?>
											<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:#fcf0f1;color:#d63638;">
												<?php esc_html_e( 'Revoked', 'konx-affiliate-dashboard' ); ?>
											</span>
										<?php else : ?>
											<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:#edfaef;color:#00a32a;">
												<?php esc_html_e( 'Active', 'konx-affiliate-dashboard' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $key->created_at ) ) ); ?></td>
									<td>
										<?php if ( $key->last_used_at ) : ?>
											<?php echo esc_html( date_i18n( 'M j, Y g:ia', strtotime( $key->last_used_at ) ) ); ?>
										<?php else : ?>
											<span class="description"><?php esc_html_e( 'Never', 'konx-affiliate-dashboard' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! $is_revoked ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
												onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this API key? Any system using it will lose access immediately.', 'konx-affiliate-dashboard' ) ); ?>');"
												style="display:inline;">
												<input type="hidden" name="action" value="konx_revoke_api_key">
												<input type="hidden" name="key_id" value="<?php echo esc_attr( $key->id ); ?>">
												<?php wp_nonce_field( 'konx_revoke_api_key_' . $key->id, 'konx_revoke_nonce' ); ?>
												<button type="submit" class="button button-link-delete" style="color:#d63638;">
													<?php esc_html_e( 'Revoke', 'konx-affiliate-dashboard' ); ?>
												</button>
											</form>
										<?php else : ?>
											<span class="description"><?php echo esc_html( date_i18n( 'M j', strtotime( $key->revoked_at ) ) ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<!-- Copy key script -->
		<script>
		function konxCopyApiKey() {
			var input = document.getElementById('konx-new-api-key');
			var btn = document.getElementById('konx-copy-key-btn');
			if (!input) return;
			input.select();
			input.setSelectionRange(0, 99999);
			try {
				navigator.clipboard.writeText(input.value).then(function() {
					btn.textContent = '<?php echo esc_js( __( 'Copied!', 'konx-affiliate-dashboard' ) ); ?>';
					btn.disabled = true;
					setTimeout(function() { btn.textContent = '<?php echo esc_js( __( 'Copy', 'konx-affiliate-dashboard' ) ); ?>'; btn.disabled = false; }, 2000);
				});
			} catch(e) {
				document.execCommand('copy');
				btn.textContent = '<?php echo esc_js( __( 'Copied!', 'konx-affiliate-dashboard' ) ); ?>';
			}
		}
		</script>
		<?php
	}

	// ------------------------------------------------------------------
	// Form Handlers
	// ------------------------------------------------------------------

	/**
	 * Handle API key generation form submission.
	 */
	public static function handle_generate() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		check_admin_referer( 'konx_generate_api_key', 'konx_api_key_nonce' );

		$name = isset( $_POST['key_name'] ) ? sanitize_text_field( wp_unslash( $_POST['key_name'] ) ) : '';

		if ( empty( $name ) ) {
			self::set_feedback( 'error', __( 'API key name is required.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-tools&tab=api-keys' ) );
			exit;
		}

		$result = Konx_Api_Helper::generate_key( $name, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			self::set_feedback( 'error', $result->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-tools&tab=api-keys' ) );
			exit;
		}

		// Store plaintext key in a user-specific transient for one-time display.
		set_transient( 'konx_new_api_key_' . get_current_user_id(), $result['key'], 60 );

		self::set_feedback( 'success', sprintf(
			/* translators: %s: key name */
			__( 'API key "%s" created successfully.', 'konx-affiliate-dashboard' ),
			$name
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=konx-tools&tab=api-keys' ) );
		exit;
	}

	/**
	 * Handle API key revocation form submission.
	 */
	public static function handle_revoke() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$key_id = isset( $_POST['key_id'] ) ? absint( $_POST['key_id'] ) : 0;

		check_admin_referer( 'konx_revoke_api_key_' . $key_id, 'konx_revoke_nonce' );

		if ( ! $key_id ) {
			self::set_feedback( 'error', __( 'Invalid key ID.', 'konx-affiliate-dashboard' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=konx-tools&tab=api-keys' ) );
			exit;
		}

		$revoked = Konx_Api_Helper::revoke_key( $key_id );

		if ( $revoked ) {
			self::set_feedback( 'success', __( 'API key revoked. Any system using this key will lose access immediately.', 'konx-affiliate-dashboard' ) );
		} else {
			self::set_feedback( 'error', __( 'Failed to revoke API key.', 'konx-affiliate-dashboard' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=konx-tools&tab=api-keys' ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Set feedback transient.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	private static function set_feedback( $type, $message ) {
		set_transient( 'konx_api_keys_feedback', array(
			'type'    => $type,
			'message' => $message,
		), 30 );
	}

	/**
	 * Get and clear feedback transient.
	 *
	 * @return array|false
	 */
	private static function get_feedback() {
		$feedback = get_transient( 'konx_api_keys_feedback' );
		if ( $feedback ) {
			delete_transient( 'konx_api_keys_feedback' );
		}
		return $feedback;
	}
}

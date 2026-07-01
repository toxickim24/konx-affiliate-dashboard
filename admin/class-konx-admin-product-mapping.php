<?php
/**
 * Admin page for product mapping.
 *
 * Provides a UI under "KonX Affiliates > Product Mapping" where
 * administrators can map WooCommerce products to internal commission
 * categories used by the commission engine.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Admin_Product_Mapping
 */
class Konx_Admin_Product_Mapping {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_konx_save_product_mapping', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_konx_delete_product_mapping', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_konx_auto_map_apply', array( __CLASS__, 'handle_auto_map_apply' ) );
	}

	/**
	 * Register the submenu page.
	 *
	 * The top-level menu is registered by Konx_Admin_Dashboard.
	 */
	public static function register_menu() {
		// Hidden from sidebar — accessed via Settings > Product Mapping tab.
		// Page slug kept registered for backward-compatible redirects.
		add_submenu_page(
			null,
			__( 'Product Mapping', 'konx-affiliate-dashboard' ),
			'',
			'manage_konx_settings',
			'konx-product-mapping',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the product mapping admin page (standalone).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}
		// Redirect to Settings > Product Mapping tab.
		wp_safe_redirect( admin_url( 'admin.php?page=konx-settings&tab=product-mapping' ) );
		exit;
	}

	/**
	 * Render product mapping content (embeddable in Settings tab).
	 */
	public static function render_content() {
		$mappings   = Konx_Product_Mapper::get_all_mappings();
		$categories = Konx_Product_Mapper::get_categories();
		$feedback   = self::get_feedback();

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );

		?>
		<h2><?php esc_html_e( 'Product Mapping', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'product_mapping' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
		<p><?php esc_html_e( 'Map WooCommerce products to commission categories. Search products by name or enter an ID directly.', 'konx-affiliate-dashboard' ); ?></p>

		<?php if ( $feedback ) : ?>
			<div class="notice notice-<?php echo esc_attr( $feedback['type'] ); ?> is-dismissible">
				<p><?php echo esc_html( $feedback['message'] ); ?></p>
			</div>
		<?php endif; ?>

			<div class="konx-form-card">
				<h2><?php esc_html_e( 'Add New Mapping', 'konx-affiliate-dashboard' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="konx_save_product_mapping">
					<?php wp_nonce_field( 'konx_save_product_mapping', 'konx_mapping_nonce' ); ?>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="konx_product_search"><?php esc_html_e( 'Product', 'konx-affiliate-dashboard' ); ?></label>
							</th>
							<td>
								<select id="konx_product_search" class="wc-product-search" name="product_id"
									data-placeholder="<?php esc_attr_e( 'Search for a product...', 'konx-affiliate-dashboard' ); ?>"
									data-action="woocommerce_json_search_products_and_variations"
									style="width:400px;" required>
								</select>
								<p class="description">
									<?php esc_html_e( 'Search by product name. Supports variations.', 'konx-affiliate-dashboard' ); ?>
								</p>
							</td>
						</tr>
					<tr>
						<th scope="row">
							<label for="konx_product_type"><?php esc_html_e( 'Commission Category', 'konx-affiliate-dashboard' ); ?></label>
						</th>
						<td>
							<select id="konx_product_type" name="product_type" required>
								<option value=""><?php esc_html_e( '— Select —', 'konx-affiliate-dashboard' ); ?></option>
								<?php foreach ( $categories as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="konx_product_label"><?php esc_html_e( 'Display Label', 'konx-affiliate-dashboard' ); ?></label>
						</th>
						<td>
							<input type="text" id="konx_product_label" name="product_label" class="regular-text">
							<p class="description">
								<?php esc_html_e( 'Optional. Auto-populated from the WooCommerce product name if left blank.', 'konx-affiliate-dashboard' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subscription Product', 'konx-affiliate-dashboard' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="is_subscription" value="1">
								<?php esc_html_e( 'This product has recurring billing (subscription)', 'konx-affiliate-dashboard' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Mapping', 'konx-affiliate-dashboard' ) ); ?>
			</form>
			</div><!-- .konx-form-card -->

			<?php self::render_auto_configure( $mappings, $categories ); ?>

			<h2><?php esc_html_e( 'Current Mappings', 'konx-affiliate-dashboard' ); ?></h2>
			<?php if ( empty( $mappings ) ) : ?>
				<p><?php esc_html_e( 'No product mappings configured yet.', 'konx-affiliate-dashboard' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product ID', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Product Name', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Category', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Subscription', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Active', 'konx-affiliate-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'konx-affiliate-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $mappings as $mapping ) : ?>
							<tr>
								<td><?php echo esc_html( $mapping->product_id ); ?></td>
								<td>
									<?php
									echo esc_html( $mapping->product_label );
									$wc_product = wc_get_product( $mapping->product_id );
									if ( ! $wc_product || ! $wc_product->exists() ) {
										echo ' <span style="color:#d63638;">(' . esc_html__( 'product not found', 'konx-affiliate-dashboard' ) . ')</span>';
									}
									?>
								</td>
								<td>
									<?php
									$cat_label = isset( $categories[ $mapping->product_type ] )
										? $categories[ $mapping->product_type ]
										: $mapping->product_type;
									echo esc_html( $cat_label );
									?>
								</td>
								<td><?php echo $mapping->is_subscription ? esc_html__( 'Yes', 'konx-affiliate-dashboard' ) : esc_html__( 'No', 'konx-affiliate-dashboard' ); ?></td>
								<td><?php echo $mapping->is_active ? esc_html__( 'Yes', 'konx-affiliate-dashboard' ) : esc_html__( 'No', 'konx-affiliate-dashboard' ); ?></td>
								<td>
									<?php
									$delete_url = wp_nonce_url(
										add_query_arg(
											array(
												'action'     => 'konx_delete_product_mapping',
												'product_id' => $mapping->product_id,
											),
											admin_url( 'admin-post.php' )
										),
										'konx_delete_mapping_' . $mapping->product_id
									);
									?>
									<a href="<?php echo esc_url( $delete_url ); ?>"
									   class="button button-small"
									   onclick="return confirm('<?php echo esc_js( __( 'Remove this mapping?', 'konx-affiliate-dashboard' ) ); ?>');">
										<?php esc_html_e( 'Remove', 'konx-affiliate-dashboard' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php
	}

	/**
	 * Handle the save mapping form submission.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		check_admin_referer( 'konx_save_product_mapping', 'konx_mapping_nonce' );

		$product_id      = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product_type    = isset( $_POST['product_type'] ) ? sanitize_text_field( wp_unslash( $_POST['product_type'] ) ) : '';
		$product_label   = isset( $_POST['product_label'] ) ? sanitize_text_field( wp_unslash( $_POST['product_label'] ) ) : '';
		$is_subscription = ! empty( $_POST['is_subscription'] );

		if ( ! $product_id || ! $product_type ) {
			self::redirect_with_feedback( 'error', __( 'Product ID and category are required.', 'konx-affiliate-dashboard' ) );
			return;
		}

		$result = Konx_Product_Mapper::map_product( $product_id, $product_type, $product_label, $is_subscription );

		if ( is_wp_error( $result ) ) {
			self::redirect_with_feedback( 'error', $result->get_error_message() );
		} else {
			self::redirect_with_feedback( 'success', __( 'Product mapping saved.', 'konx-affiliate-dashboard' ) );
		}
	}

	/**
	 * Handle the delete mapping action.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		check_admin_referer( 'konx_delete_mapping_' . $product_id );

		if ( ! $product_id ) {
			self::redirect_with_feedback( 'error', __( 'Invalid product ID.', 'konx-affiliate-dashboard' ) );
			return;
		}

		$removed = Konx_Product_Mapper::remove_mapping( $product_id );

		if ( $removed ) {
			self::redirect_with_feedback( 'success', __( 'Product mapping removed.', 'konx-affiliate-dashboard' ) );
		} else {
			self::redirect_with_feedback( 'error', __( 'Failed to remove mapping.', 'konx-affiliate-dashboard' ) );
		}
	}

	// ------------------------------------------------------------------
	// Auto Configure
	// ------------------------------------------------------------------

	/**
	 * Keyword patterns for auto-detecting commission categories.
	 *
	 * Each category maps to an array of lowercase keyword patterns.
	 * Patterns are checked with stripos against the product name.
	 * More specific patterns are listed first to avoid false positives.
	 *
	 * @var array
	 */
	private static $auto_map_patterns = array(
		'enterprise_conference' => array( 'enterprise conference', 'enterprise room', 'ecr' ),
		'corporate_conference'  => array( 'corporate conference', 'corporate room', 'ccr' ),
		'business_conference'   => array( 'business conference', 'business room', 'bcr' ),
		'basic_pro_conference'  => array( 'basic pro conference', 'basic pro room' ),
		'pro_pack'              => array( 'pro pack' ),
		'ecard_pack'            => array( 'ecard pack', 'e-card pack' ),
		'starter_pack'          => array( 'starter pack', 'starter kit' ),
	);

	/**
	 * Build a preview of auto-map proposals.
	 *
	 * Scans all published WooCommerce products, matches names against
	 * keyword patterns, and returns proposed mappings. Does NOT write
	 * anything to the database.
	 *
	 * @return array Array of proposals: { product_id, product_name, proposed_category, proposed_label, already_mapped, current_category }.
	 */
	public static function build_auto_map_preview() {
		$proposals  = array();
		$categories = Konx_Product_Mapper::get_categories();
		$existing   = Konx_Product_Mapper::get_all_mappings();

		// Index existing mappings by product_id.
		$mapped = array();
		foreach ( $existing as $m ) {
			$mapped[ (int) $m->product_id ] = $m->product_type;
		}

		// Query published WooCommerce products (not variations — parent products only).
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'type'   => array( 'simple', 'variable', 'subscription', 'variable-subscription' ),
				'return' => 'objects',
			)
		);

		foreach ( $products as $product ) {
			$pid  = $product->get_id();
			$name = $product->get_name();

			// Try to match name against patterns.
			$matched_category = self::match_product_name( $name );
			if ( ! $matched_category ) {
				continue;
			}

			$proposals[] = array(
				'product_id'        => $pid,
				'product_name'      => $name,
				'proposed_category' => $matched_category,
				'proposed_label'    => isset( $categories[ $matched_category ] ) ? $categories[ $matched_category ] : $matched_category,
				'already_mapped'    => isset( $mapped[ $pid ] ),
				'current_category'  => isset( $mapped[ $pid ] ) ? $mapped[ $pid ] : null,
			);
		}

		return $proposals;
	}

	/**
	 * Match a product name to a commission category using keyword patterns.
	 *
	 * @param string $name The WooCommerce product name.
	 * @return string|null The matched category slug, or null if no match.
	 */
	private static function match_product_name( $name ) {
		$lower = strtolower( $name );

		foreach ( self::$auto_map_patterns as $category => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $lower, $pattern ) ) {
					return $category;
				}
			}
		}

		return null;
	}

	/**
	 * Render the Auto Configure section.
	 *
	 * @param array $mappings   Current mappings.
	 * @param array $categories Category slug => label map.
	 */
	private static function render_auto_configure( $mappings, $categories ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$show_preview = isset( $_GET['auto_map_preview'] ) && '1' === $_GET['auto_map_preview'];
		$proposals    = $show_preview ? self::build_auto_map_preview() : array();

		?>
		<div class="konx-form-card" style="margin:20px 0;border-left:4px solid #2271b1;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Auto Configure Product Mapping', 'konx-affiliate-dashboard' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Automatically detect WooCommerce products and map them to KonX commission categories based on product names. Existing mappings will not be overwritten.', 'konx-affiliate-dashboard' ); ?></p>

			<?php if ( ! $show_preview ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-settings&tab=product-mapping&auto_map_preview=1' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Preview Auto Configuration', 'konx-affiliate-dashboard' ); ?>
				</a>
			<?php else : ?>
				<?php if ( empty( $proposals ) ) : ?>
					<div class="notice notice-info inline" style="margin:12px 0;">
						<p><?php esc_html_e( 'No unmapped products could be auto-detected. All products may already be mapped, or product names do not match any known commission categories.', 'konx-affiliate-dashboard' ); ?></p>
					</div>
				<?php else : ?>
					<?php
					$new_count     = 0;
					$skip_count    = 0;
					foreach ( $proposals as $p ) {
						if ( $p['already_mapped'] ) {
							$skip_count++;
						} else {
							$new_count++;
						}
					}
					?>
					<div class="konx-stats-grid" style="margin:12px 0;">
						<?php
						// Inline stat cards since we may not have the full admin dashboard loaded.
						?>
						<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px;text-align:center;">
							<div style="font-size:24px;font-weight:700;color:#2271b1;"><?php echo esc_html( count( $proposals ) ); ?></div>
							<div style="font-size:12px;color:#646970;"><?php esc_html_e( 'Products Detected', 'konx-affiliate-dashboard' ); ?></div>
						</div>
						<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px;text-align:center;">
							<div style="font-size:24px;font-weight:700;color:#00a32a;"><?php echo esc_html( $new_count ); ?></div>
							<div style="font-size:12px;color:#646970;"><?php esc_html_e( 'New Mappings', 'konx-affiliate-dashboard' ); ?></div>
						</div>
						<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px;text-align:center;">
							<div style="font-size:24px;font-weight:700;color:#dba617;"><?php echo esc_html( $skip_count ); ?></div>
							<div style="font-size:12px;color:#646970;"><?php esc_html_e( 'Already Mapped (skip)', 'konx-affiliate-dashboard' ); ?></div>
						</div>
					</div>

					<table class="widefat fixed striped" style="margin:12px 0;">
						<thead>
							<tr>
								<th style="width:70px;"><?php esc_html_e( 'ID', 'konx-affiliate-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Product Name', 'konx-affiliate-dashboard' ); ?></th>
								<th><?php esc_html_e( 'Proposed Category', 'konx-affiliate-dashboard' ); ?></th>
								<th style="width:120px;"><?php esc_html_e( 'Action', 'konx-affiliate-dashboard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $proposals as $p ) : ?>
								<tr<?php echo $p['already_mapped'] ? ' style="opacity:0.5;"' : ''; ?>>
									<td><?php echo esc_html( $p['product_id'] ); ?></td>
									<td><?php echo esc_html( $p['product_name'] ); ?></td>
									<td><?php echo esc_html( $p['proposed_label'] ); ?></td>
									<td>
										<?php if ( $p['already_mapped'] ) : ?>
											<span style="color:#dba617;font-size:12px;font-weight:600;"><?php esc_html_e( 'Skip', 'konx-affiliate-dashboard' ); ?></span>
											<span class="description" style="font-size:11px;"> (<?php echo esc_html( isset( $categories[ $p['current_category'] ] ) ? $categories[ $p['current_category'] ] : $p['current_category'] ); ?>)</span>
										<?php else : ?>
											<span style="color:#00a32a;font-size:12px;font-weight:600;"><?php esc_html_e( 'Create', 'konx-affiliate-dashboard' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( $new_count > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
							<input type="hidden" name="action" value="konx_auto_map_apply">
							<?php wp_nonce_field( 'konx_auto_map_apply', 'konx_auto_map_nonce' ); ?>
							<p style="margin-bottom:8px;">
								<strong><?php printf( esc_html__( 'This will create %d new product mapping(s). Existing mappings will not be changed.', 'konx-affiliate-dashboard' ), $new_count ); ?></strong>
							</p>
							<?php submit_button( sprintf( __( 'Apply %d Mapping(s)', 'konx-affiliate-dashboard' ), $new_count ), 'primary', '', false ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=konx-settings&tab=product-mapping' ) ); ?>" class="button" style="margin-left:8px;">
								<?php esc_html_e( 'Cancel', 'konx-affiliate-dashboard' ); ?>
							</a>
						</form>
					<?php else : ?>
						<div class="notice notice-success inline" style="margin:12px 0;">
							<p><?php esc_html_e( 'All detected products are already mapped. No changes needed.', 'konx-affiliate-dashboard' ); ?></p>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the auto-map apply action.
	 *
	 * Creates new product mappings for all auto-detected products that
	 * are not already mapped. Never overwrites existing mappings.
	 */
	public static function handle_auto_map_apply() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		check_admin_referer( 'konx_auto_map_apply', 'konx_auto_map_nonce' );

		$proposals = self::build_auto_map_preview();
		$created   = 0;
		$skipped   = 0;
		$errors    = 0;

		foreach ( $proposals as $p ) {
			if ( $p['already_mapped'] ) {
				$skipped++;
				continue;
			}

			$result = Konx_Product_Mapper::map_product(
				$p['product_id'],
				$p['proposed_category'],
				$p['product_name'],
				false
			);

			if ( is_wp_error( $result ) ) {
				$errors++;
			} else {
				$created++;
			}
		}

		if ( $errors > 0 ) {
			$msg = sprintf(
				__( 'Auto configuration complete: %1$d created, %2$d skipped, %3$d errors.', 'konx-affiliate-dashboard' ),
				$created,
				$skipped,
				$errors
			);
			self::redirect_with_feedback( 'warning', $msg );
		} else {
			$msg = sprintf(
				__( 'Auto configuration complete: %1$d mapping(s) created, %2$d already mapped (skipped).', 'konx-affiliate-dashboard' ),
				$created,
				$skipped
			);
			self::redirect_with_feedback( 'success', $msg );
		}
	}

	/**
	 * Redirect back to the mapping page with a feedback message.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message The feedback message.
	 */
	private static function redirect_with_feedback( $type, $message ) {
		set_transient( 'konx_mapping_feedback', array(
			'type'    => $type,
			'message' => $message,
		), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=konx-settings&tab=product-mapping' ) );
		exit;
	}

	/**
	 * Get and clear any stored feedback message.
	 *
	 * @return array|false Feedback array with 'type' and 'message', or false.
	 */
	private static function get_feedback() {
		$feedback = get_transient( 'konx_mapping_feedback' );
		if ( $feedback ) {
			delete_transient( 'konx_mapping_feedback' );
		}
		return $feedback;
	}
}

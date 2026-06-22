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
	}

	/**
	 * Register the submenu page.
	 *
	 * The top-level menu is registered by Konx_Admin_Dashboard.
	 */
	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Product Mapping', 'konx-affiliate-dashboard' ),
			__( 'Product Mapping', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-product-mapping',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the product mapping admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'konx-affiliate-dashboard' ) );
		}

		$mappings   = Konx_Product_Mapper::get_all_mappings();
		$categories = Konx_Product_Mapper::get_categories();
		$feedback   = self::get_feedback();

		?>
		<?php wp_enqueue_script( 'wc-enhanced-select' ); wp_enqueue_style( 'woocommerce_admin_styles' ); ?>

		<div class="wrap">
			<div class="konx-page-header">
				<h1><?php esc_html_e( 'Product Mapping', 'konx-affiliate-dashboard' ); ?> <?php echo Konx_Tooltip_Helper::get( 'product_mapping' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
			</div>
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
		</div>
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

		wp_safe_redirect( admin_url( 'admin.php?page=konx-product-mapping' ) );
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

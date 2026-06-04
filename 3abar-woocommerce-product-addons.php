<?php
/**
 * Plugin Name: 3abar WooCommerce Product Addons
 * Description: Product-level WooCommerce addon selector with an elegant AJAX modal and configurable price adjustments.
 * Author: Shawky El Moazamy
 * Author URI: https://3abar.com
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 *
 * @package ThreeabarWooCommerceProductAddons
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Threeabar_WooCommerce_Product_Addons' ) ) {
	/**
	 * A compact one-file WooCommerce plugin for configurable attached products.
	 */
	final class Threeabar_WooCommerce_Product_Addons {
		private const VERSION             = '1.0.0';
		private const META_PRODUCT_IDS    = '_threeabar_addon_product_ids';
		private const META_CATEGORY_IDS   = '_threeabar_addon_category_ids';
		private const META_MAX_CHOICES    = '_threeabar_addon_max_choices';
		private const META_PRICE_TYPE     = '_threeabar_addon_price_type';
		private const META_PRICE_AMOUNT   = '_threeabar_addon_price_amount';
		private const ADMIN_SAVE_ACTION   = 'threeabar_save_product_addons';
		private const ADMIN_SAVE_NONCE    = 'threeabar_product_addons_nonce';
		private const ADMIN_AJAX_NONCE    = 'threeabar_admin_addons_nonce';
		private const FRONTEND_NONCE      = 'threeabar_frontend_addons_nonce';
		private const ADMIN_STYLE_HANDLE  = 'threeabar-wc-product-addons-admin';
		private const FRONT_STYLE_HANDLE  = 'threeabar-wc-product-addons-front';

		/**
		 * Boot hooks.
		 */
		public function __construct() {
			add_action( 'admin_notices', array( $this, 'maybe_show_woocommerce_notice' ) );

			if ( ! $this->is_woocommerce_active() ) {
				return;
			}

			add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
			add_action( 'save_post_product', array( $this, 'save_product_meta' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
			add_action( 'wp_footer', array( $this, 'render_frontend_modal' ) );
			add_action( 'wp', array( $this, 'maybe_replace_single_add_to_cart_button' ) );

			add_action( 'wp_ajax_threeabar_search_addon_products', array( $this, 'ajax_search_admin_products' ) );
			add_action( 'wp_ajax_threeabar_search_addon_categories', array( $this, 'ajax_search_admin_categories' ) );
			add_action( 'wp_ajax_threeabar_get_addon_products', array( $this, 'ajax_get_frontend_addons' ) );
			add_action( 'wp_ajax_nopriv_threeabar_get_addon_products', array( $this, 'ajax_get_frontend_addons' ) );
			add_action( 'wp_ajax_threeabar_add_product_bundle', array( $this, 'ajax_add_product_bundle' ) );
			add_action( 'wp_ajax_nopriv_threeabar_add_product_bundle', array( $this, 'ajax_add_product_bundle' ) );

			add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_adjusted_cart_prices' ), 20 );
			add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		}

		/**
		 * Confirm WooCommerce is available.
		 */
		private function is_woocommerce_active(): bool {
			return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
		}

		/**
		 * Show a clear dependency notice in wp-admin.
		 */
		public function maybe_show_woocommerce_notice(): void {
			if ( $this->is_woocommerce_active() || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p><strong>3abar WooCommerce Product Addons</strong> يحتاج إلى تفعيل WooCommerce أولاً.</p></div>';
		}

		/**
		 * Add the product edit screen meta box.
		 */
		public function register_meta_box(): void {
			add_meta_box(
				'threeabar_wc_product_addons',
				'إضافات 3abar - المنتجات المرفقة',
				array( $this, 'render_meta_box' ),
				'product',
				'normal',
				'high'
			);
		}

		/**
		 * Render the admin product settings UI.
		 *
		 * @param WP_Post $post Current product post.
		 */
		public function render_meta_box( WP_Post $post ): void {
			$settings       = $this->get_product_settings( $post->ID );
			$selected_items = $this->get_selected_product_options( $settings['product_ids'] );
			$selected_terms = $this->get_selected_category_options( $settings['category_ids'] );

			wp_nonce_field( self::ADMIN_SAVE_ACTION, self::ADMIN_SAVE_NONCE );
			?>
			<div class="threeabar-admin-panel" dir="rtl">
				<div class="threeabar-admin-hero">
					<div>
						<span class="threeabar-admin-kicker">3abar Experience Builder</span>
						<h3>اربط منتجاتك بطريقة ذكية ومبهرة</h3>
						<p>اختر منتجات أو تصنيفات كاملة، وحدد عدد الاختيارات وسياسة السعر التي ستظهر داخل نافذة أنيقة في صفحة المنتج.</p>
					</div>
					<div class="threeabar-admin-orb" aria-hidden="true"></div>
				</div>

				<div class="threeabar-admin-grid">
					<label class="threeabar-admin-field threeabar-admin-field-wide">
						<span>منتجات محددة</span>
						<select class="threeabar-admin-select threeabar-product-search" name="threeabar_addon_product_ids[]" multiple="multiple" data-placeholder="ابحث باسم المنتج أو SKU...">
							<?php foreach ( $selected_items as $item ) : ?>
								<option value="<?php echo esc_attr( $item['id'] ); ?>" selected="selected"><?php echo esc_html( $item['text'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<small>يمكنك تحديد منتجات بعينها لتظهر كإضافات لهذا المنتج.</small>
					</label>

					<label class="threeabar-admin-field threeabar-admin-field-wide">
						<span>تصنيفات</span>
						<select class="threeabar-admin-select threeabar-category-search" name="threeabar_addon_category_ids[]" multiple="multiple" data-placeholder="ابحث في تصنيفات المنتجات...">
							<?php foreach ( $selected_terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term['id'] ); ?>" selected="selected"><?php echo esc_html( $term['text'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<small>كل المنتجات القابلة للشراء داخل هذه التصنيفات ستظهر في الـ Popup.</small>
					</label>

					<label class="threeabar-admin-field">
						<span>الحد الأقصى لعدد المنتجات القابلة للاختيار</span>
						<input type="number" name="threeabar_addon_max_choices" min="1" step="1" value="<?php echo esc_attr( $settings['max_choices'] ); ?>" />
						<small>إذا كان الرقم 1 سيتم استخدام Radio Buttons، وإذا كان أكبر من 1 سيتم استخدام Checkboxes.</small>
					</label>

					<label class="threeabar-admin-field">
						<span>سياسة السعر</span>
						<select name="threeabar_addon_price_type" class="threeabar-price-type">
							<?php foreach ( $this->get_price_types() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['price_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<small>تُطبق السياسة على سعر كل منتج إضافي داخل النافذة وعند الإضافة للسلة.</small>
					</label>

					<label class="threeabar-admin-field threeabar-price-amount-wrap">
						<span>قيمة الزيادة أو الخصم</span>
						<input type="number" name="threeabar_addon_price_amount" min="0" step="0.01" value="<?php echo esc_attr( $settings['price_amount'] ); ?>" />
						<small>اتركها 0 عند استخدام السعر الأصلي. في النسبة المئوية اكتب الرقم فقط، مثل 15.</small>
					</label>
				</div>
			</div>
			<?php
		}

		/**
		 * Save the product meta box settings.
		 *
		 * @param int     $post_id Current product ID.
		 * @param WP_Post $post    Product post object.
		 */
		public function save_product_meta( int $post_id, WP_Post $post ): void {
			if ( ! isset( $_POST[ self::ADMIN_SAVE_NONCE ] ) ) {
				return;
			}

			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::ADMIN_SAVE_NONCE ] ) );
			if ( ! wp_verify_nonce( $nonce, self::ADMIN_SAVE_ACTION ) ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( 'product' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$product_ids  = isset( $_POST['threeabar_addon_product_ids'] ) ? $this->normalize_int_array( wp_unslash( $_POST['threeabar_addon_product_ids'] ) ) : array();
			$category_ids = isset( $_POST['threeabar_addon_category_ids'] ) ? $this->normalize_int_array( wp_unslash( $_POST['threeabar_addon_category_ids'] ) ) : array();
			$max_choices  = isset( $_POST['threeabar_addon_max_choices'] ) ? max( 1, absint( wp_unslash( $_POST['threeabar_addon_max_choices'] ) ) ) : 1;
			$price_type   = isset( $_POST['threeabar_addon_price_type'] ) ? sanitize_key( wp_unslash( $_POST['threeabar_addon_price_type'] ) ) : 'original';
			$amount_raw   = isset( $_POST['threeabar_addon_price_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['threeabar_addon_price_amount'] ) ) : 0;
			$amount       = max( 0, (float) $amount_raw );

			if ( ! array_key_exists( $price_type, $this->get_price_types() ) ) {
				$price_type = 'original';
			}

			update_post_meta( $post_id, self::META_PRODUCT_IDS, array_values( array_diff( $product_ids, array( $post_id ) ) ) );
			update_post_meta( $post_id, self::META_CATEGORY_IDS, $category_ids );
			update_post_meta( $post_id, self::META_MAX_CHOICES, $max_choices );
			update_post_meta( $post_id, self::META_PRICE_TYPE, $price_type );
			update_post_meta( $post_id, self::META_PRICE_AMOUNT, $amount );
		}

		/**
		 * Enqueue admin scripts and styles only on the product editor.
		 *
		 * @param string $hook Current admin hook.
		 */
		public function enqueue_admin_assets( string $hook ): void {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'product' !== $screen->id ) {
				return;
			}

			wp_enqueue_script( 'jquery' );

			if ( wp_script_is( 'selectWoo', 'registered' ) ) {
				wp_enqueue_script( 'selectWoo' );
			} elseif ( wp_script_is( 'select2', 'registered' ) ) {
				wp_enqueue_script( 'select2' );
			}

			if ( wp_style_is( 'select2', 'registered' ) ) {
				wp_enqueue_style( 'select2' );
			}

			wp_register_style( self::ADMIN_STYLE_HANDLE, false, array(), self::VERSION );
			wp_enqueue_style( self::ADMIN_STYLE_HANDLE );
			wp_add_inline_style( self::ADMIN_STYLE_HANDLE, $this->get_admin_css() );

			wp_localize_script(
				'jquery',
				'ThreeabarWCAddonsAdmin',
				array(
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( self::ADMIN_AJAX_NONCE ),
					'noSelect2Text' => 'Select2 غير متاح، سيتم عرض القوائم بالشكل الافتراضي.',
				)
			);
			wp_add_inline_script( 'jquery', $this->get_admin_js() );
		}

		/**
		 * Search WooCommerce products for the admin Select2 field.
		 */
		public function ajax_search_admin_products(): void {
			check_ajax_referer( self::ADMIN_AJAX_NONCE, 'nonce' );

			if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => 'غير مصرح.' ), 403 );
			}

			$term  = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
			$items = array();

			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => 20,
					's'              => $term,
					'fields'         => 'ids',
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				$sku     = $product->get_sku();
				$items[] = array(
					'id'   => $product_id,
					'text' => $product->get_name() . ( $sku ? ' - SKU: ' . $sku : '' ),
				);
			}

			wp_send_json( array( 'results' => $items ) );
		}

		/**
		 * Search product categories for the admin Select2 field.
		 */
		public function ajax_search_admin_categories(): void {
			check_ajax_referer( self::ADMIN_AJAX_NONCE, 'nonce' );

			if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => 'غير مصرح.' ), 403 );
			}

			$term  = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
			$items = array();
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'number'     => 20,
					'search'     => $term,
				)
			);

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term_item ) {
					$items[] = array(
						'id'   => $term_item->term_id,
						'text' => $term_item->name,
					);
				}
			}

			wp_send_json( array( 'results' => $items ) );
		}

		/**
		 * Replace the default single product add-to-cart area when configured.
		 */
		public function maybe_replace_single_add_to_cart_button(): void {
			if ( ! function_exists( 'is_product' ) || ! is_product() ) {
				return;
			}

			$product = wc_get_product( get_queried_object_id() );
			if ( ! $this->product_should_use_addons( $product ) ) {
				return;
			}

			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_custom_add_to_cart_button' ), 30 );
		}

		/**
		 * Enqueue frontend modal assets only on eligible product pages.
		 */
		public function enqueue_frontend_assets(): void {
			if ( ! function_exists( 'is_product' ) || ! is_product() ) {
				return;
			}

			$product = wc_get_product( get_queried_object_id() );
			if ( ! $this->product_should_use_addons( $product ) ) {
				return;
			}

			wp_enqueue_script( 'jquery' );
			wp_register_style( self::FRONT_STYLE_HANDLE, false, array(), self::VERSION );
			wp_enqueue_style( self::FRONT_STYLE_HANDLE );
			wp_add_inline_style( self::FRONT_STYLE_HANDLE, $this->get_frontend_css() );

			wp_localize_script(
				'jquery',
				'ThreeabarWCAddonsFrontend',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'nonce'          => wp_create_nonce( self::FRONTEND_NONCE ),
					'cartUrl'        => wc_get_cart_url(),
					'i18n'           => array(
						'loading'      => 'جاري تحميل المنتجات الإضافية...',
						'empty'        => 'لا توجد منتجات مطابقة لبحثك.',
						'error'        => 'حدث خطأ غير متوقع. حاول مرة أخرى.',
						'limit'        => 'وصلت للحد الأقصى للاختيار.',
						'adding'       => 'جاري الإضافة...',
						'addToCart'    => 'إضافة إلى السلة',
						'chooseAddons' => 'اختر المنتجات الإضافية',
					),
				)
			);
			wp_add_inline_script( 'jquery', $this->get_frontend_js() );
		}

		/**
		 * Print the custom product page button.
		 */
		public function render_custom_add_to_cart_button(): void {
			$product = wc_get_product( get_queried_object_id() );
			if ( ! $product ) {
				return;
			}

			echo '<button type="button" class="single_add_to_cart_button button alt threeabar-open-addons" data-product-id="' . esc_attr( $product->get_id() ) . '">';
			echo '<span>اختر الإضافات وأضف للسلة</span>';
			echo '<i aria-hidden="true">+</i>';
			echo '</button>';
		}

		/**
		 * Render the modal container in the footer.
		 */
		public function render_frontend_modal(): void {
			if ( ! function_exists( 'is_product' ) || ! is_product() ) {
				return;
			}

			$product = wc_get_product( get_queried_object_id() );
			if ( ! $this->product_should_use_addons( $product ) ) {
				return;
			}
			?>
			<div class="threeabar-addon-modal" id="threeabar-addon-modal" aria-hidden="true" dir="rtl">
				<div class="threeabar-addon-backdrop" data-threeabar-close></div>
				<section class="threeabar-addon-dialog" role="dialog" aria-modal="true" aria-labelledby="threeabar-addon-title">
					<button type="button" class="threeabar-addon-close" data-threeabar-close aria-label="إغلاق">×</button>
					<div class="threeabar-addon-header">
						<span class="threeabar-addon-eyebrow">3abar Smart Addons</span>
						<h2 id="threeabar-addon-title">اختر المنتجات الإضافية</h2>
						<p>اكتشف إضافات مناسبة، وابحث بسرعة، ثم أضف كل شيء للسلة بضغطة واحدة.</p>
					</div>
					<div class="threeabar-addon-search-wrap">
						<input type="search" class="threeabar-addon-search" placeholder="ابحث باسم المنتج..." autocomplete="off" />
						<span aria-hidden="true">⌕</span>
					</div>
					<div class="threeabar-addon-status" role="status" aria-live="polite"></div>
					<div class="threeabar-addon-products"></div>
					<div class="threeabar-addon-footer">
						<div class="threeabar-addon-count"></div>
						<button type="button" class="threeabar-addon-submit">إضافة إلى السلة</button>
					</div>
				</section>
			</div>
			<?php
		}

		/**
		 * Return allowed addon products for the frontend modal.
		 */
		public function ajax_get_frontend_addons(): void {
			check_ajax_referer( self::FRONTEND_NONCE, 'nonce' );

			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
			$product    = wc_get_product( $product_id );

			if ( ! $this->product_should_use_addons( $product ) ) {
				wp_send_json_error( array( 'message' => 'هذا المنتج غير متاح للإضافات.' ), 400 );
			}

			$settings = $this->get_product_settings( $product_id );
			$ids      = $this->get_allowed_addon_product_ids( $product_id, $search, 80 );
			$items    = array();

			foreach ( $ids as $addon_id ) {
				$addon = wc_get_product( $addon_id );
				if ( ! $this->is_cartable_addon_product( $addon ) ) {
					continue;
				}

				$adjusted_price = $this->calculate_adjusted_price( $addon, $settings );
				$image_url      = $this->get_product_image_url( $addon );
				$original_price = (float) $addon->get_price();
				$price_html     = wc_price( wc_get_price_to_display( $addon, array( 'price' => $adjusted_price ) ) );
				$original_html  = wc_price( wc_get_price_to_display( $addon, array( 'price' => $original_price ) ) );

				$items[] = array(
					'id'             => $addon_id,
					'name'           => $addon->get_name(),
					'priceHtml'      => $price_html,
					'originalHtml'   => $original_html,
					'priceChanged'   => 'original' !== $settings['price_type'],
					'image'          => $image_url,
					'type'           => $settings['max_choices'] > 1 ? 'checkbox' : 'radio',
					'adjustmentText' => $this->get_price_adjustment_label( $settings ),
				);
			}

			wp_send_json_success(
				array(
					'products'      => $items,
					'maxChoices'    => $settings['max_choices'],
					'selectionType' => $settings['max_choices'] > 1 ? 'checkbox' : 'radio',
					'count'         => count( $items ),
				)
			);
		}

		/**
		 * Add the base product and selected addons to the WooCommerce cart.
		 */
		public function ajax_add_product_bundle(): void {
			check_ajax_referer( self::FRONTEND_NONCE, 'nonce' );

			if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
				wc_load_cart();
			}

			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			$addons     = isset( $_POST['addons'] ) ? $this->normalize_int_array( wp_unslash( $_POST['addons'] ) ) : array();
			$product    = wc_get_product( $product_id );

			if ( ! $this->product_should_use_addons( $product ) ) {
				wp_send_json_error( array( 'message' => 'لا يمكن إضافة هذا المنتج بهذه الطريقة.' ), 400 );
			}

			$settings = $this->get_product_settings( $product_id );
			if ( count( $addons ) > $settings['max_choices'] ) {
				wp_send_json_error( array( 'message' => 'عدد المنتجات المختارة أكبر من الحد المسموح.' ), 400 );
			}

			$allowed_ids = $this->get_allowed_addon_product_ids( $product_id, '', -1 );
			foreach ( $addons as $addon_id ) {
				if ( ! in_array( $addon_id, $allowed_ids, true ) ) {
					wp_send_json_error( array( 'message' => 'يوجد منتج إضافي غير مسموح.' ), 400 );
				}
			}

			$group_key = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'threeabar_', true );
			$added     = array();

			$base_key = WC()->cart->add_to_cart(
				$product_id,
				1,
				0,
				array(),
				array(
					'threeabar_group_key' => $group_key,
					'threeabar_role'      => 'parent',
					'threeabar_unique'    => $group_key . '_parent',
				)
			);

			if ( ! $base_key ) {
				wp_send_json_error( array( 'message' => $this->get_cart_error_message() ), 400 );
			}
			$added[] = $base_key;

			foreach ( $addons as $addon_id ) {
				$addon = wc_get_product( $addon_id );
				if ( ! $this->is_cartable_addon_product( $addon ) ) {
					$this->rollback_cart_items( $added );
					wp_send_json_error( array( 'message' => 'أحد المنتجات الإضافية غير قابل للإضافة للسلة.' ), 400 );
				}

				$adjusted_price = $this->calculate_adjusted_price( $addon, $settings );
				$addon_key      = WC()->cart->add_to_cart(
					$addon_id,
					1,
					0,
					array(),
					array(
						'threeabar_group_key'       => $group_key,
						'threeabar_role'            => 'addon',
						'threeabar_parent_id'       => $product_id,
						'threeabar_adjusted_price'  => $adjusted_price,
						'threeabar_original_price'  => (float) $addon->get_price(),
						'threeabar_adjustment_text' => $this->get_price_adjustment_label( $settings ),
						'threeabar_unique'          => $group_key . '_addon_' . $addon_id,
					)
				);

				if ( ! $addon_key ) {
					$this->rollback_cart_items( $added );
					wp_send_json_error( array( 'message' => $this->get_cart_error_message() ), 400 );
				}

				$added[] = $addon_key;
			}

			wp_send_json_success(
				array(
					'message'  => 'تمت إضافة المنتج والإضافات إلى السلة.',
					'cartUrl'  => wc_get_cart_url(),
					'cartHash' => WC()->cart->get_cart_hash(),
				)
			);
		}

		/**
		 * Apply stored adjusted addon prices before totals are calculated.
		 *
		 * @param WC_Cart $cart Cart object.
		 */
		public function apply_adjusted_cart_prices( $cart ): void {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return;
			}

			if ( ! $cart instanceof WC_Cart ) {
				return;
			}

			foreach ( $cart->get_cart() as $cart_item ) {
				if ( isset( $cart_item['threeabar_adjusted_price'], $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) {
					$cart_item['data']->set_price( (float) $cart_item['threeabar_adjusted_price'] );
				}
			}
		}

		/**
		 * Add helpful metadata below addon cart items.
		 *
		 * @param array $item_data Cart item data.
		 * @param array $cart_item Cart item.
		 */
		public function display_cart_item_data( array $item_data, array $cart_item ): array {
			if ( empty( $cart_item['threeabar_parent_id'] ) ) {
				return $item_data;
			}

			$parent = wc_get_product( absint( $cart_item['threeabar_parent_id'] ) );
			if ( $parent ) {
				$item_data[] = array(
					'key'   => 'إضافة مرفقة مع',
					'value' => esc_html( $parent->get_name() ),
				);
			}

			if ( ! empty( $cart_item['threeabar_adjustment_text'] ) ) {
				$item_data[] = array(
					'key'   => 'سياسة السعر',
					'value' => esc_html( $cart_item['threeabar_adjustment_text'] ),
				);
			}

			return $item_data;
		}

		/**
		 * Load normalized product settings.
		 *
		 * @param int $product_id Product ID.
		 */
		private function get_product_settings( int $product_id ): array {
			$price_type = sanitize_key( (string) get_post_meta( $product_id, self::META_PRICE_TYPE, true ) );
			if ( ! array_key_exists( $price_type, $this->get_price_types() ) ) {
				$price_type = 'original';
			}

			$max_choices = absint( get_post_meta( $product_id, self::META_MAX_CHOICES, true ) );

			return array(
				'product_ids'  => $this->normalize_int_array( get_post_meta( $product_id, self::META_PRODUCT_IDS, true ) ),
				'category_ids' => $this->normalize_int_array( get_post_meta( $product_id, self::META_CATEGORY_IDS, true ) ),
				'max_choices'  => max( 1, $max_choices ?: 1 ),
				'price_type'   => $price_type,
				'price_amount' => max( 0, (float) get_post_meta( $product_id, self::META_PRICE_AMOUNT, true ) ),
			);
		}

		/**
		 * Supported price adjustment policies.
		 */
		private function get_price_types(): array {
			return array(
				'original'         => 'استخدم سعر المنتج الأصلي',
				'fixed_increase'   => 'زيادة ثابتة',
				'fixed_discount'   => 'خصم ثابت',
				'percent_increase' => 'زيادة بنسبة %',
				'percent_discount' => 'خصم بنسبة %',
			);
		}

		/**
		 * Prepare selected product option labels.
		 *
		 * @param array $ids Product IDs.
		 */
		private function get_selected_product_options( array $ids ): array {
			$options = array();

			foreach ( $ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				$sku       = $product->get_sku();
				$options[] = array(
					'id'   => $product_id,
					'text' => $product->get_name() . ( $sku ? ' - SKU: ' . $sku : '' ),
				);
			}

			return $options;
		}

		/**
		 * Prepare selected category option labels.
		 *
		 * @param array $ids Category IDs.
		 */
		private function get_selected_category_options( array $ids ): array {
			if ( empty( $ids ) ) {
				return array();
			}

			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'include'    => $ids,
				)
			);

			if ( is_wp_error( $terms ) ) {
				return array();
			}

			$options = array();
			foreach ( $terms as $term ) {
				$options[] = array(
					'id'   => $term->term_id,
					'text' => $term->name,
				);
			}

			return $options;
		}

		/**
		 * Decide whether this simple product can use the custom addon flow.
		 *
		 * @param mixed $product Product candidate.
		 */
		private function product_should_use_addons( $product ): bool {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}

			if ( ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				return false;
			}

			return $this->product_has_addon_configuration( $product->get_id() );
		}

		/**
		 * Check if product has selected products or categories.
		 *
		 * @param int $product_id Product ID.
		 */
		private function product_has_addon_configuration( int $product_id ): bool {
			$settings = $this->get_product_settings( $product_id );
			return ! empty( $settings['product_ids'] ) || ! empty( $settings['category_ids'] );
		}

		/**
		 * Return addon product IDs allowed by the current settings.
		 *
		 * @param int    $product_id Base product ID.
		 * @param string $search     Search keyword.
		 * @param int    $limit      Max results, -1 for all.
		 */
		private function get_allowed_addon_product_ids( int $product_id, string $search = '', int $limit = 80 ): array {
			$settings = $this->get_product_settings( $product_id );
			$search   = trim( $search );
			$ids      = array();

			foreach ( $settings['product_ids'] as $explicit_id ) {
				if ( $explicit_id === $product_id ) {
					continue;
				}

				$addon = wc_get_product( $explicit_id );
				if ( ! $this->is_cartable_addon_product( $addon ) || ! $this->product_matches_search( $addon, $search ) ) {
					continue;
				}

				$ids[] = $explicit_id;
			}

			if ( ! empty( $settings['category_ids'] ) ) {
				$query_args = array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => -1 === $limit ? -1 : max( 20, $limit ),
					'fields'         => 'ids',
					'post__not_in'   => array( $product_id ),
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $settings['category_ids'],
						),
					),
				);

				if ( '' !== $search ) {
					$query_args['s'] = $search;
				}

				$query = new WP_Query( $query_args );
				foreach ( $query->posts as $category_product_id ) {
					if ( $category_product_id === $product_id ) {
						continue;
					}

					$addon = wc_get_product( $category_product_id );
					if ( ! $this->is_cartable_addon_product( $addon ) || ! $this->product_matches_search( $addon, $search ) ) {
						continue;
					}

					$ids[] = $category_product_id;
				}
			}

			$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

			if ( $limit > 0 ) {
				$ids = array_slice( $ids, 0, $limit );
			}

			return $ids;
		}

		/**
		 * Products added by this plugin must be directly cartable without variation choices.
		 *
		 * @param mixed $product Product candidate.
		 */
		private function is_cartable_addon_product( $product ): bool {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}

			if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
				return false;
			}

			return $product->is_purchasable() && $product->is_in_stock();
		}

		/**
		 * Search in product name and SKU.
		 *
		 * @param WC_Product $product Product.
		 * @param string     $search  Keyword.
		 */
		private function product_matches_search( WC_Product $product, string $search ): bool {
			if ( '' === $search ) {
				return true;
			}

			$haystack = strtolower( $product->get_name() . ' ' . $product->get_sku() );
			return false !== strpos( $haystack, strtolower( $search ) );
		}

		/**
		 * Calculate the final addon price according to product settings.
		 *
		 * @param WC_Product $product  Addon product.
		 * @param array      $settings Base product addon settings.
		 */
		private function calculate_adjusted_price( WC_Product $product, array $settings ): float {
			$price  = max( 0, (float) $product->get_price() );
			$amount = max( 0, (float) $settings['price_amount'] );

			switch ( $settings['price_type'] ) {
				case 'fixed_increase':
					$price += $amount;
					break;

				case 'fixed_discount':
					$price = max( 0, $price - $amount );
					break;

				case 'percent_increase':
					$price += ( $price * $amount / 100 );
					break;

				case 'percent_discount':
					$price = max( 0, $price - ( $price * $amount / 100 ) );
					break;
			}

			return (float) wc_format_decimal( $price, wc_get_price_decimals() );
		}

		/**
		 * Human-readable price policy label.
		 *
		 * @param array $settings Product settings.
		 */
		private function get_price_adjustment_label( array $settings ): string {
			$types  = $this->get_price_types();
			$type   = $settings['price_type'];
			$amount = (float) $settings['price_amount'];

			if ( 'original' === $type || $amount <= 0 ) {
				return $types['original'];
			}

			if ( in_array( $type, array( 'percent_increase', 'percent_discount' ), true ) ) {
				return $types[ $type ] . ' (' . wc_format_decimal( $amount ) . '%)';
			}

			return $types[ $type ] . ' (' . wc_price( $amount ) . ')';
		}

		/**
		 * Product thumbnail URL with WooCommerce placeholder fallback.
		 *
		 * @param WC_Product $product Product.
		 */
		private function get_product_image_url( WC_Product $product ): string {
			$image_id = $product->get_image_id();
			$url      = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

			if ( ! $url && function_exists( 'wc_placeholder_img_src' ) ) {
				$url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
			}

			return (string) $url;
		}

		/**
		 * Normalize any array-like input into unique positive integers.
		 *
		 * @param mixed $value Raw input.
		 */
		private function normalize_int_array( $value ): array {
			if ( ! is_array( $value ) ) {
				$value = empty( $value ) ? array() : array( $value );
			}

			return array_values(
				array_unique(
					array_filter(
						array_map(
							static function ( $item ): int {
								return absint( $item );
							},
							$value
						)
					)
				)
			);
		}

		/**
		 * Remove already-added cart items if a grouped add operation fails midway.
		 *
		 * @param array $cart_keys Cart item keys.
		 */
		private function rollback_cart_items( array $cart_keys ): void {
			foreach ( $cart_keys as $cart_key ) {
				WC()->cart->remove_cart_item( $cart_key );
			}
		}

		/**
		 * Extract WooCommerce cart errors into a single AJAX-safe message.
		 */
		private function get_cart_error_message(): string {
			$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
			if ( empty( $notices ) ) {
				return 'تعذر إضافة المنتجات إلى السلة.';
			}

			$messages = array();
			foreach ( $notices as $notice ) {
				if ( isset( $notice['notice'] ) ) {
					$messages[] = wp_strip_all_tags( $notice['notice'] );
				}
			}

			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}

			return $messages ? implode( ' ', $messages ) : 'تعذر إضافة المنتجات إلى السلة.';
		}

		/**
		 * Admin JavaScript.
		 */
		private function get_admin_js(): string {
			return <<<'JS'
(function ($) {
	'use strict';

	function selectMethod() {
		if ($.fn.selectWoo) {
			return 'selectWoo';
		}
		if ($.fn.select2) {
			return 'select2';
		}
		return null;
	}

	function initThreeabarSelect($field, action, inputLength) {
		var method = selectMethod();

		if (!method || !$field.length) {
			return;
		}

		$field[method]({
			width: '100%',
			dir: 'rtl',
			allowClear: true,
			minimumInputLength: inputLength || 0,
			placeholder: $field.data('placeholder') || '',
			ajax: {
				url: ThreeabarWCAddonsAdmin.ajaxUrl,
				dataType: 'json',
				delay: 250,
				data: function (params) {
					return {
						action: action,
						nonce: ThreeabarWCAddonsAdmin.nonce,
						term: params.term || ''
					};
				},
				processResults: function (data) {
					return data;
				},
				cache: true
			}
		});
	}

	function toggleAmountField() {
		var type = $('.threeabar-price-type').val();
		$('.threeabar-price-amount-wrap').toggleClass('is-muted', type === 'original');
	}

	$(function () {
		initThreeabarSelect($('.threeabar-product-search'), 'threeabar_search_addon_products', 2);
		initThreeabarSelect($('.threeabar-category-search'), 'threeabar_search_addon_categories', 0);
		toggleAmountField();
		$(document).on('change', '.threeabar-price-type', toggleAmountField);
	});
})(jQuery);
JS;
		}

		/**
		 * Frontend JavaScript.
		 */
		private function get_frontend_js(): string {
			return <<<'JS'
(function ($) {
	'use strict';

	var state = {
		productId: 0,
		maxChoices: 1,
		selectionType: 'radio',
		selected: {},
		request: null,
		timer: null
	};

	function modal() {
		return $('#threeabar-addon-modal');
	}

	function openModal(productId) {
		state.productId = productId;
		state.selected = {};
		$('.threeabar-addon-search').val('');
		$('.threeabar-addon-products').empty();
		$('.threeabar-addon-status').text(ThreeabarWCAddonsFrontend.i18n.loading);
		updateCount();
		modal().attr('aria-hidden', 'false').addClass('is-open');
		$('body').addClass('threeabar-modal-open');
		loadProducts('');
		setTimeout(function () {
			$('.threeabar-addon-search').trigger('focus');
		}, 120);
	}

	function closeModal() {
		modal().attr('aria-hidden', 'true').removeClass('is-open');
		$('body').removeClass('threeabar-modal-open');
	}

	function loadProducts(search) {
		if (state.request) {
			state.request.abort();
		}

		$('.threeabar-addon-status').text(ThreeabarWCAddonsFrontend.i18n.loading);
		$('.threeabar-addon-products').addClass('is-loading');

		state.request = $.ajax({
			url: ThreeabarWCAddonsFrontend.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'threeabar_get_addon_products',
				nonce: ThreeabarWCAddonsFrontend.nonce,
				product_id: state.productId,
				search: search || ''
			}
		}).done(function (response) {
			if (!response || !response.success) {
				showError(response && response.data && response.data.message ? response.data.message : ThreeabarWCAddonsFrontend.i18n.error);
				return;
			}

			state.maxChoices = parseInt(response.data.maxChoices, 10) || 1;
			state.selectionType = response.data.selectionType || 'radio';
			renderProducts(response.data.products || []);
			updateCount();
		}).fail(function (xhr, textStatus) {
			if (textStatus !== 'abort') {
				showError(ThreeabarWCAddonsFrontend.i18n.error);
			}
		}).always(function () {
			$('.threeabar-addon-products').removeClass('is-loading');
			state.request = null;
		});
	}

	function renderProducts(products) {
		var $wrap = $('.threeabar-addon-products');
		$wrap.empty();

		if (!products.length) {
			$('.threeabar-addon-status').text(ThreeabarWCAddonsFrontend.i18n.empty);
			return;
		}

		$('.threeabar-addon-status').text('');

		products.forEach(function (product) {
			var checked = state.selected[product.id] ? 'checked' : '';
			var original = product.priceChanged ? '<del>' + product.originalHtml + '</del>' : '';
			var badge = product.priceChanged ? '<span class="threeabar-addon-badge">' + product.adjustmentText + '</span>' : '';

			$wrap.append(
				'<label class="threeabar-addon-card" data-product-id="' + product.id + '">' +
					'<input type="' + state.selectionType + '" name="threeabar-addon-choice" value="' + product.id + '" ' + checked + ' />' +
					'<span class="threeabar-addon-check" aria-hidden="true"></span>' +
					'<span class="threeabar-addon-image"><img src="' + escapeAttr(product.image) + '" alt="" loading="lazy" /></span>' +
					'<span class="threeabar-addon-info">' +
						'<strong>' + escapeHtml(product.name) + '</strong>' +
						'<span class="threeabar-addon-price">' + original + '<b>' + product.priceHtml + '</b></span>' +
						badge +
					'</span>' +
				'</label>'
			);
		});
	}

	function updateCount() {
		var count = Object.keys(state.selected).length;
		$('.threeabar-addon-count').text('تم اختيار ' + count + ' من ' + state.maxChoices);
	}

	function showError(message) {
		$('.threeabar-addon-status').html('<span class="threeabar-addon-error">' + escapeHtml(message) + '</span>');
	}

	function debounceSearch() {
		clearTimeout(state.timer);
		state.timer = setTimeout(function () {
			loadProducts($('.threeabar-addon-search').val());
		}, 260);
	}

	function addToCart() {
		var $button = $('.threeabar-addon-submit');
		var ids = Object.keys(state.selected);

		$button.prop('disabled', true).text(ThreeabarWCAddonsFrontend.i18n.adding);
		$('.threeabar-addon-status').text('');

		$.ajax({
			url: ThreeabarWCAddonsFrontend.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'threeabar_add_product_bundle',
				nonce: ThreeabarWCAddonsFrontend.nonce,
				product_id: state.productId,
				addons: ids
			}
		}).done(function (response) {
			if (response && response.success) {
				window.location.href = response.data.cartUrl || ThreeabarWCAddonsFrontend.cartUrl;
				return;
			}

			showError(response && response.data && response.data.message ? response.data.message : ThreeabarWCAddonsFrontend.i18n.error);
		}).fail(function () {
			showError(ThreeabarWCAddonsFrontend.i18n.error);
		}).always(function () {
			$button.prop('disabled', false).text(ThreeabarWCAddonsFrontend.i18n.addToCart);
		});
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[char];
		});
	}

	function escapeAttr(value) {
		return escapeHtml(value).replace(/`/g, '&#096;');
	}

	$(document).on('click', '.threeabar-open-addons', function (event) {
		event.preventDefault();
		openModal(parseInt($(this).data('product-id'), 10));
	});

	$(document).on('click', '[data-threeabar-close]', closeModal);

	$(document).on('keydown', function (event) {
		if (event.key === 'Escape' && modal().hasClass('is-open')) {
			closeModal();
		}
	});

	$(document).on('input', '.threeabar-addon-search', debounceSearch);

	$(document).on('change', '.threeabar-addon-card input', function () {
		var id = String($(this).val());

		if (state.selectionType === 'radio') {
			state.selected = {};
			if (this.checked) {
				state.selected[id] = true;
			}
		} else if (this.checked) {
			if (Object.keys(state.selected).length >= state.maxChoices) {
				this.checked = false;
				showError(ThreeabarWCAddonsFrontend.i18n.limit);
				return;
			}
			state.selected[id] = true;
		} else {
			delete state.selected[id];
		}

		$('.threeabar-addon-status').text('');
		updateCount();
	});

	$(document).on('click', '.threeabar-addon-submit', addToCart);
})(jQuery);
JS;
		}

		/**
		 * Admin CSS.
		 */
		private function get_admin_css(): string {
			return <<<'CSS'
.threeabar-admin-panel {
	position: relative;
	overflow: hidden;
	margin: -6px -12px -12px;
	padding: 22px;
	color: #172033;
	background:
		radial-gradient(circle at 8% 10%, rgba(255, 91, 126, .22), transparent 30%),
		radial-gradient(circle at 92% 8%, rgba(32, 217, 210, .24), transparent 28%),
		linear-gradient(135deg, #f8fbff 0%, #eef4ff 48%, #fff7fb 100%);
	border-radius: 16px;
}
.threeabar-admin-hero {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 24px;
	padding: 24px;
	margin-bottom: 18px;
	color: #fff;
	background: linear-gradient(135deg, #111827 0%, #3b0764 46%, #0f766e 100%);
	border: 1px solid rgba(255, 255, 255, .2);
	border-radius: 24px;
	box-shadow: 0 24px 60px rgba(59, 7, 100, .22);
}
.threeabar-admin-hero h3 {
	margin: 5px 0 8px;
	color: #fff;
	font-size: 24px;
	line-height: 1.4;
}
.threeabar-admin-hero p {
	max-width: 760px;
	margin: 0;
	color: rgba(255, 255, 255, .82);
	font-size: 14px;
}
.threeabar-admin-kicker {
	display: inline-flex;
	padding: 6px 12px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: .08em;
	text-transform: uppercase;
	color: #fff;
	background: rgba(255, 255, 255, .14);
	border: 1px solid rgba(255, 255, 255, .2);
	border-radius: 999px;
	backdrop-filter: blur(12px);
}
.threeabar-admin-orb {
	width: 104px;
	height: 104px;
	flex: 0 0 104px;
	border-radius: 999px;
	background:
		radial-gradient(circle at 32% 28%, #fff, rgba(255,255,255,.25) 28%, transparent 29%),
		conic-gradient(from 120deg, #ff4f8b, #22d3ee, #a7f3d0, #facc15, #ff4f8b);
	box-shadow: 0 0 45px rgba(34, 211, 238, .55);
}
.threeabar-admin-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16px;
}
.threeabar-admin-field {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 18px;
	background: rgba(255, 255, 255, .82);
	border: 1px solid rgba(99, 102, 241, .12);
	border-radius: 18px;
	box-shadow: 0 14px 40px rgba(15, 23, 42, .08);
	backdrop-filter: blur(14px);
}
.threeabar-admin-field-wide {
	grid-column: 1 / -1;
}
.threeabar-admin-field > span {
	font-size: 14px;
	font-weight: 800;
	color: #172033;
}
.threeabar-admin-field small {
	color: #64748b;
}
.threeabar-admin-field input,
.threeabar-admin-field select,
.threeabar-admin-field .select2-container,
.threeabar-admin-field .selectWoo-container {
	max-width: 100%;
}
.threeabar-admin-field input[type="number"],
.threeabar-admin-field select {
	min-height: 42px;
	border-color: #dbeafe;
	border-radius: 12px;
	box-shadow: none;
}
.threeabar-price-amount-wrap {
	transition: opacity .2s ease, filter .2s ease;
}
.threeabar-price-amount-wrap.is-muted {
	opacity: .62;
	filter: grayscale(.25);
}
.threeabar-admin-panel .select2-container--default .select2-selection--multiple,
.threeabar-admin-panel .selectWoo-container--default .selectWoo-selection--multiple {
	min-height: 46px;
	border: 1px solid #dbeafe;
	border-radius: 14px;
}
@media (max-width: 782px) {
	.threeabar-admin-grid {
		grid-template-columns: 1fr;
	}
	.threeabar-admin-hero {
		align-items: flex-start;
		flex-direction: column;
	}
	.threeabar-admin-orb {
		width: 72px;
		height: 72px;
		flex-basis: 72px;
	}
}
CSS;
		}

		/**
		 * Frontend CSS.
		 */
		private function get_frontend_css(): string {
			return <<<'CSS'
.threeabar-open-addons.single_add_to_cart_button {
	position: relative;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	min-height: 54px;
	padding: 0 28px;
	overflow: hidden;
	color: #fff !important;
	background: linear-gradient(135deg, #111827, #6d28d9 48%, #0f766e) !important;
	border: 0 !important;
	border-radius: 999px !important;
	box-shadow: 0 18px 42px rgba(109, 40, 217, .35), inset 0 1px 0 rgba(255,255,255,.25);
	font-weight: 800;
	letter-spacing: .01em;
	transition: transform .2s ease, box-shadow .2s ease;
}
.threeabar-open-addons.single_add_to_cart_button:hover {
	transform: translateY(-2px);
	box-shadow: 0 24px 55px rgba(15, 118, 110, .35), inset 0 1px 0 rgba(255,255,255,.3);
}
.threeabar-open-addons i {
	display: grid;
	place-items: center;
	width: 30px;
	height: 30px;
	font-style: normal;
	color: #111827;
	background: #fff;
	border-radius: 50%;
}
body.threeabar-modal-open {
	overflow: hidden;
}
.threeabar-addon-modal {
	position: fixed;
	inset: 0;
	z-index: 999999;
	display: none;
	align-items: center;
	justify-content: center;
	padding: 22px;
	font-family: inherit;
}
.threeabar-addon-modal.is-open {
	display: flex;
}
.threeabar-addon-backdrop {
	position: absolute;
	inset: 0;
	background:
		radial-gradient(circle at 20% 10%, rgba(34, 211, 238, .28), transparent 25%),
		radial-gradient(circle at 80% 80%, rgba(244, 63, 94, .26), transparent 28%),
		rgba(2, 6, 23, .68);
	backdrop-filter: blur(12px);
}
.threeabar-addon-dialog {
	position: relative;
	width: min(980px, 100%);
	max-height: min(88vh, 900px);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	color: #0f172a;
	background:
		linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,252,.94)),
		radial-gradient(circle at 14% 0%, rgba(99,102,241,.14), transparent 32%);
	border: 1px solid rgba(255, 255, 255, .55);
	border-radius: 30px;
	box-shadow: 0 32px 110px rgba(2, 6, 23, .45);
	animation: threeabarModalIn .22s ease-out;
}
@keyframes threeabarModalIn {
	from { opacity: 0; transform: translateY(18px) scale(.98); }
	to { opacity: 1; transform: translateY(0) scale(1); }
}
.threeabar-addon-close {
	position: absolute;
	top: 18px;
	left: 18px;
	z-index: 2;
	display: grid;
	place-items: center;
	width: 42px;
	height: 42px;
	padding: 0;
	color: #0f172a;
	background: rgba(255, 255, 255, .78);
	border: 1px solid rgba(148, 163, 184, .28);
	border-radius: 50%;
	box-shadow: 0 12px 28px rgba(15, 23, 42, .12);
	font-size: 26px;
	line-height: 1;
	cursor: pointer;
}
.threeabar-addon-header {
	padding: 30px 32px 18px;
	color: #fff;
	background:
		radial-gradient(circle at 15% 0%, rgba(34, 211, 238, .42), transparent 32%),
		linear-gradient(135deg, #111827 0%, #4c1d95 48%, #0f766e 100%);
}
.threeabar-addon-eyebrow {
	display: inline-flex;
	padding: 6px 12px;
	margin-bottom: 10px;
	color: #cffafe;
	background: rgba(255, 255, 255, .12);
	border: 1px solid rgba(255, 255, 255, .18);
	border-radius: 999px;
	font-size: 12px;
	font-weight: 800;
	letter-spacing: .08em;
	text-transform: uppercase;
}
.threeabar-addon-header h2 {
	margin: 0 0 8px;
	color: #fff;
	font-size: clamp(25px, 4vw, 38px);
	line-height: 1.2;
}
.threeabar-addon-header p {
	max-width: 650px;
	margin: 0;
	color: rgba(255,255,255,.78);
}
.threeabar-addon-search-wrap {
	position: relative;
	margin: 18px 32px 12px;
}
.threeabar-addon-search {
	width: 100%;
	min-height: 56px;
	padding: 0 52px 0 18px;
	color: #0f172a;
	background: #fff;
	border: 1px solid #dbeafe;
	border-radius: 18px;
	box-shadow: 0 16px 38px rgba(15, 23, 42, .08);
	font-size: 16px;
	outline: none;
}
.threeabar-addon-search:focus {
	border-color: #7c3aed;
	box-shadow: 0 0 0 4px rgba(124, 58, 237, .12), 0 16px 38px rgba(15, 23, 42, .08);
}
.threeabar-addon-search-wrap > span {
	position: absolute;
	top: 50%;
	right: 18px;
	transform: translateY(-50%);
	color: #7c3aed;
	font-size: 26px;
}
.threeabar-addon-status {
	min-height: 24px;
	padding: 0 32px 8px;
	color: #64748b;
	font-size: 14px;
}
.threeabar-addon-error {
	display: inline-flex;
	padding: 8px 12px;
	color: #be123c;
	background: #fff1f2;
	border: 1px solid #fecdd3;
	border-radius: 999px;
}
.threeabar-addon-products {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 14px;
	min-height: 180px;
	padding: 0 32px 22px;
	overflow: auto;
}
.threeabar-addon-products.is-loading {
	opacity: .65;
}
.threeabar-addon-card {
	position: relative;
	display: grid;
	grid-template-columns: auto 92px 1fr;
	gap: 14px;
	align-items: center;
	padding: 14px;
	margin: 0;
	cursor: pointer;
	background: rgba(255,255,255,.82);
	border: 1px solid rgba(148, 163, 184, .24);
	border-radius: 22px;
	box-shadow: 0 16px 36px rgba(15, 23, 42, .08);
	transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
}
.threeabar-addon-card:hover,
.threeabar-addon-card:has(input:checked) {
	transform: translateY(-2px);
	border-color: rgba(124, 58, 237, .58);
	box-shadow: 0 22px 50px rgba(76, 29, 149, .16);
}
.threeabar-addon-card input {
	position: absolute;
	opacity: 0;
	pointer-events: none;
}
.threeabar-addon-check {
	width: 24px;
	height: 24px;
	border: 2px solid #cbd5e1;
	border-radius: 50%;
	background: #fff;
	box-shadow: inset 0 0 0 5px #fff;
}
.threeabar-addon-card input[type="checkbox"] + .threeabar-addon-check {
	border-radius: 8px;
}
.threeabar-addon-card input:checked + .threeabar-addon-check {
	background: linear-gradient(135deg, #7c3aed, #06b6d4);
	border-color: #7c3aed;
}
.threeabar-addon-image {
	position: relative;
	overflow: hidden;
	width: 92px;
	height: 92px;
	background: #f8fafc;
	border-radius: 18px;
}
.threeabar-addon-image img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}
.threeabar-addon-info {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
}
.threeabar-addon-info strong {
	color: #0f172a;
	font-size: 15px;
	line-height: 1.5;
}
.threeabar-addon-price {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	color: #64748b;
}
.threeabar-addon-price b {
	color: #0f766e;
	font-size: 16px;
}
.threeabar-addon-price del {
	opacity: .68;
}
.threeabar-addon-badge {
	align-self: flex-start;
	padding: 5px 9px;
	color: #581c87;
	background: #f3e8ff;
	border-radius: 999px;
	font-size: 12px;
	font-weight: 700;
}
.threeabar-addon-footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 18px 32px;
	background: rgba(248, 250, 252, .92);
	border-top: 1px solid rgba(148, 163, 184, .2);
}
.threeabar-addon-count {
	color: #475569;
	font-weight: 700;
}
.threeabar-addon-submit {
	min-width: 220px;
	min-height: 54px;
	padding: 0 26px;
	color: #fff;
	background: linear-gradient(135deg, #ec4899, #7c3aed 45%, #06b6d4);
	border: 0;
	border-radius: 999px;
	box-shadow: 0 18px 42px rgba(124, 58, 237, .28);
	font-weight: 900;
	cursor: pointer;
}
.threeabar-addon-submit:disabled {
	cursor: wait;
	opacity: .72;
}
@media (max-width: 780px) {
	.threeabar-addon-modal {
		padding: 10px;
		align-items: flex-end;
	}
	.threeabar-addon-dialog {
		max-height: 94vh;
		border-radius: 24px 24px 0 0;
	}
	.threeabar-addon-products {
		grid-template-columns: 1fr;
		padding-inline: 18px;
	}
	.threeabar-addon-header,
	.threeabar-addon-footer {
		padding-inline: 18px;
	}
	.threeabar-addon-search-wrap {
		margin-inline: 18px;
	}
	.threeabar-addon-footer {
		align-items: stretch;
		flex-direction: column;
	}
	.threeabar-addon-submit {
		width: 100%;
	}
}
@media (max-width: 460px) {
	.threeabar-addon-card {
		grid-template-columns: auto 72px 1fr;
	}
	.threeabar-addon-image {
		width: 72px;
		height: 72px;
	}
}
CSS;
		}
	}

	new Threeabar_WooCommerce_Product_Addons();
}

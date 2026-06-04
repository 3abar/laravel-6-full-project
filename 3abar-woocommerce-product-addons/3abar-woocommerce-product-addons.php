<?php
/**
 * Plugin Name:       3abar WooCommerce Product Addons
 * Plugin URI:        https://3abar.com
 * Description:       إضافات منتجات مرفقة مع نافذة اختيار أنيقة، بحث حي، وتعديل أسعار — Ajax بالكامل.
 * Version:           1.0.0
 * Author:            Shawky El Moazamy
 * Author URI:        https://3abar.com
 * Text Domain:       3abar-wc-product-addons
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   9.0
 *
 * @package Three_Abar_WC_Product_Addons
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin bootstrap.
 */
final class Three_Abar_WC_Product_Addons {

	const VERSION = '1.0.0';

	const META_PRODUCTS     = '_3abar_addon_products';
	const META_CATEGORIES   = '_3abar_addon_categories';
	const META_MAX_SELECT   = '_3abar_addon_max_select';
	const META_PRICE_TYPE   = '_3abar_addon_price_type';
	const META_PRICE_AMOUNT = '_3abar_addon_price_amount';

	const NONCE_ACTION = '3abar_wc_addons_nonce';
	const AJAX_PREFIX  = '3abar_wc_addons_';

	/** @var self|null */
	private static $instance = null;

	/**
	 * Singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register hooks.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize after WooCommerce is available.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Admin.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_product_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX (admin + frontend).
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'search_products', array( $this, 'ajax_search_products' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'get_addons', array( $this, 'ajax_get_addons' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_PREFIX . 'get_addons', array( $this, 'ajax_get_addons' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_PREFIX . 'add_to_cart', array( $this, 'ajax_add_to_cart' ) );

		// Frontend.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'maybe_replace_add_to_cart' ), 1 );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'hide_default_form_start' ), 1 );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'hide_default_form_end' ), 99 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_custom_add_to_cart' ), 30 );
		add_action( 'wp_footer', array( $this, 'render_modal_markup' ) );

		// Cart price adjustment for addon line items.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_addon_prices' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_meta' ), 10, 2 );
	}

	/**
	 * Admin notice when WooCommerce is inactive.
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( '3abar WooCommerce Product Addons يتطلب تفعيل WooCommerce.', '3abar-wc-product-addons' );
		echo '</p></div>';
	}

	/* -------------------------------------------------------------------------
	 * Meta box & product settings
	 * ---------------------------------------------------------------------- */

	/**
	 * Register product meta box.
	 */
	public function register_meta_box() {
		add_meta_box(
			'3abar_wc_product_addons',
			__( 'إضافات 3abar - المنتجات المرفقة', '3abar-wc-product-addons' ),
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * Render admin meta box HTML.
	 *
	 * @param WP_Post $post Current product post.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, '3abar_addons_meta_nonce' );

		$products    = (array) get_post_meta( $post->ID, self::META_PRODUCTS, true );
		$categories  = (array) get_post_meta( $post->ID, self::META_CATEGORIES, true );
		$max_select  = (int) get_post_meta( $post->ID, self::META_MAX_SELECT, true );
		$price_type  = get_post_meta( $post->ID, self::META_PRICE_TYPE, true );
		$price_amount = get_post_meta( $post->ID, self::META_PRICE_AMOUNT, true );

		if ( $max_select < 1 ) {
			$max_select = 1;
		}
		if ( ! $price_type ) {
			$price_type = 'original';
		}

		$price_types = $this->get_price_type_options();
		?>
		<div class="abar-admin-wrap" dir="rtl">
			<div class="abar-admin-hero">
				<div class="abar-admin-hero__glow"></div>
				<h3 class="abar-admin-hero__title"><?php esc_html_e( 'إعدادات الإضافات', '3abar-wc-product-addons' ); ?></h3>
				<p class="abar-admin-hero__desc"><?php esc_html_e( 'اختر المنتجات أو التصنيفات التي تظهر للعميل في نافذة الاختيار.', '3abar-wc-product-addons' ); ?></p>
			</div>

			<div class="abar-admin-grid">
				<div class="abar-admin-field abar-admin-field--full">
					<label for="3abar_addon_products"><?php esc_html_e( 'منتجات محددة', '3abar-wc-product-addons' ); ?></label>
					<select name="3abar_addon_products[]" id="3abar_addon_products" class="abar-select2" multiple="multiple" data-placeholder="<?php esc_attr_e( 'ابحث واختر المنتجات...', '3abar-wc-product-addons' ); ?>">
						<?php
						foreach ( array_filter( array_map( 'absint', $products ) ) as $pid ) {
							$product = wc_get_product( $pid );
							if ( ! $product ) {
								continue;
							}
							printf(
								'<option value="%d" selected="selected">%s</option>',
								$pid,
								esc_html( $product->get_formatted_name() )
							);
						}
						?>
					</select>
				</div>

				<div class="abar-admin-field abar-admin-field--full">
					<label for="3abar_addon_categories"><?php esc_html_e( 'تصنيفات', '3abar-wc-product-addons' ); ?></label>
					<select name="3abar_addon_categories[]" id="3abar_addon_categories" class="abar-select2-cat" multiple="multiple" data-placeholder="<?php esc_attr_e( 'اختر التصنيفات...', '3abar-wc-product-addons' ); ?>">
						<?php
						$terms = get_terms(
							array(
								'taxonomy'   => 'product_cat',
								'hide_empty' => false,
							)
						);
						if ( ! is_wp_error( $terms ) ) {
							foreach ( $terms as $term ) {
								$selected = in_array( (int) $term->term_id, array_map( 'absint', $categories ), true ) ? 'selected="selected"' : '';
								printf(
									'<option value="%d" %s>%s</option>',
									(int) $term->term_id,
									$selected,
									esc_html( $term->name )
								);
							}
						}
						?>
					</select>
				</div>

				<div class="abar-admin-field">
					<label for="3abar_addon_max_select"><?php esc_html_e( 'الحد الأقصى لعدد المنتجات القابلة للاختيار', '3abar-wc-product-addons' ); ?></label>
					<input type="number" min="1" step="1" id="3abar_addon_max_select" name="3abar_addon_max_select" value="<?php echo esc_attr( $max_select ); ?>" />
				</div>

				<div class="abar-admin-field">
					<label for="3abar_addon_price_type"><?php esc_html_e( 'سياسة السعر', '3abar-wc-product-addons' ); ?></label>
					<select name="3abar_addon_price_type" id="3abar_addon_price_type" class="abar-price-type-select">
						<?php foreach ( $price_types as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $price_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="abar-admin-field abar-admin-field--amount <?php echo 'original' === $price_type ? 'is-hidden' : ''; ?>" id="3abar_price_amount_wrap">
					<label for="3abar_addon_price_amount"><?php esc_html_e( 'قيمة التعديل (مبلغ أو نسبة)', '3abar-wc-product-addons' ); ?></label>
					<input type="number" min="0" step="0.01" id="3abar_addon_price_amount" name="3abar_addon_price_amount" value="<?php echo esc_attr( $price_amount ); ?>" />
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Price adjustment type labels.
	 *
	 * @return array<string, string>
	 */
	private function get_price_type_options() {
		return array(
			'original'         => __( 'استخدم سعر المنتج الأصلي', '3abar-wc-product-addons' ),
			'fixed_increase'   => __( 'زيادة ثابتة', '3abar-wc-product-addons' ),
			'fixed_decrease'   => __( 'خصم ثابت', '3abar-wc-product-addons' ),
			'percent_increase' => __( 'زيادة بنسبة %', '3abar-wc-product-addons' ),
			'percent_decrease' => __( 'خصم بنسبة %', '3abar-wc-product-addons' ),
		);
	}

	/**
	 * Save product meta on product save.
	 *
	 * @param int     $post_id Product ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_product_meta( $post_id, $post ) {
		if ( ! isset( $_POST['3abar_addons_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['3abar_addons_meta_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$products = isset( $_POST['3abar_addon_products'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['3abar_addon_products'] ) ) : array();
		$categories = isset( $_POST['3abar_addon_categories'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['3abar_addon_categories'] ) ) : array();
		$max_select = isset( $_POST['3abar_addon_max_select'] ) ? max( 1, (int) $_POST['3abar_addon_max_select'] ) : 1;
		$price_type = isset( $_POST['3abar_addon_price_type'] ) ? sanitize_key( wp_unslash( $_POST['3abar_addon_price_type'] ) ) : 'original';
		$amount     = isset( $_POST['3abar_addon_price_amount'] ) ? (float) $_POST['3abar_addon_price_amount'] : 0;

		if ( ! array_key_exists( $price_type, $this->get_price_type_options() ) ) {
			$price_type = 'original';
		}

		update_post_meta( $post_id, self::META_PRODUCTS, array_values( array_filter( $products ) ) );
		update_post_meta( $post_id, self::META_CATEGORIES, array_values( array_filter( $categories ) ) );
		update_post_meta( $post_id, self::META_MAX_SELECT, $max_select );
		update_post_meta( $post_id, self::META_PRICE_TYPE, $price_type );
		update_post_meta( $post_id, self::META_PRICE_AMOUNT, $amount );
	}

	/**
	 * Whether a product has addon configuration.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function product_has_addons( $product_id ) {
		$products   = (array) get_post_meta( $product_id, self::META_PRODUCTS, true );
		$categories = (array) get_post_meta( $product_id, self::META_CATEGORIES, true );
		return ! empty( array_filter( $products ) ) || ! empty( array_filter( $categories ) );
	}

	/**
	 * Resolve addon product IDs for a parent product.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return int[]
	 */
	public function get_addon_product_ids( $parent_id ) {
		$ids        = array();
		$products   = (array) get_post_meta( $parent_id, self::META_PRODUCTS, true );
		$categories = (array) get_post_meta( $parent_id, self::META_CATEGORIES, true );

		foreach ( array_filter( array_map( 'absint', $products ) ) as $pid ) {
			if ( $pid !== $parent_id && 'publish' === get_post_status( $pid ) ) {
				$ids[] = $pid;
			}
		}

		foreach ( array_filter( array_map( 'absint', $categories ) ) as $cat_id ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $cat_id,
						),
					),
				)
			);
			if ( ! empty( $query->posts ) ) {
				$ids = array_merge( $ids, $query->posts );
			}
		}

		$ids = array_unique( array_map( 'absint', $ids ) );
		$ids = array_diff( $ids, array( $parent_id ) );

		return array_values( $ids );
	}

	/**
	 * Calculate adjusted price for display/cart.
	 *
	 * @param float  $base_price   Original price.
	 * @param string $type         Adjustment type.
	 * @param float  $amount       Adjustment amount.
	 * @return float
	 */
	public function calculate_adjusted_price( $base_price, $type, $amount ) {
		$base_price = (float) $base_price;
		$amount     = (float) $amount;

		switch ( $type ) {
			case 'fixed_increase':
				return max( 0, $base_price + $amount );
			case 'fixed_decrease':
				return max( 0, $base_price - $amount );
			case 'percent_increase':
				return max( 0, $base_price * ( 1 + ( $amount / 100 ) ) );
			case 'percent_decrease':
				return max( 0, $base_price * ( 1 - ( $amount / 100 ) ) );
			default:
				return $base_price;
		}
	}

	/* -------------------------------------------------------------------------
	 * Admin assets
	 * ---------------------------------------------------------------------- */

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		wp_enqueue_style( 'select2', WC()->plugin_url() . '/assets/css/select2.css', array(), self::VERSION );
		wp_enqueue_script( 'select2', WC()->plugin_url() . '/assets/js/select2/select2.full.min.js', array( 'jquery' ), self::VERSION, true );

		wp_add_inline_style( 'select2', $this->get_admin_css() );
		wp_add_inline_script(
			'select2',
			$this->get_admin_js(),
			'after'
		);

		wp_localize_script(
			'select2',
			'abarAddonsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'action'  => self::AJAX_PREFIX . 'search_products',
				'i18n'    => array(
					'searching' => __( 'جاري البحث...', '3abar-wc-product-addons' ),
					'noResults' => __( 'لا توجد نتائج', '3abar-wc-product-addons' ),
				),
			)
		);
	}

	/**
	 * Admin panel CSS.
	 *
	 * @return string
	 */
	private function get_admin_css() {
		return '
		.abar-admin-wrap{font-family:"Segoe UI",Tahoma,sans-serif;margin:8px 0 4px}
		.abar-admin-hero{position:relative;padding:28px 32px;border-radius:16px;background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 45%,#4c1d95 100%);color:#fff;overflow:hidden;margin-bottom:24px;box-shadow:0 20px 50px rgba(76,29,149,.35)}
		.abar-admin-hero__glow{position:absolute;inset:-40% auto auto -20%;width:280px;height:280px;background:radial-gradient(circle,rgba(168,85,247,.55) 0%,transparent 70%);pointer-events:none}
		.abar-admin-hero__title{margin:0 0 8px;font-size:22px;font-weight:700;letter-spacing:-.02em;position:relative}
		.abar-admin-hero__desc{margin:0;opacity:.88;font-size:14px;position:relative}
		.abar-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}
		.abar-admin-field--full{grid-column:1/-1}
		.abar-admin-field label{display:block;margin-bottom:8px;font-weight:600;color:#1e293b;font-size:13px}
		.abar-admin-field input[type=number],.abar-admin-field select{width:100%;max-width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:12px;font-size:14px;transition:border-color .2s,box-shadow .2s;background:#fff}
		.abar-admin-field input:focus,.abar-admin-field select:focus{border-color:#7c3aed;outline:none;box-shadow:0 0 0 4px rgba(124,58,237,.15)}
		.abar-admin-field .select2-container{width:100%!important}
		.abar-admin-field .select2-container--default .select2-selection--multiple{min-height:48px;border:2px solid #e2e8f0;border-radius:12px;padding:6px 8px;background:linear-gradient(180deg,#fff 0%,#f8fafc 100%)}
		.abar-admin-field .select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#7c3aed;box-shadow:0 0 0 4px rgba(124,58,237,.12)}
		.abar-admin-field .select2-selection__choice{background:linear-gradient(135deg,#7c3aed,#a855f7)!important;border:none!important;color:#fff!important;border-radius:999px!important;padding:4px 10px!important;margin:4px!important}
		.abar-admin-field.is-hidden{display:none}
		@media(max-width:782px){.abar-admin-grid{grid-template-columns:1fr}}
		';
	}

	/**
	 * Admin panel JavaScript.
	 *
	 * @return string
	 */
	private function get_admin_js() {
		return "
		jQuery(function($){
			var \$products = $('#3abar_addon_products');
			if (\$products.length) {
				\$products.select2({
					ajax: {
						url: abarAddonsAdmin.ajaxUrl,
						dataType: 'json',
						delay: 250,
						data: function(params){ return { action: abarAddonsAdmin.action, nonce: abarAddonsAdmin.nonce, q: params.term || '' }; },
						processResults: function(data){ return data; },
						cache: true
					},
					minimumInputLength: 2,
					placeholder: \$products.data('placeholder'),
					allowClear: true,
					dir: 'rtl',
					language: { searching: function(){ return abarAddonsAdmin.i18n.searching; }, noResults: function(){ return abarAddonsAdmin.i18n.noResults; } }
				});
			}
			$('#3abar_addon_categories').select2({ placeholder: $('#3abar_addon_categories').data('placeholder'), allowClear: true, dir: 'rtl' });
			$('#3abar_addon_price_type').on('change', function(){
				var v = $(this).val();
				$('#3abar_price_amount_wrap').toggleClass('is-hidden', v === 'original');
			});
		});
		";
	}

	/**
	 * AJAX: search products for Select2 (admin).
	 */
	public function ajax_search_products() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$ids  = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 30,
				's'      => $term,
				'return' => 'ids',
			)
		);

		$results = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$results[] = array(
				'id'   => $id,
				'text' => $product->get_formatted_name(),
			);
		}

		wp_send_json( array( 'results' => $results ) );
	}

	/* -------------------------------------------------------------------------
	 * Frontend: replace add to cart & modal
	 * ---------------------------------------------------------------------- */

	/** @var bool */
	private $hide_default_form = false;

	/**
	 * Remove default add-to-cart when addons configured (simple products).
	 */
	public function maybe_replace_add_to_cart() {
		if ( ! is_product() ) {
			return;
		}
		global $product;
		if ( ! $product || ! $this->product_has_addons( $product->get_id() ) ) {
			return;
		}
		if ( ! $product->is_type( 'simple' ) ) {
			return;
		}

		$this->hide_default_form = true;
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}

	/**
	 * Hide default cart form via CSS class wrapper.
	 */
	public function hide_default_form_start() {
		if ( $this->hide_default_form ) {
			echo '<div class="abar-hide-native-cart" style="display:none!important" aria-hidden="true">';
		}
	}

	/**
	 * Close hidden wrapper.
	 */
	public function hide_default_form_end() {
		if ( $this->hide_default_form ) {
			echo '</div>';
		}
	}

	/**
	 * Output custom trigger button.
	 */
	public function render_custom_add_to_cart() {
		if ( ! is_product() ) {
			return;
		}
		global $product;
		if ( ! $product || ! $this->product_has_addons( $product->get_id() ) || ! $product->is_type( 'simple' ) ) {
			return;
		}

		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		$max = max( 1, (int) get_post_meta( $product->get_id(), self::META_MAX_SELECT, true ) );
		?>
		<div class="abar-atc-wrap" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-max="<?php echo esc_attr( $max ); ?>">
			<div class="abar-qty-row">
				<label for="abar_qty_<?php echo esc_attr( $product->get_id() ); ?>" class="abar-qty-label"><?php esc_html_e( 'الكمية', '3abar-wc-product-addons' ); ?></label>
				<?php
				woocommerce_quantity_input(
					array(
						'min_value'   => $product->get_min_purchase_quantity(),
						'max_value'   => $product->get_max_purchase_quantity(),
						'input_value' => isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : $product->get_min_purchase_quantity(),
						'input_id'    => 'abar_qty_' . $product->get_id(),
						'classes'     => array( 'input-text', 'qty', 'text', 'abar-qty-input' ),
					)
				);
				?>
			</div>
			<button type="button" class="abar-trigger-btn button alt" data-abar-open-modal>
				<span class="abar-trigger-btn__shine"></span>
				<span class="abar-trigger-btn__text"><?php esc_html_e( 'أضف إلى السلة', '3abar-wc-product-addons' ); ?></span>
				<span class="abar-trigger-btn__icon" aria-hidden="true">+</span>
			</button>
		</div>
		<?php
	}

	/**
	 * Modal HTML in footer (single instance, works with Elementor).
	 */
	public function render_modal_markup() {
		if ( ! is_product() ) {
			return;
		}
		?>
		<div id="abar-addon-modal" class="abar-modal" role="dialog" aria-modal="true" aria-labelledby="abar-modal-title" hidden>
			<div class="abar-modal__backdrop" data-abar-close-modal></div>
			<div class="abar-modal__dialog">
				<button type="button" class="abar-modal__close" data-abar-close-modal aria-label="<?php esc_attr_e( 'إغلاق', '3abar-wc-product-addons' ); ?>">&times;</button>
				<header class="abar-modal__header">
					<div class="abar-modal__badge">3abar</div>
					<h2 id="abar-modal-title" class="abar-modal__title"><?php esc_html_e( 'اختر المنتجات الإضافية', '3abar-wc-product-addons' ); ?></h2>
					<p class="abar-modal__subtitle"><?php esc_html_e( 'ابحث واختر ما يناسبك — ثم أضف الكل للسلة دفعة واحدة', '3abar-wc-product-addons' ); ?></p>
				</header>
				<div class="abar-modal__search-wrap">
					<input type="search" class="abar-modal__search" id="abar-addon-search" placeholder="<?php esc_attr_e( 'ابحث بالاسم...', '3abar-wc-product-addons' ); ?>" autocomplete="off" />
					<span class="abar-modal__search-icon" aria-hidden="true">⌕</span>
				</div>
				<div class="abar-modal__loader" id="abar-modal-loader">
					<div class="abar-spinner"></div>
					<span><?php esc_html_e( 'جاري التحميل...', '3abar-wc-product-addons' ); ?></span>
				</div>
				<div class="abar-modal__list" id="abar-addon-list" role="listbox"></div>
				<p class="abar-modal__empty" id="abar-addon-empty" hidden><?php esc_html_e( 'لا توجد منتجات مطابقة', '3abar-wc-product-addons' ); ?></p>
				<footer class="abar-modal__footer">
					<button type="button" class="abar-modal__submit button alt" id="abar-addon-submit" disabled>
						<?php esc_html_e( 'إضافة إلى السلة', '3abar-wc-product-addons' ); ?>
					</button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue frontend assets.
	 */
	public function enqueue_frontend_assets() {
		if ( ! is_product() ) {
			return;
		}

		global $product;
		if ( ! $product || ! $this->product_has_addons( $product->get_id() ) ) {
			return;
		}

		wp_register_style( '3abar-wc-addons', false, array(), self::VERSION );
		wp_enqueue_style( '3abar-wc-addons' );
		wp_add_inline_style( '3abar-wc-addons', $this->get_frontend_css() );

		wp_register_script( '3abar-wc-addons', false, array( 'jquery' ), self::VERSION, true );
		wp_enqueue_script( '3abar-wc-addons' );
		wp_add_inline_script( '3abar-wc-addons', $this->get_frontend_js() );

		wp_localize_script(
			'3abar-wc-addons',
			'abarAddonsFront',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
				'actions'  => array(
					'get'  => self::AJAX_PREFIX . 'get_addons',
					'cart' => self::AJAX_PREFIX . 'add_to_cart',
				),
				'i18n'     => array(
					'selectOne'   => __( 'اختر منتجاً واحداً على الأقل', '3abar-wc-product-addons' ),
					'maxReached'  => __( 'وصلت للحد الأقصى من الاختيارات', '3abar-wc-product-addons' ),
					'adding'       => __( 'جاري الإضافة...', '3abar-wc-product-addons' ),
					'submitLabel'  => __( 'إضافة إلى السلة', '3abar-wc-product-addons' ),
					'error'        => __( 'حدث خطأ، حاول مرة أخرى', '3abar-wc-product-addons' ),
					'selectLimit' => __( 'يمكنك اختيار حتى', '3abar-wc-product-addons' ),
				),
				'currency' => get_woocommerce_currency_symbol(),
			)
		);
	}

	/**
	 * Frontend CSS — high specificity, isolated from theme conflicts.
	 *
	 * @return string
	 */
	private function get_frontend_css() {
		return '
		body .abar-atc-wrap{margin:1.25em 0 1.5em;font-family:inherit}
		body .abar-qty-row{display:flex;align-items:center;gap:12px;margin-bottom:14px}
		body .abar-qty-label{font-weight:600;font-size:14px}
		body .abar-trigger-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;gap:10px;width:100%;max-width:420px;padding:16px 28px!important;border:none!important;border-radius:14px!important;background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#d946ef 100%)!important;color:#fff!important;font-size:17px!important;font-weight:700!important;cursor:pointer;overflow:hidden;box-shadow:0 12px 32px rgba(99,102,241,.45);transition:transform .2s,box-shadow .2s}
		body .abar-trigger-btn:hover{transform:translateY(-2px);box-shadow:0 16px 40px rgba(139,92,246,.5);color:#fff!important}
		body .abar-trigger-btn__shine{position:absolute;inset:0;background:linear-gradient(105deg,transparent 40%,rgba(255,255,255,.25) 50%,transparent 60%);transform:translateX(-100%);animation:abarShine 3s infinite}
		@keyframes abarShine{to{transform:translateX(100%)}}
		body .abar-trigger-btn__icon{width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:20px;line-height:1}
		body .abar-modal{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;padding:16px;font-family:inherit}
		body .abar-modal[hidden]{display:none!important}
		body .abar-modal__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.72);backdrop-filter:blur(8px)}
		body .abar-modal__dialog{position:relative;width:100%;max-width:560px;max-height:min(90vh,720px);display:flex;flex-direction:column;background:linear-gradient(165deg,#fff 0%,#f8fafc 100%);border-radius:24px;box-shadow:0 32px 64px rgba(15,23,42,.28);overflow:hidden;animation:abarModalIn .35s cubic-bezier(.34,1.56,.64,1)}
		@keyframes abarModalIn{from{opacity:0;transform:scale(.92) translateY(20px)}to{opacity:1;transform:scale(1) translateY(0)}}
		body .abar-modal__close{position:absolute;top:14px;left:14px;z-index:2;width:40px;height:40px;border:none;border-radius:50%;background:#f1f5f9;color:#334155;font-size:24px;line-height:1;cursor:pointer;transition:background .2s}
		body .abar-modal__close:hover{background:#e2e8f0}
		body .abar-modal__header{padding:28px 24px 16px;text-align:center;background:linear-gradient(180deg,rgba(99,102,241,.08) 0%,transparent 100%)}
		body .abar-modal__badge{display:inline-block;padding:4px 12px;border-radius:999px;background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;font-size:11px;font-weight:700;letter-spacing:.08em;margin-bottom:10px}
		body .abar-modal__title{margin:0 0 6px;font-size:22px;font-weight:800;color:#0f172a}
		body .abar-modal__subtitle{margin:0;font-size:13px;color:#64748b}
		body .abar-modal__search-wrap{position:relative;padding:0 20px 12px}
		body .abar-modal__search{width:100%;padding:14px 44px 14px 16px;border:2px solid #e2e8f0;border-radius:14px;font-size:15px;transition:border-color .2s,box-shadow .2s}
		body .abar-modal__search:focus{border-color:#8b5cf6;outline:none;box-shadow:0 0 0 4px rgba(139,92,246,.15)}
		body .abar-modal__search-icon{position:absolute;right:36px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:18px;pointer-events:none}
		body .abar-modal__list{flex:1;overflow-y:auto;padding:8px 16px 12px;display:grid;gap:10px;scrollbar-width:thin}
		body .abar-addon-card{display:flex;align-items:center;gap:14px;padding:12px 14px;border:2px solid #f1f5f9;border-radius:16px;background:#fff;cursor:pointer;transition:border-color .2s,box-shadow .2s,transform .15s}
		body .abar-addon-card:hover{border-color:#c4b5fd;box-shadow:0 8px 24px rgba(99,102,241,.12)}
		body .abar-addon-card.is-selected{border-color:#7c3aed;background:linear-gradient(135deg,rgba(124,58,237,.06),rgba(168,85,247,.08));box-shadow:0 8px 24px rgba(124,58,237,.18)}
		body .abar-addon-card.is-hidden{display:none}
		body .abar-addon-card__thumb{width:64px;height:64px;border-radius:12px;object-fit:cover;flex-shrink:0;background:#f1f5f9}
		body .abar-addon-card__body{flex:1;min-width:0;text-align:right}
		body .abar-addon-card__name{margin:0 0 4px;font-size:15px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
		body .abar-addon-card__price{margin:0;font-size:14px;font-weight:600;color:#7c3aed}
		body .abar-addon-card__price del{color:#94a3b8;font-weight:400;margin-left:6px}
		body .abar-addon-card__check{flex-shrink:0;width:22px;height:22px;accent-color:#7c3aed}
		body .abar-modal__loader{display:flex;flex-direction:column;align-items:center;gap:12px;padding:40px;color:#64748b}
		body .abar-modal__loader.is-hidden{display:none}
		body .abar-spinner{width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:#7c3aed;border-radius:50%;animation:abarSpin .7s linear infinite}
		@keyframes abarSpin{to{transform:rotate(360deg)}}
		body .abar-modal__empty{text-align:center;padding:24px;color:#94a3b8}
		body .abar-modal__footer{padding:16px 20px 20px;border-top:1px solid #f1f5f9;background:#fff}
		body .abar-modal__submit{width:100%;padding:16px!important;border:none!important;border-radius:14px!important;background:linear-gradient(135deg,#059669,#10b981)!important;color:#fff!important;font-size:17px!important;font-weight:700!important;cursor:pointer;transition:opacity .2s,transform .2s}
		body .abar-modal__submit:disabled{opacity:.45;cursor:not-allowed}
		body .abar-modal__submit:not(:disabled):hover{transform:translateY(-1px)}
		body.abar-modal-open{overflow:hidden}
		@media(max-width:480px){body .abar-modal__dialog{border-radius:20px 20px 0 0;max-height:92vh;align-self:flex-end}body .abar-modal{align-items:flex-end;padding:0}}
		';
	}

	/**
	 * Frontend JavaScript.
	 *
	 * @return string
	 */
	private function get_frontend_js() {
		return <<<'JS'
(function($){
	'use strict';
	var state = { productId: 0, max: 1, items: [], qty: 1, selectionType: 'radio' };

	function getModal(){ return document.getElementById('abar-addon-modal'); }
	function openModal(){ var m = getModal(); if(!m) return; m.hidden = false; document.body.classList.add('abar-modal-open'); }
	function closeModal(){ var m = getModal(); if(!m) return; m.hidden = true; document.body.classList.remove('abar-modal-open'); }

	function getSelectedIds(){
		var ids = [];
		$('#abar-addon-list .abar-addon-card__check:checked').each(function(){ ids.push(parseInt($(this).val(), 10)); });
		return ids;
	}

	function updateSubmitState(){
		var ids = getSelectedIds();
		$('#abar-addon-submit').prop('disabled', ids.length === 0);
	}

	function renderList(items, max){
		var $list = $('#abar-addon-list').empty();
		var type = max <= 1 ? 'radio' : 'checkbox';
		state.selectionType = type;
		var inputName = type === 'radio' ? 'abar_addon_choice' : 'abar_addon_choice[]';

		items.forEach(function(item){
			var html = '<label class="abar-addon-card" role="option" data-name="'+ escapeAttr(item.name.toLowerCase()) +'">' +
				'<img class="abar-addon-card__thumb" src="'+ escapeAttr(item.image) +'" alt="" loading="lazy" />' +
				'<div class="abar-addon-card__body">' +
					'<p class="abar-addon-card__name">'+ escapeHtml(item.name) +'</p>' +
					'<p class="abar-addon-card__price">'+ item.price_html +'</p>' +
				'</div>' +
				'<input type="'+ type +'" class="abar-addon-card__check" name="'+ inputName +'" value="'+ item.id +'" />' +
			'</label>';
			$list.append(html);
		});

		$list.find('.abar-addon-card').on('click', function(e){
			if ($(e.target).is('input')) return;
			var $inp = $(this).find('input');
			if (type === 'radio') {
				$inp.prop('checked', true);
			} else {
				$inp.prop('checked', !$inp.prop('checked'));
			}
			$(this).toggleClass('is-selected', $inp.prop('checked'));
			enforceMax(max);
			updateSubmitState();
		});

		$list.on('change', '.abar-addon-card__check', function(){
			$(this).closest('.abar-addon-card').toggleClass('is-selected', this.checked);
			enforceMax(max);
			updateSubmitState();
		});
	}

	function enforceMax(max){
		if (max <= 1) return;
		var $checked = $('#abar-addon-list .abar-addon-card__check:checked');
		if ($checked.length > max) {
			$checked.last().prop('checked', false).closest('.abar-addon-card').removeClass('is-selected');
			alert(abarAddonsFront.i18n.selectLimit + ' ' + max);
		}
	}

	function escapeHtml(s){ return $('<div>').text(s).html(); }
	function escapeAttr(s){ return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

	function liveSearch(q){
		q = (q || '').toLowerCase().trim();
		var visible = 0;
		$('#abar-addon-list .abar-addon-card').each(function(){
			var name = $(this).data('name') || '';
			var show = !q || name.indexOf(q) !== -1;
			$(this).toggleClass('is-hidden', !show);
			if (show) visible++;
		});
		$('#abar-addon-empty').prop('hidden', visible > 0);
	}

	function loadAddons(productId){
		$('#abar-modal-loader').removeClass('is-hidden');
		$('#abar-addon-list').empty();
		$('#abar-addon-empty').prop('hidden', true);

		$.post(abarAddonsFront.ajaxUrl, {
			action: abarAddonsFront.actions.get,
			nonce: abarAddonsFront.nonce,
			product_id: productId
		}).done(function(res){
			if (!res || !res.success) {
				alert(abarAddonsFront.i18n.error);
				closeModal();
				return;
			}
			state.items = res.data.items || [];
			state.max = res.data.max || 1;
			renderList(state.items, state.max);
			$('#abar-addon-search').val('');
		}).fail(function(){
			alert(abarAddonsFront.i18n.error);
			closeModal();
		}).always(function(){
			$('#abar-modal-loader').addClass('is-hidden');
		});
	}

	$(document).on('click', '[data-abar-open-modal]', function(e){
		e.preventDefault();
		var $wrap = $(this).closest('.abar-atc-wrap');
		state.productId = parseInt($wrap.data('product-id'), 10);
		state.max = parseInt($wrap.data('max'), 10) || 1;
		state.qty = parseInt($wrap.find('.qty').val(), 10) || 1;
		openModal();
		loadAddons(state.productId);
	});

	$(document).on('click', '[data-abar-close-modal]', closeModal);
	$(document).on('keydown', function(e){ if (e.key === 'Escape') closeModal(); });

	$('#abar-addon-search').on('input', function(){ liveSearch($(this).val()); });

	$('#abar-addon-submit').on('click', function(){
		var ids = getSelectedIds();
		if (!ids.length) {
			alert(abarAddonsFront.i18n.selectOne);
			return;
		}
		var $btn = $(this).prop('disabled', true).text(abarAddonsFront.i18n.adding);

		$.post(abarAddonsFront.ajaxUrl, {
			action: abarAddonsFront.actions.cart,
			nonce: abarAddonsFront.nonce,
			product_id: state.productId,
			quantity: state.qty,
			addon_ids: ids
		}).done(function(res){
			if (res && res.success && res.data.redirect) {
				window.location.href = res.data.redirect;
			} else {
				alert((res && res.data && res.data.message) || abarAddonsFront.i18n.error);
				$btn.prop('disabled', false).text(abarAddonsFront.i18n.submitLabel);
			}
		}).fail(function(){
			alert(abarAddonsFront.i18n.error);
			$btn.prop('disabled', false).text(abarAddonsFront.i18n.submitLabel);
		});
	});
})(jQuery);
JS;
	}

	/* -------------------------------------------------------------------------
	 * AJAX: frontend
	 * ---------------------------------------------------------------------- */

	/**
	 * AJAX: get addon products for modal.
	 */
	public function ajax_get_addons() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id || ! $this->product_has_addons( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'لا توجد إضافات.', '3abar-wc-product-addons' ) ) );
		}

		$max          = max( 1, (int) get_post_meta( $product_id, self::META_MAX_SELECT, true ) );
		$price_type   = get_post_meta( $product_id, self::META_PRICE_TYPE, true ) ?: 'original';
		$price_amount = (float) get_post_meta( $product_id, self::META_PRICE_AMOUNT, true );

		$items = array();
		foreach ( $this->get_addon_product_ids( $product_id ) as $aid ) {
			$addon = wc_get_product( $aid );
			if ( ! $addon || ! $addon->is_purchasable() ) {
				continue;
			}

			$base   = (float) wc_get_price_to_display( $addon );
			$adj    = $this->calculate_adjusted_price( $base, $price_type, $price_amount );
			$symbol = get_woocommerce_currency_symbol();

			if ( 'original' !== $price_type && $adj !== $base ) {
				$price_html = '<del>' . wc_price( $base ) . '</del> ' . wc_price( $adj );
			} else {
				$price_html = wc_price( $adj );
			}

			$image_id = $addon->get_image_id();
			$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src();

			$items[] = array(
				'id'         => $aid,
				'name'       => $addon->get_name(),
				'price'      => $adj,
				'price_html' => $price_html,
				'image'      => $image,
			);
		}

		wp_send_json_success(
			array(
				'items' => $items,
				'max'   => $max,
			)
		);
	}

	/**
	 * AJAX: add parent + addons to cart, return cart URL.
	 */
	public function ajax_add_to_cart() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, (int) $_POST['quantity'] ) : 1;
		$addon_ids  = isset( $_POST['addon_ids'] ) ? array_map( 'absint', (array) $_POST['addon_ids'] ) : array();

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			wp_send_json_error( array( 'message' => __( 'المنتج غير متاح.', '3abar-wc-product-addons' ) ) );
		}

		$max = max( 1, (int) get_post_meta( $product_id, self::META_MAX_SELECT, true ) );
		if ( count( $addon_ids ) > $max ) {
			wp_send_json_error( array( 'message' => __( 'تجاوزت الحد الأقصى للاختيار.', '3abar-wc-product-addons' ) ) );
		}

		$allowed = $this->get_addon_product_ids( $product_id );
		foreach ( $addon_ids as $aid ) {
			if ( ! in_array( $aid, $allowed, true ) ) {
				wp_send_json_error( array( 'message' => __( 'منتج إضافي غير صالح.', '3abar-wc-product-addons' ) ) );
			}
		}

		$price_type   = get_post_meta( $product_id, self::META_PRICE_TYPE, true ) ?: 'original';
		$price_amount = (float) get_post_meta( $product_id, self::META_PRICE_AMOUNT, true );

		$parent_key = WC()->cart->add_to_cart( $product_id, $quantity );
		if ( ! $parent_key ) {
			wp_send_json_error( array( 'message' => __( 'تعذرت إضافة المنتج الأساسي.', '3abar-wc-product-addons' ) ) );
		}

		foreach ( $addon_ids as $aid ) {
			$addon_product = wc_get_product( $aid );
			if ( ! $addon_product ) {
				continue;
			}
			$base_price = (float) wc_get_price_to_display( $addon_product );
			$adj_price  = $this->calculate_adjusted_price( $base_price, $price_type, $price_amount );

			$cart_item_data = array(
				'3abar_is_addon'     => true,
				'3abar_parent_id'    => $product_id,
				'3abar_price_type'   => $price_type,
				'3abar_price_amount' => $price_amount,
				'3abar_custom_price' => $adj_price,
			);

			WC()->cart->add_to_cart( $aid, 1, 0, array(), $cart_item_data );
		}

		wp_send_json_success(
			array(
				'redirect' => wc_get_cart_url(),
			)
		);
	}

	/**
	 * Apply custom prices to addon cart lines.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function apply_cart_addon_prices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['3abar_is_addon'] ) || ! isset( $cart_item['3abar_custom_price'] ) ) {
				continue;
			}
			$cart_item['data']->set_price( (float) $cart_item['3abar_custom_price'] );
		}
	}

	/**
	 * Show parent product reference in cart.
	 *
	 * @param array $item_data Item data rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_meta( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['3abar_is_addon'] ) && ! empty( $cart_item['3abar_parent_id'] ) ) {
			$parent = get_the_title( (int) $cart_item['3abar_parent_id'] );
			if ( $parent ) {
				$item_data[] = array(
					'key'   => __( 'إضافة لـ', '3abar-wc-product-addons' ),
					'value' => $parent,
				);
			}
		}
		return $item_data;
	}
}

Three_Abar_WC_Product_Addons::instance();

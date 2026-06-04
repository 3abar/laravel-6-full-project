<?php
/**
 * Plugin Name: 3abar WooCommerce Product Addons
 * Plugin URI:  https://3abar.com
 * Description: إضافة احترافية لـ WooCommerce تتيح إرفاق منتجات/تصنيفات بمنتج معيّن، واختيارها من خلال نافذة منبثقة (Modal) أنيقة مع بحث حيّ، وسياسات تسعير متقدمة، وكل ذلك عبر Ajax بالكامل.
 * Version:     1.0.0
 * Author:      Shawky El Moazamy
 * Author URI:  https://3abar.com
 * Text Domain: 3abar-wc-addons
 * Domain Path: /languages
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 *
 * @package ThreeAbar_WC_Addons
 *
 * ملاحظة: هذا البلاجن مكتوب بالكامل في ملف واحد كما هو مطلوب، مع تنظيم الكود
 * داخل كلاس واحد (Singleton) لتجنّب تعارض الأسماء، ولرفع الأداء والوضوح.
 */

// منع الوصول المباشر للملف.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * الكلاس الرئيسي للبلاجن.
 *
 * يدير: الميتا بوكس في لوحة التحكم، حفظ البيانات، الواجهة الأمامية (Modal)،
 * طلبات الـ Ajax (البحث الحي + الإضافة للسلة)، والأنماط (CSS/JS).
 */
final class ThreeAbar_WC_Product_Addons {

	/**
	 * إصدار البلاجن (يُستخدم لإصدارات ملفات الأصول وكسر الكاش).
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * مفاتيح الـ Meta المستخدمة لتخزين الإعدادات على مستوى المنتج.
	 */
	const META_PRODUCTS    = '_3abar_addon_products';
	const META_CATEGORIES  = '_3abar_addon_categories';
	const META_MAX_SELECT  = '_3abar_addon_max_select';
	const META_PRICE_TYPE  = '_3abar_addon_price_type';
	const META_PRICE_VALUE = '_3abar_addon_price_value';

	/**
	 * اسم الـ action الخاص بالـ nonce.
	 */
	const NONCE_ACTION = '3abar_wc_addons_nonce';

	/**
	 * نسخة وحيدة من الكلاس (Singleton).
	 *
	 * @var ThreeAbar_WC_Product_Addons|null
	 */
	private static $instance = null;

	/**
	 * الحصول على النسخة الوحيدة من الكلاس.
	 *
	 * @return ThreeAbar_WC_Product_Addons
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * المُنشئ: يربط كل الـ hooks اللازمة.
	 */
	private function __construct() {
		// لا نُكمّل إن لم يكن WooCommerce مفعّلًا.
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * تهيئة البلاجن بعد تحميل الإضافات.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// ---------- لوحة التحكم (Admin) ----------
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// ---------- الواجهة الأمامية (Frontend) ----------
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
		add_action( 'wp', array( $this, 'maybe_swap_add_to_cart' ) );
		add_action( 'wp_footer', array( $this, 'render_modal_template' ) );

		// ---------- Ajax ----------
		add_action( 'wp_ajax_3abar_search_addons', array( $this, 'ajax_search_addons' ) );
		add_action( 'wp_ajax_nopriv_3abar_search_addons', array( $this, 'ajax_search_addons' ) );
		add_action( 'wp_ajax_3abar_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_3abar_add_to_cart', array( $this, 'ajax_add_to_cart' ) );

		// ---------- تعديل سعر المنتجات المرفقة في السلة ----------
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'inject_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_addon_price' ), 20, 1 );

		// ---------- إظهار المنتج الإضافي كعنصر فرعي داخل السلة ----------
		add_filter( 'woocommerce_cart_item_class', array( $this, 'cart_item_class' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_data_display' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_item_name_prefix' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'cart_assets' ) );
	}

	/**
	 * تنبيه في لوحة التحكم عند غياب WooCommerce.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'بلاجن "3abar WooCommerce Product Addons" يتطلّب تفعيل WooCommerce أولًا.', '3abar-wc-addons' );
		echo '</p></div>';
	}

	/* =====================================================================
	 *  القسم الأول: لوحة التحكم (Meta Box)
	 * ===================================================================== */

	/**
	 * تسجيل الـ Meta Box في صفحة تحرير المنتج.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		add_meta_box(
			'3abar_wc_addons_metabox',
			__( '✨ إضافات 3abar - المنتجات المرفقة', '3abar-wc-addons' ),
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * عرض محتوى الـ Meta Box.
	 *
	 * @param WP_Post $post المنتج الحالي.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, '3abar_wc_addons_nonce_field' );

		$selected_products   = (array) get_post_meta( $post->ID, self::META_PRODUCTS, true );
		$selected_categories = (array) get_post_meta( $post->ID, self::META_CATEGORIES, true );
		$max_select          = (int) get_post_meta( $post->ID, self::META_MAX_SELECT, true );
		$max_select          = $max_select > 0 ? $max_select : 1;
		$price_type          = get_post_meta( $post->ID, self::META_PRICE_TYPE, true );
		$price_type          = $price_type ? $price_type : 'original';
		$price_value         = get_post_meta( $post->ID, self::META_PRICE_VALUE, true );

		$price_types = array(
			'original'          => __( 'استخدم سعر المنتج الأصلي', '3abar-wc-addons' ),
			'increase_fixed'    => __( 'زيادة ثابتة (مبلغ)', '3abar-wc-addons' ),
			'discount_fixed'    => __( 'خصم ثابت (مبلغ)', '3abar-wc-addons' ),
			'increase_percent'  => __( 'زيادة بنسبة %', '3abar-wc-addons' ),
			'discount_percent'  => __( 'خصم بنسبة %', '3abar-wc-addons' ),
		);
		?>
		<div class="threeabar-metabox">
			<div class="threeabar-mb-header">
				<span class="threeabar-mb-badge">3ABAR</span>
				<p class="threeabar-mb-desc">
					<?php esc_html_e( 'حدّد المنتجات و/أو التصنيفات التي ستظهر للعميل ليختار منها داخل النافذة المنبثقة عند الشراء.', '3abar-wc-addons' ); ?>
				</p>
			</div>

			<div class="threeabar-mb-grid">
				<div class="threeabar-field">
					<label for="3abar_addon_products"><?php esc_html_e( 'منتجات محددة', '3abar-wc-addons' ); ?></label>
					<select class="threeabar-select2-products" id="3abar_addon_products" name="<?php echo esc_attr( self::META_PRODUCTS ); ?>[]" multiple="multiple" style="width:100%;" data-placeholder="<?php esc_attr_e( 'ابحث واختر منتجات...', '3abar-wc-addons' ); ?>">
						<?php
						foreach ( $selected_products as $product_id ) {
							$product_obj = wc_get_product( $product_id );
							if ( $product_obj ) {
								printf(
									'<option value="%d" selected="selected">%s</option>',
									esc_attr( $product_id ),
									esc_html( wp_strip_all_tags( $product_obj->get_formatted_name() ) )
								);
							}
						}
						?>
					</select>
				</div>

				<div class="threeabar-field">
					<label for="3abar_addon_categories"><?php esc_html_e( 'تصنيفات', '3abar-wc-addons' ); ?></label>
					<select class="threeabar-select2-cats" id="3abar_addon_categories" name="<?php echo esc_attr( self::META_CATEGORIES ); ?>[]" multiple="multiple" style="width:100%;" data-placeholder="<?php esc_attr_e( 'اختر تصنيفات...', '3abar-wc-addons' ); ?>">
						<?php
						$terms = get_terms(
							array(
								'taxonomy'   => 'product_cat',
								'hide_empty' => false,
							)
						);
						if ( ! is_wp_error( $terms ) ) {
							foreach ( $terms as $term ) {
								printf(
									'<option value="%d" %s>%s</option>',
									esc_attr( $term->term_id ),
									in_array( $term->term_id, array_map( 'intval', $selected_categories ), true ) ? 'selected="selected"' : '',
									esc_html( $term->name )
								);
							}
						}
						?>
					</select>
				</div>

				<div class="threeabar-field">
					<label for="3abar_addon_max_select"><?php esc_html_e( 'الحد الأقصى لعدد المنتجات القابلة للاختيار', '3abar-wc-addons' ); ?></label>
					<input type="number" min="1" step="1" id="3abar_addon_max_select" name="<?php echo esc_attr( self::META_MAX_SELECT ); ?>" value="<?php echo esc_attr( $max_select ); ?>" />
					<small><?php esc_html_e( 'إذا كانت القيمة = 1 سيظهر للعميل أزرار راديو، وإذا كانت أكبر من 1 ستظهر مربعات اختيار.', '3abar-wc-addons' ); ?></small>
				</div>

				<div class="threeabar-field">
					<label for="3abar_addon_price_type"><?php esc_html_e( 'سياسة السعر (Price Adjustment)', '3abar-wc-addons' ); ?></label>
					<select id="3abar_addon_price_type" name="<?php echo esc_attr( self::META_PRICE_TYPE ); ?>" class="threeabar-price-type" style="width:100%;">
						<?php foreach ( $price_types as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $price_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="threeabar-field threeabar-price-value-wrap" style="<?php echo ( 'original' === $price_type ) ? 'display:none;' : ''; ?>">
					<label for="3abar_addon_price_value"><?php esc_html_e( 'قيمة التعديل (مبلغ أو نسبة)', '3abar-wc-addons' ); ?></label>
					<input type="number" min="0" step="0.01" id="3abar_addon_price_value" name="<?php echo esc_attr( self::META_PRICE_VALUE ); ?>" value="<?php echo esc_attr( $price_value ); ?>" />
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * حفظ بيانات الـ Meta Box مع التحقق من الأمان والتنظيف.
	 *
	 * @param int     $post_id معرّف المنتج.
	 * @param WP_Post $post    كائن المنتج.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		// التحقق من الـ nonce.
		if ( ! isset( $_POST['3abar_wc_addons_nonce_field'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['3abar_wc_addons_nonce_field'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// تجاهل الحفظ التلقائي والمراجعات والصلاحيات.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// المنتجات.
		$products = isset( $_POST[ self::META_PRODUCTS ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ self::META_PRODUCTS ] ) ) : array();
		update_post_meta( $post_id, self::META_PRODUCTS, array_values( array_filter( $products ) ) );

		// التصنيفات.
		$categories = isset( $_POST[ self::META_CATEGORIES ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ self::META_CATEGORIES ] ) ) : array();
		update_post_meta( $post_id, self::META_CATEGORIES, array_values( array_filter( $categories ) ) );

		// الحد الأقصى.
		$max_select = isset( $_POST[ self::META_MAX_SELECT ] ) ? absint( wp_unslash( $_POST[ self::META_MAX_SELECT ] ) ) : 1;
		update_post_meta( $post_id, self::META_MAX_SELECT, max( 1, $max_select ) );

		// نوع السعر.
		$allowed_types = array( 'original', 'increase_fixed', 'discount_fixed', 'increase_percent', 'discount_percent' );
		$price_type    = isset( $_POST[ self::META_PRICE_TYPE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_PRICE_TYPE ] ) ) : 'original';
		$price_type    = in_array( $price_type, $allowed_types, true ) ? $price_type : 'original';
		update_post_meta( $post_id, self::META_PRICE_TYPE, $price_type );

		// قيمة التعديل.
		$price_value = isset( $_POST[ self::META_PRICE_VALUE ] ) ? wc_format_decimal( wp_unslash( $_POST[ self::META_PRICE_VALUE ] ) ) : 0;
		update_post_meta( $post_id, self::META_PRICE_VALUE, $price_value );
	}

	/**
	 * تحميل أصول لوحة التحكم (Select2 + أنماط الميتا بوكس) في صفحة تحرير المنتج فقط.
	 *
	 * @param string $hook الصفحة الحالية.
	 * @return void
	 */
	public function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		// Select2 المُرفق مع WooCommerce.
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'select2' );

		wp_add_inline_style( 'select2', $this->admin_css() );
		wp_add_inline_script( 'selectWoo', $this->admin_js() );
	}

	/* =====================================================================
	 *  القسم الثاني: الواجهة الأمامية (Frontend)
	 * ===================================================================== */

	/**
	 * تحميل أصول الواجهة الأمامية فقط في صفحات المنتجات التي تحتوي على إضافات.
	 *
	 * @return void
	 */
	public function frontend_assets() {
		if ( ! is_product() ) {
			return;
		}

		global $post;
		if ( ! $post || ! $this->product_has_addons( $post->ID ) ) {
			return;
		}

		// نستخدم jQuery المُرفق مع ووردبريس فقط (لا اعتماديات خارجية).
		wp_enqueue_script( 'jquery' );

		// تمرير المتغيرات اللازمة لـ Ajax.
		$inline_data = sprintf(
			'window.ThreeAbarAddons = %s;',
			wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
					'i18n'    => array(
						'loading'   => __( 'جارٍ التحميل...', '3abar-wc-addons' ),
						'noResults' => __( 'لا توجد منتجات مطابقة.', '3abar-wc-addons' ),
						'maxReached' => __( 'لقد وصلت للحد الأقصى من الاختيارات.', '3abar-wc-addons' ),
						'adding'    => __( 'جارٍ الإضافة...', '3abar-wc-addons' ),
						'error'     => __( 'حدث خطأ، حاول مرة أخرى.', '3abar-wc-addons' ),
					),
				)
			)
		);

		wp_add_inline_script( 'jquery', $inline_data, 'after' );
		wp_add_inline_script( 'jquery', $this->frontend_js(), 'after' );

		// نُسجّل ستايل وهمي لإرفاق الـ CSS المُضمّن به.
		wp_register_style( '3abar-wc-addons-inline', false, array(), self::VERSION );
		wp_enqueue_style( '3abar-wc-addons-inline' );
		wp_add_inline_style( '3abar-wc-addons-inline', $this->frontend_css() );
	}

	/**
	 * استبدال زر "أضف إلى السلة" الأصلي بزر مخصص (للمنتجات التي بها إضافات فقط).
	 *
	 * @return void
	 */
	public function maybe_swap_add_to_cart() {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		global $post;
		if ( ! $post || ! $this->product_has_addons( $post->ID ) ) {
			return;
		}

		// إزالة زر الإضافة الأصلي واستبداله بزرّنا المخصص.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_custom_button' ), 30 );
	}

	/**
	 * عرض الزر المخصص الذي يفتح النافذة المنبثقة.
	 *
	 * @return void
	 */
	public function render_custom_button() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$product_id = $product->get_id();
		$max_select = (int) get_post_meta( $product_id, self::META_MAX_SELECT, true );
		$max_select = $max_select > 0 ? $max_select : 1;
		?>
		<div class="threeabar-cta-wrap">
			<button type="button"
					class="threeabar-open-modal button alt"
					data-product-id="<?php echo esc_attr( $product_id ); ?>"
					data-max="<?php echo esc_attr( $max_select ); ?>">
				<span class="threeabar-cta-icon" aria-hidden="true">🛍️</span>
				<span class="threeabar-cta-text"><?php esc_html_e( 'اطلب الآن واختر إضافاتك', '3abar-wc-addons' ); ?></span>
			</button>
		</div>
		<?php
	}

	/**
	 * طباعة قالب النافذة المنبثقة في الفوتر (مرة واحدة لكل صفحة منتج بها إضافات).
	 *
	 * @return void
	 */
	public function render_modal_template() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		global $post;
		if ( ! $post || ! $this->product_has_addons( $post->ID ) ) {
			return;
		}
		?>
		<div class="threeabar-modal-overlay" id="threeabarModal" aria-hidden="true">
			<div class="threeabar-modal" role="dialog" aria-modal="true" aria-labelledby="threeabarModalTitle">
				<div class="threeabar-modal-glow"></div>
				<header class="threeabar-modal-head">
					<h3 id="threeabarModalTitle"><?php esc_html_e( 'اختر المنتجات الإضافية', '3abar-wc-addons' ); ?></h3>
					<button type="button" class="threeabar-modal-close" aria-label="<?php esc_attr_e( 'إغلاق', '3abar-wc-addons' ); ?>">&times;</button>
				</header>

				<div class="threeabar-search-bar">
					<span class="threeabar-search-icon" aria-hidden="true">🔍</span>
					<input type="text" class="threeabar-search-input" placeholder="<?php esc_attr_e( 'ابحث عن منتج...', '3abar-wc-addons' ); ?>" autocomplete="off" />
				</div>

				<div class="threeabar-modal-body">
					<div class="threeabar-results"></div>
				</div>

				<footer class="threeabar-modal-foot">
					<div class="threeabar-selection-info">
						<span class="threeabar-selected-count">0</span>
						<span class="threeabar-selected-sep">/</span>
						<span class="threeabar-selected-max">0</span>
					</div>
					<button type="button" class="threeabar-confirm-btn" disabled>
						<span class="threeabar-confirm-text"><?php esc_html_e( 'إضافة إلى السلة', '3abar-wc-addons' ); ?></span>
						<span class="threeabar-confirm-spinner" aria-hidden="true"></span>
					</button>
				</footer>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 *  القسم الثالث: طلبات الـ Ajax
	 * ===================================================================== */

	/**
	 * Ajax: البحث الحي عن المنتجات المرفقة بمنتج معيّن.
	 *
	 * @return void
	 */
	public function ajax_search_addons() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'منتج غير صالح.', '3abar-wc-addons' ) ) );
		}

		$addon_ids = $this->get_addon_product_ids( $product_id );
		if ( empty( $addon_ids ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'post__in'       => $addon_ids,
			'posts_per_page' => 50,
			'orderby'        => 'post__in',
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$addon = wc_get_product( get_the_ID() );
				if ( ! $addon || ! $addon->is_purchasable() || ! $addon->is_in_stock() ) {
					continue;
				}

				$base_price     = (float) wc_get_price_to_display( $addon );
				$adjusted_price = $this->calculate_adjusted_price( $base_price, $product_id );

				$items[] = array(
					'id'          => $addon->get_id(),
					'name'        => $addon->get_name(),
					'image'       => wp_get_attachment_image_url( $addon->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src( 'woocommerce_thumbnail' ),
					'price_html'  => wc_price( $adjusted_price ),
					'price_raw'   => $adjusted_price,
					'is_discount' => $adjusted_price < $base_price,
					'orig_price'  => $adjusted_price !== $base_price ? wc_price( $base_price ) : '',
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Ajax: إضافة المنتج الأساسي + المنتجات المختارة إلى السلة.
	 *
	 * @return void
	 */
	public function ajax_add_to_cart() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$addons     = isset( $_POST['addons'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['addons'] ) ) : array();

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'منتج غير صالح.', '3abar-wc-addons' ) ) );
		}

		// التحقق من أن المنتجات المختارة ضمن الإضافات المسموح بها.
		$allowed_ids = $this->get_addon_product_ids( $product_id );
		$addons      = array_values( array_intersect( $addons, $allowed_ids ) );

		// احترام الحد الأقصى المسموح به.
		$max_select = (int) get_post_meta( $product_id, self::META_MAX_SELECT, true );
		$max_select = $max_select > 0 ? $max_select : 1;
		if ( count( $addons ) > $max_select ) {
			$addons = array_slice( $addons, 0, $max_select );
		}

		// إضافة المنتج الأساسي.
		$added_main = WC()->cart->add_to_cart( $product_id );
		if ( ! $added_main ) {
			wp_send_json_error( array( 'message' => __( 'تعذّرت إضافة المنتج الأساسي.', '3abar-wc-addons' ) ) );
		}

		// إضافة المنتجات المرفقة مع تمرير معرّف المنتج الأصل لتطبيق السعر.
		foreach ( $addons as $addon_id ) {
			WC()->cart->add_to_cart(
				$addon_id,
				1,
				0,
				array(),
				array( '3abar_parent' => $product_id )
			);
		}

		wp_send_json_success(
			array(
				'message'      => __( 'تمت الإضافة بنجاح!', '3abar-wc-addons' ),
				'redirect_url' => wc_get_cart_url(),
			)
		);
	}

	/* =====================================================================
	 *  القسم الرابع: تعديل أسعار المنتجات المرفقة داخل السلة
	 * ===================================================================== */

	/**
	 * حقن معرّف المنتج الأصل في بيانات عنصر السلة (لتطبيق السعر لاحقًا).
	 *
	 * @param array $cart_item_data بيانات عنصر السلة.
	 * @param int   $product_id     معرّف المنتج المُضاف.
	 * @param int   $variation_id   معرّف المتغيّر.
	 * @return array
	 */
	public function inject_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( isset( $cart_item_data['3abar_parent'] ) ) {
			// نضمن أن العنصر فريد حتى لا يندمج مع نفس المنتج المُضاف بشكل عادي.
			$cart_item_data['3abar_unique'] = md5( microtime() . wp_rand() );
		}
		return $cart_item_data;
	}

	/**
	 * تطبيق سياسة السعر على المنتجات المرفقة داخل السلة.
	 *
	 * @param WC_Cart $cart السلة.
	 * @return void
	 */
	public function apply_addon_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['3abar_parent'] ) ) {
				continue;
			}
			$parent_id  = absint( $cart_item['3abar_parent'] );
			$base_price = (float) $cart_item['data']->get_price( 'edit' );
			$new_price  = $this->calculate_adjusted_price( $base_price, $parent_id );
			$cart_item['data']->set_price( $new_price );
		}
	}

	/**
	 * إضافة كلاسات CSS لصفوف السلة لتمييز المنتج الأصل والمنتجات الفرعية.
	 *
	 * @param string $class         كلاسات الصف.
	 * @param array  $cart_item     عنصر السلة.
	 * @param string $cart_item_key مفتاح العنصر.
	 * @return string
	 */
	public function cart_item_class( $class, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['3abar_parent'] ) ) {
			$class .= ' threeabar-addon-cart-item';
		} elseif ( $this->item_has_addons_in_cart( $cart_item ) ) {
			$class .= ' threeabar-parent-cart-item';
		}
		return $class;
	}

	/**
	 * إضافة سطر يوضّح أن هذا العنصر إضافة تابعة لمنتج رئيسي.
	 *
	 * @param array $item_data بيانات العرض.
	 * @param array $cart_item عنصر السلة.
	 * @return array
	 */
	public function cart_item_data_display( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['3abar_parent'] ) ) {
			$parent = wc_get_product( absint( $cart_item['3abar_parent'] ) );
			if ( $parent ) {
				$item_data[] = array(
					'key'     => __( 'إضافة إلى', '3abar-wc-addons' ),
					'value'   => $parent->get_name(),
					'display' => '<span class="threeabar-addon-parent-tag">' . esc_html( $parent->get_name() ) . '</span>',
				);
			}
		}
		return $item_data;
	}

	/**
	 * إضافة رمز التفرّع قبل اسم المنتج الإضافي داخل السلة.
	 *
	 * @param string $name          اسم المنتج (HTML).
	 * @param array  $cart_item     عنصر السلة.
	 * @param string $cart_item_key مفتاح العنصر.
	 * @return string
	 */
	public function cart_item_name_prefix( $name, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['3abar_parent'] ) && ! is_admin() ) {
			$name = '<span class="threeabar-addon-branch" aria-hidden="true">↳</span> ' . $name;
		}
		return $name;
	}

	/**
	 * هل يملك عنصر السلة هذا منتجات إضافية مرتبطة به داخل السلة؟
	 *
	 * @param array $cart_item عنصر السلة (الأصل المحتمل).
	 * @return bool
	 */
	private function item_has_addons_in_cart( $cart_item ) {
		if ( ! WC()->cart ) {
			return false;
		}
		$parent_product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
		if ( ! $parent_product_id ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $ci ) {
			if ( ! empty( $ci['3abar_parent'] ) && absint( $ci['3abar_parent'] ) === $parent_product_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * تحميل أنماط صفحة السلة/الدفع لإظهار العناصر الفرعية بشكل متداخل.
	 *
	 * @return void
	 */
	public function cart_assets() {
		if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) ) {
			return;
		}
		wp_register_style( '3abar-wc-addons-cart', false, array(), self::VERSION );
		wp_enqueue_style( '3abar-wc-addons-cart' );
		wp_add_inline_style( '3abar-wc-addons-cart', $this->cart_css() );
	}

	/* =====================================================================
	 *  القسم الخامس: دوال مساعدة
	 * ===================================================================== */

	/**
	 * هل يحتوي المنتج على إضافات مُعرّفة؟
	 *
	 * @param int $product_id معرّف المنتج.
	 * @return bool
	 */
	private function product_has_addons( $product_id ) {
		return ! empty( $this->get_addon_product_ids( $product_id ) );
	}

	/**
	 * الحصول على قائمة معرّفات المنتجات المرفقة (من المنتجات المحددة + التصنيفات).
	 *
	 * @param int $product_id معرّف المنتج الأصل.
	 * @return int[]
	 */
	private function get_addon_product_ids( $product_id ) {
		$products   = (array) get_post_meta( $product_id, self::META_PRODUCTS, true );
		$categories = (array) get_post_meta( $product_id, self::META_CATEGORIES, true );

		$ids = array_map( 'absint', array_filter( $products ) );

		if ( ! empty( $categories ) ) {
			$cat_query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => array_map( 'absint', $categories ),
						),
					),
				)
			);
			if ( ! empty( $cat_query->posts ) ) {
				$ids = array_merge( $ids, array_map( 'absint', $cat_query->posts ) );
			}
		}

		// استبعاد المنتج نفسه ومنع التكرار.
		$ids = array_diff( array_unique( $ids ), array( absint( $product_id ) ) );

		return array_values( $ids );
	}

	/**
	 * حساب السعر بعد تطبيق سياسة التسعير الخاصة بالمنتج الأصل.
	 *
	 * @param float $base_price سعر المنتج المرفق الأصلي.
	 * @param int   $parent_id  معرّف المنتج الأصل (الذي يحمل سياسة التسعير).
	 * @return float
	 */
	private function calculate_adjusted_price( $base_price, $parent_id ) {
		$type  = get_post_meta( $parent_id, self::META_PRICE_TYPE, true );
		$value = (float) get_post_meta( $parent_id, self::META_PRICE_VALUE, true );
		$price = (float) $base_price;

		switch ( $type ) {
			case 'increase_fixed':
				$price = $price + $value;
				break;
			case 'discount_fixed':
				$price = $price - $value;
				break;
			case 'increase_percent':
				$price = $price + ( $price * ( $value / 100 ) );
				break;
			case 'discount_percent':
				$price = $price - ( $price * ( $value / 100 ) );
				break;
			case 'original':
			default:
				// لا تغيير.
				break;
		}

		return max( 0, round( $price, wc_get_price_decimals() ) );
	}

	/* =====================================================================
	 *  القسم السادس: الأنماط والسكربتات (CSS / JS)
	 * ===================================================================== */

	/**
	 * أنماط لوحة التحكم (Meta Box).
	 *
	 * @return string
	 */
	private function admin_css() {
		return '
		.threeabar-metabox{padding:6px 2px;font-family:inherit}
		.threeabar-mb-header{display:flex;align-items:center;gap:14px;padding:16px 18px;margin:-6px -2px 18px;border-radius:14px;background:linear-gradient(120deg,#1a1206 0%,#3a2a0c 55%,#5a4209 100%);color:#f4c453;box-shadow:0 10px 30px -12px rgba(74,52,9,.7);border:1px solid #e0a73c}
		.threeabar-mb-badge{font-weight:800;letter-spacing:1px;background:linear-gradient(120deg,#b97e16,#f4c453);color:#1a1206;padding:8px 14px;border-radius:10px;border:1px solid rgba(244,196,83,.5)}
		.threeabar-mb-desc{margin:0;opacity:.95;font-size:13px;line-height:1.7;color:#f3ead3}
		.threeabar-mb-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}
		.threeabar-field{display:flex;flex-direction:column;gap:7px;background:#fff;border:1px solid #f0e6cf;border-radius:12px;padding:14px 16px;transition:.25s box-shadow,.25s transform}
		.threeabar-field:hover{box-shadow:0 8px 24px -14px rgba(184,128,28,.45);transform:translateY(-1px)}
		.threeabar-field label{font-weight:700;color:#5a4209;font-size:13px}
		.threeabar-field small{color:#9c8f6e;font-size:11.5px;line-height:1.6}
		.threeabar-field input[type=number]{border-radius:9px;border:1.5px solid #f0e6cf;padding:9px 11px;font-size:14px;outline:none;transition:.2s}
		.threeabar-field input[type=number]:focus{border-color:#e0a73c;box-shadow:0 0 0 3px rgba(224,167,60,.18)}
		.threeabar-metabox .select2-container--default .select2-selection--multiple,
		.threeabar-metabox .select2-container--default .select2-selection--single{border-radius:9px!important;border:1.5px solid #f0e6cf!important;min-height:40px}
		.threeabar-metabox .select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#e0a73c!important}
		.threeabar-metabox .select2-container--default .select2-selection--multiple .select2-selection__choice{background:linear-gradient(120deg,#b97e16,#e0a73c)!important;border:none!important;color:#1a1206!important;font-weight:700;border-radius:7px!important;padding:3px 9px!important}
		@media(max-width:782px){.threeabar-mb-grid{grid-template-columns:1fr}}
		';
	}

	/**
	 * سكربت لوحة التحكم (تهيئة Select2 + إظهار/إخفاء حقل القيمة).
	 *
	 * @return string
	 */
	private function admin_js() {
		$ajax_url = admin_url( 'admin-ajax.php' );
		ob_start();
		?>
		(function($){
			$(function(){
				// تهيئة Select2 للتصنيفات.
				if ($.fn.selectWoo){
					$('.threeabar-select2-cats, .threeabar-price-type').selectWoo();

					// Select2 للمنتجات مع بحث Ajax مدمج بـ WooCommerce.
					$('.threeabar-select2-products').selectWoo({
						ajax:{
							url: '<?php echo esc_js( $ajax_url ); ?>',
							dataType:'json',
							delay:250,
							data:function(params){
								return {
									term: params.term,
									action:'woocommerce_json_search_products_and_variations',
									security: '<?php echo esc_js( wp_create_nonce( 'search-products' ) ); ?>'
								};
							},
							processResults:function(data){
								var results = [];
								if (data){
									$.each(data, function(id, text){
										results.push({id:id, text:text});
									});
								}
								return {results:results};
							},
							cache:true
						},
						minimumInputLength:1
					});
				}

				// إظهار/إخفاء حقل قيمة السعر حسب النوع.
				function toggleValue(){
					var t = $('#3abar_addon_price_type').val();
					if (t === 'original'){
						$('.threeabar-price-value-wrap').slideUp(150);
					} else {
						$('.threeabar-price-value-wrap').slideDown(150);
					}
				}
				$('#3abar_addon_price_type').on('change', toggleValue);
			});
		})(jQuery);
		<?php
		return ob_get_clean();
	}

	/**
	 * أنماط الواجهة الأمامية (الزر + النافذة المنبثقة) بتصميم حديث وخيالي.
	 *
	 * @return string
	 */
	private function frontend_css() {
		return '
		/* ====== الزر المخصص ====== */
		.threeabar-cta-wrap{margin:18px 0}
		.threeabar-open-modal{position:relative;display:inline-flex!important;align-items:center;gap:12px;border:none!important;cursor:pointer;color:#1a1206!important;font-weight:800!important;font-size:17px!important;padding:16px 34px!important;border-radius:16px!important;background:linear-gradient(120deg,#b97e16 0%,#e0a73c 50%,#f4c453 100%)!important;box-shadow:0 16px 34px -14px rgba(184,128,28,.85);transition:transform .25s,box-shadow .25s;overflow:hidden}
		.threeabar-open-modal:before{content:"";position:absolute;inset:0;background:linear-gradient(120deg,transparent,rgba(255,255,255,.45),transparent);transform:translateX(-120%);transition:transform .7s}
		.threeabar-open-modal:hover{transform:translateY(-3px) scale(1.02);box-shadow:0 22px 44px -14px rgba(224,167,60,.95)}
		.threeabar-open-modal:hover:before{transform:translateX(120%)}
		.threeabar-cta-icon{font-size:20px;filter:drop-shadow(0 2px 4px rgba(0,0,0,.2))}

		/* ====== الخلفية والنافذة ====== */
		.threeabar-modal-overlay{position:fixed;inset:0;z-index:999999;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(20,15,6,.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);opacity:0;transition:opacity .3s}
		.threeabar-modal-overlay.is-open{display:flex;opacity:1}
		.threeabar-modal{position:relative;width:100%;max-width:680px;max-height:88vh;display:flex;flex-direction:column;background:linear-gradient(180deg,#ffffff 0%,#fffaf0 100%);border-radius:24px;box-shadow:0 40px 90px -30px rgba(74,52,9,.65);overflow:hidden;transform:translateY(28px) scale(.96);opacity:0;transition:transform .35s cubic-bezier(.2,.9,.3,1.2),opacity .35s;direction:rtl}
		.threeabar-modal-overlay.is-open .threeabar-modal{transform:translateY(0) scale(1);opacity:1}
		.threeabar-modal-glow{position:absolute;top:-120px;inset-inline-end:-120px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,rgba(244,196,83,.5),transparent 65%);pointer-events:none;filter:blur(8px)}

		/* ====== الهيدر ====== */
		.threeabar-modal-head{position:relative;display:flex;align-items:center;justify-content:space-between;padding:22px 26px;background:linear-gradient(120deg,#1a1206 0%,#3a2a0c 55%,#5a4209 100%);color:#f4c453;border-bottom:2px solid #e0a73c}
		.threeabar-modal-head h3{margin:0;font-size:21px;font-weight:800;color:#f4c453;text-shadow:0 1px 2px rgba(0,0,0,.4)}
		.threeabar-modal-close{background:rgba(244,196,83,.18);border:1px solid rgba(244,196,83,.4);color:#f4c453;width:38px;height:38px;border-radius:50%;font-size:24px;line-height:1;cursor:pointer;transition:.2s}
		.threeabar-modal-close:hover{background:rgba(244,196,83,.35);transform:rotate(90deg)}

		/* ====== البحث ====== */
		.threeabar-search-bar{position:relative;padding:18px 26px 6px}
		.threeabar-search-icon{position:absolute;inset-inline-start:40px;top:50%;transform:translateY(-30%);font-size:16px;opacity:.6}
		.threeabar-search-input{width:100%;padding:14px 46px;border:2px solid #f0e6cf;border-radius:14px;font-size:15px;outline:none;transition:.2s;background:#fff}
		.threeabar-search-input:focus{border-color:#e0a73c;box-shadow:0 0 0 4px rgba(224,167,60,.18)}

		/* ====== الجسم والنتائج ====== */
		.threeabar-modal-body{flex:1;overflow-y:auto;padding:10px 26px 18px}
		.threeabar-modal-body::-webkit-scrollbar{width:9px}
		.threeabar-modal-body::-webkit-scrollbar-thumb{background:#e8cf94;border-radius:8px}
		.threeabar-results{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:8px}
		.threeabar-card{position:relative;display:flex;gap:13px;align-items:center;padding:13px;border:2px solid #f2e8d0;border-radius:16px;background:#fff;cursor:pointer;transition:transform .2s,border-color .2s,box-shadow .2s;outline:none}
		.threeabar-card:hover,.threeabar-card:focus-visible{transform:translateY(-2px);border-color:#e8c878;box-shadow:0 12px 26px -16px rgba(184,128,28,.6)}
		.threeabar-card.is-selected{border-color:#e0a73c;background:linear-gradient(180deg,#fffaf0,#fdf3da);box-shadow:0 12px 30px -14px rgba(224,167,60,.6)}
		.threeabar-card.is-selected:after{content:"\2713";position:absolute;top:9px;inset-inline-start:9px;width:24px;height:24px;border-radius:50%;background:linear-gradient(120deg,#b97e16,#e0a73c);color:#1a1206;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;box-shadow:0 4px 10px -3px rgba(184,128,28,.7)}
		.threeabar-card img{width:64px;height:64px;object-fit:cover;border-radius:12px;flex:0 0 64px;background:#faf3e2}
		.threeabar-card-info{flex:1;min-width:0}
		.threeabar-card-name{margin:0 0 5px;font-size:14px;font-weight:700;color:#3a2a0c;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
		.threeabar-card-price{font-size:14px;font-weight:800;color:#b97e16}
		.threeabar-card-price del{color:#bcae8e;font-weight:500;font-size:12px;margin-inline-start:6px}
		.threeabar-card input{position:absolute;opacity:0;pointer-events:none}

		/* حالات فارغة/تحميل */
		.threeabar-state{grid-column:1/-1;text-align:center;padding:40px 10px;color:#9c8f6e;font-size:15px}
		.threeabar-spinner{width:42px;height:42px;margin:0 auto 14px;border:4px solid #f3ead3;border-top-color:#e0a73c;border-radius:50%;animation:threeabarSpin .8s linear infinite}
		@keyframes threeabarSpin{to{transform:rotate(360deg)}}

		/* ====== الفوتر ====== */
		.threeabar-modal-foot{display:flex;align-items:center;gap:16px;padding:18px 26px;border-top:1px solid #f0e6cf;background:#fff}
		.threeabar-selection-info{font-weight:800;color:#8a5a12;font-size:16px;background:#fdf3da;padding:10px 16px;border-radius:12px;min-width:64px;text-align:center}
		.threeabar-selected-sep{opacity:.4;margin:0 2px}
		.threeabar-confirm-btn{flex:1;position:relative;border:none;cursor:pointer;color:#1a1206;font-weight:800;font-size:17px;padding:16px;border-radius:14px;background:linear-gradient(120deg,#b97e16 0%,#e0a73c 50%,#f4c453 100%);box-shadow:0 14px 30px -12px rgba(184,128,28,.8);transition:transform .2s,box-shadow .2s,opacity .2s;display:flex;align-items:center;justify-content:center;gap:10px}
		.threeabar-confirm-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 20px 40px -12px rgba(224,167,60,.9)}
		.threeabar-confirm-btn:disabled{opacity:.45;cursor:not-allowed}
		.threeabar-confirm-btn.is-loading .threeabar-confirm-text{opacity:.5}
		.threeabar-confirm-spinner{display:none;width:20px;height:20px;border:3px solid rgba(26,18,6,.35);border-top-color:#1a1206;border-radius:50%;animation:threeabarSpin .7s linear infinite}
		.threeabar-confirm-btn.is-loading .threeabar-confirm-spinner{display:inline-block}

		/* ====== التجاوب ====== */
		@media(max-width:600px){
			.threeabar-results{grid-template-columns:1fr}
			.threeabar-modal{max-height:92vh;border-radius:20px}
			.threeabar-modal-head h3{font-size:18px}
			.threeabar-open-modal{width:100%;justify-content:center}
		}
		';
	}

	/**
	 * أنماط صفحة السلة/الدفع: إظهار المنتجات الإضافية كعناصر فرعية متداخلة.
	 *
	 * @return string
	 */
	private function cart_css() {
		return '
		.threeabar-parent-cart-item td{border-bottom:none!important}
		.threeabar-addon-cart-item{background:#fffaf0!important}
		.threeabar-addon-cart-item > td.product-name,
		.threeabar-addon-cart-item > td:nth-child(3){position:relative}
		.threeabar-addon-cart-item td.product-name{padding-inline-start:42px!important}
		.threeabar-addon-cart-item td.product-name:before{content:"";position:absolute;inset-inline-start:20px;top:0;bottom:0;width:3px;border-radius:3px;background:linear-gradient(180deg,#e0a73c,#b97e16)}
		.threeabar-addon-branch{color:#b97e16;font-weight:800;margin-inline-end:4px}
		.threeabar-addon-cart-item .product-name a{color:#5a4209!important;font-weight:600}
		.threeabar-addon-parent-tag{display:inline-block;background:linear-gradient(120deg,#fff4dc,#fde9bf);color:#8a5a12;border:1px solid #f0d9a0;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:700}
		/* عربة السلة المبنية على البلوكات (Block Cart) */
		.wc-block-cart-items__row.threeabar-addon-cart-item{background:#fffaf0}
		';
	}

	/**
	 * سكربت الواجهة الأمامية (فتح النافذة + بحث حي + اختيار + إضافة للسلة).
	 *
	 * @return string
	 */
	private function frontend_js() {
		ob_start();
		?>
		(function($){
			'use strict';
			var cfg = window.ThreeAbarAddons || {};
			var $overlay, $modal, $results, $search, $confirm, $count, $maxEl;
			var state = { productId:0, max:1, selected:[], searchTimer:null, lastTerm:null };

			$(function(){
				$overlay = $('#threeabarModal');
				if (!$overlay.length){ return; }
				$modal   = $overlay.find('.threeabar-modal');
				$results = $overlay.find('.threeabar-results');
				$search  = $overlay.find('.threeabar-search-input');
				$confirm = $overlay.find('.threeabar-confirm-btn');
				$count   = $overlay.find('.threeabar-selected-count');
				$maxEl   = $overlay.find('.threeabar-selected-max');

				bindEvents();
			});

			function bindEvents(){
				// فتح النافذة من الزر المخصص.
				$(document).on('click', '.threeabar-open-modal', function(){
					state.productId = parseInt($(this).data('product-id'), 10) || 0;
					state.max       = parseInt($(this).data('max'), 10) || 1;
					state.selected  = [];
					openModal();
				});

				// إغلاق النافذة.
				$overlay.on('click', '.threeabar-modal-close', closeModal);
				$overlay.on('click', function(e){ if (e.target === this){ closeModal(); } });
				$(document).on('keydown', function(e){ if (e.key === 'Escape'){ closeModal(); } });

				// البحث الحي (debounced).
				$search.on('input', function(){
					var term = $.trim($(this).val());
					clearTimeout(state.searchTimer);
					state.searchTimer = setTimeout(function(){ fetchAddons(term); }, 280);
				});

				// اختيار/إلغاء اختيار بطاقة.
				$results.on('click', '.threeabar-card', function(){ toggleCard($(this)); });

				// دعم لوحة المفاتيح (Enter / Space) للوصولية.
				$results.on('keydown', '.threeabar-card', function(e){
					if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar'){
						e.preventDefault();
						toggleCard($(this));
					}
				});

				// تأكيد الإضافة للسلة.
				$confirm.on('click', addToCart);
			}

			function openModal(){
				$maxEl.text(state.max);
				$count.text(0);
				$search.val('');
				updateConfirm();
				$overlay.addClass('is-open').attr('aria-hidden','false');
				$('body').css('overflow','hidden');
				fetchAddons('');
				setTimeout(function(){ $search.trigger('focus'); }, 350);
			}

			function closeModal(){
				$overlay.removeClass('is-open').attr('aria-hidden','true');
				$('body').css('overflow','');
			}

			function setState(html){
				$results.html('<div class="threeabar-state">'+ html +'</div>');
			}

			function fetchAddons(term){
				state.lastTerm = term;
				setState('<div class="threeabar-spinner"></div>'+ (cfg.i18n ? cfg.i18n.loading : ''));
				$.ajax({
					url: cfg.ajaxUrl,
					method:'POST',
					dataType:'json',
					data:{
						action:'3abar_search_addons',
						nonce: cfg.nonce,
						product_id: state.productId,
						search: term
					}
				}).done(function(res){
					if (term !== state.lastTerm){ return; } // تجاهل النتائج القديمة.
					if (res && res.success && res.data.items.length){
						renderItems(res.data.items);
					} else {
						setState(cfg.i18n ? cfg.i18n.noResults : 'No results');
					}
				}).fail(function(){
					setState(cfg.i18n ? cfg.i18n.error : 'Error');
				});
			}

			function renderItems(items){
				var type = state.max > 1 ? 'checkbox' : 'radio';
				var html = '';
				items.forEach(function(it){
					var sel = state.selected.indexOf(it.id) !== -1 ? ' is-selected' : '';
					var orig = it.orig_price ? '<del>'+ it.orig_price +'</del>' : '';
					// نستخدم div بدلًا من label لتجنّب إطلاق حدث click مزدوج
					// (الـ label المرتبط بـ input يطلق الحدث مرتين فيُلغي التحديد).
					html += ''+
					'<div class="threeabar-card'+ sel +'" data-id="'+ it.id +'" role="button" tabindex="0" aria-pressed="'+ (sel?'true':'false') +'">'+
						'<input type="'+ type +'" name="threeabar_addon" value="'+ it.id +'" '+ (sel?'checked':'') +' tabindex="-1" aria-hidden="true" />'+
						'<img src="'+ it.image +'" alt="" loading="lazy" />'+
						'<div class="threeabar-card-info">'+
							'<p class="threeabar-card-name">'+ it.name +'</p>'+
							'<div class="threeabar-card-price">'+ it.price_html + orig +'</div>'+
						'</div>'+
					'</div>';
				});
				$results.html(html);
			}

			function toggleCard($card){
				var id = parseInt($card.data('id'), 10);
				var idx = state.selected.indexOf(id);

				if (state.max <= 1){
					// راديو: اختيار واحد فقط.
					state.selected = (idx !== -1) ? [] : [id];
					$results.find('.threeabar-card').removeClass('is-selected').attr('aria-pressed','false').find('input').prop('checked',false);
					if (state.selected.length){
						$card.addClass('is-selected').attr('aria-pressed','true').find('input').prop('checked',true);
					}
				} else {
					// تشيك بوكس: حتى الحد الأقصى.
					if (idx !== -1){
						state.selected.splice(idx,1);
						$card.removeClass('is-selected').attr('aria-pressed','false').find('input').prop('checked',false);
					} else {
						if (state.selected.length >= state.max){
							flash(cfg.i18n ? cfg.i18n.maxReached : '');
							return;
						}
						state.selected.push(id);
						$card.addClass('is-selected').attr('aria-pressed','true').find('input').prop('checked',true);
					}
				}
				updateConfirm();
			}

			function updateConfirm(){
				$count.text(state.selected.length);
				$confirm.prop('disabled', state.selected.length === 0);
			}

			function flash(msg){
				if (!msg){ return; }
				var $f = $('<div class="threeabar-flash"></div>').text(msg).css({
					position:'fixed', bottom:'28px', insetInlineStart:'50%', transform:'translateX(-50%)',
					background:'linear-gradient(120deg,#b97e16,#e0a73c)', color:'#1a1206', padding:'12px 22px', borderRadius:'12px',
					zIndex:9999999, fontWeight:'700', boxShadow:'0 14px 30px -12px rgba(184,128,28,.8)'
				});
				$('body').append($f);
				setTimeout(function(){ $f.fadeOut(300, function(){ $(this).remove(); }); }, 1800);
			}

			function addToCart(){
				if (!state.selected.length){ return; }
				$confirm.addClass('is-loading').prop('disabled', true);
				$.ajax({
					url: cfg.ajaxUrl,
					method:'POST',
					dataType:'json',
					data:{
						action:'3abar_add_to_cart',
						nonce: cfg.nonce,
						product_id: state.productId,
						addons: state.selected
					}
				}).done(function(res){
					if (res && res.success){
						window.location.href = res.data.redirect_url;
					} else {
						$confirm.removeClass('is-loading').prop('disabled', false);
						flash((res && res.data && res.data.message) ? res.data.message : (cfg.i18n ? cfg.i18n.error : 'Error'));
					}
				}).fail(function(){
					$confirm.removeClass('is-loading').prop('disabled', false);
					flash(cfg.i18n ? cfg.i18n.error : 'Error');
				});
			}
		})(jQuery);
		<?php
		return ob_get_clean();
	}
}

// إقلاع البلاجن.
ThreeAbar_WC_Product_Addons::instance();

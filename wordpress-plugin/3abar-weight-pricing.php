<?php
/**
 * Plugin Name: 3abar Weight Pricing
 * Plugin URI:  https://3abar.com
 * Description: حل احترافي لمنتجات الوزن في WooCommerce: أزرار وزن مرتّبة حسب لوحة التحكم، سعر إجمالي ديناميكي (السعر × الكمية) بتنسيق العملة الصحيح، تحديد الافتراضي، وصفحة إعدادات لتغيير المسمّيات — مع إخفاء السعر الافتراضي لمنتجات الوزن فقط دون المساس بباقي المنتجات. يتكامل مع بلاجن "3abar Product Colors" لإضافة رسوم اللون للسعر الحي.
 * Version:     1.3.0
 * Author:      Shawky El Moazamy
 * Author URI:  https://3abar.com
 * Text Domain: 3abar-weight-pricing
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 *
 * @package ThreeAbar_Weight_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * الكلاس الرئيسي لتسعير الوزن والخيارات.
 */
final class ThreeAbar_Weight_Pricing {

	/**
	 * مفتاح خيار الإعدادات.
	 */
	const OPTION = 'threeabar_wp_settings';

	/**
	 * النسخة الوحيدة.
	 *
	 * @var ThreeAbar_Weight_Pricing|null
	 */
	private static $instance = null;

	/**
	 * هل الصفحة الحالية منتج مستهدف (لتفعيل الأصول)؟
	 *
	 * @var bool
	 */
	private $is_target_page = false;

	/**
	 * إعدادات مُحمّلة (كاش).
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * الحصول على النسخة الوحيدة.
	 *
	 * @return ThreeAbar_Weight_Pricing
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * المُنشئ.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * تهيئة الـ hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'wp', array( $this, 'setup_single_product' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'price_hash' ), 99, 3 );

		// صفحة الإعدادات.
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ) );
	}

	/* =====================================================================
	 *  الإعدادات
	 * ===================================================================== */

	/**
	 * الإعدادات الافتراضية المدموجة مع المحفوظة.
	 *
	 * @return array
	 */
	public function get_settings() {
		if ( null !== $this->settings ) {
			return $this->settings;
		}
		$defaults = array(
			'price_label'     => __( 'السعر الإجمالي حسب الوزن والكمية', '3abar-weight-pricing' ),
			'choose_text'     => __( 'اختر الخيارات لعرض السعر', '3abar-weight-pricing' ),
			'weight_taxonomy' => 'pa_weight',
			'weight_label'    => __( 'اختر الوزن:', '3abar-weight-pricing' ),
		);
		$saved          = get_option( self::OPTION, array() );
		$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		return $this->settings;
	}

	/**
	 * تسجيل صفحة الإعدادات تحت قائمة WooCommerce.
	 *
	 * @return void
	 */
	public function register_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'إعدادات الوزن والخيارات (3abar)', '3abar-weight-pricing' ),
			__( '3abar الوزن والخيارات', '3abar-weight-pricing' ),
			'manage_woocommerce',
			'threeabar-weight-pricing',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * تسجيل الإعداد والتعقيم.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'threeabar_wp_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * تعقيم الإعدادات قبل الحفظ.
	 *
	 * @param array $input المدخلات.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array(
			'price_label'     => sanitize_text_field( $input['price_label'] ?? '' ),
			'choose_text'     => sanitize_text_field( $input['choose_text'] ?? '' ),
			'weight_taxonomy' => sanitize_key( $input['weight_taxonomy'] ?? 'pa_weight' ),
			'weight_label'    => sanitize_text_field( $input['weight_label'] ?? '' ),
		);
	}

	/**
	 * رابط الإعدادات في صفحة الإضافات.
	 *
	 * @param array $links الروابط.
	 * @return array
	 */
	public function settings_link( $links ) {
		$url  = admin_url( 'admin.php?page=threeabar-weight-pricing' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'الإعدادات', '3abar-weight-pricing' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * عرض صفحة الإعدادات.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$s = $this->get_settings();
		?>
		<div class="wrap threeabar-settings">
			<h1><?php esc_html_e( 'إعدادات الوزن والخيارات — 3abar', '3abar-weight-pricing' ); ?></h1>
			<p class="description"><?php esc_html_e( 'تحكّم في مسمّيات الخيارات الظاهرة للعميل (اختر الوزن / اختر اللون) وتفعيل خيار اللون.', '3abar-weight-pricing' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'threeabar_wp_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tw_price_label"><?php esc_html_e( 'مسمّى صندوق السعر', '3abar-weight-pricing' ); ?></label></th>
						<td><input type="text" id="tw_price_label" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[price_label]" value="<?php echo esc_attr( $s['price_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="tw_choose_text"><?php esc_html_e( 'نص قبل الاختيار', '3abar-weight-pricing' ); ?></label></th>
						<td><input type="text" id="tw_choose_text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[choose_text]" value="<?php echo esc_attr( $s['choose_text'] ); ?>" />
						<p class="description"><?php esc_html_e( 'يظهر داخل صندوق السعر قبل اختيار العميل لخياراته.', '3abar-weight-pricing' ); ?></p></td>
					</tr>

					<tr><th colspan="2"><h2><?php esc_html_e( 'خيار الوزن', '3abar-weight-pricing' ); ?></h2></th></tr>
					<tr>
						<th scope="row"><label for="tw_weight_label"><?php esc_html_e( 'مسمّى اختيار الوزن', '3abar-weight-pricing' ); ?></label></th>
						<td><input type="text" id="tw_weight_label" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[weight_label]" value="<?php echo esc_attr( $s['weight_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="tw_weight_tax"><?php esc_html_e( 'خاصية الوزن (Taxonomy)', '3abar-weight-pricing' ); ?></label></th>
						<td><input type="text" id="tw_weight_tax" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[weight_taxonomy]" value="<?php echo esc_attr( $s['weight_taxonomy'] ); ?>" />
						<p class="description"><?php esc_html_e( 'عادةً: pa_weight', '3abar-weight-pricing' ); ?></p></td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'الألوان تُدار من بلاجن "3abar Product Colors" المستقل (إعدادات الألوان + لوحة المنتج).', '3abar-weight-pricing' ); ?></p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* =====================================================================
	 *  تجهيز صفحة المنتج
	 * ===================================================================== */

	/**
	 * لا نتدخّل إلا للمنتجات المتغيّرة التي تستخدم خاصية مستهدفة (وزن/لون).
	 *
	 * @return void
	 */
	public function setup_single_product() {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = $this->get_current_product();
		if ( ! $this->product_is_target( $product ) ) {
			return;
		}

		$this->is_target_page = true;

		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
		add_filter( 'woocommerce_show_variation_price', '__return_false' );

		add_action( 'woocommerce_single_product_summary', array( $this, 'render_dynamic_price' ), 11 );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_selectors' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * إضافة كلاس للجسم لتحديد نطاق إخفاء السعر بدقّة.
	 *
	 * @param array $classes كلاسات الجسم.
	 * @return array
	 */
	public function body_class( $classes ) {
		if ( $this->is_target_page ) {
			$classes[] = 'threeabar-weight-product';
		}
		return $classes;
	}

	/* =====================================================================
	 *  العرض في الواجهة
	 * ===================================================================== */

	/**
	 * صندوق السعر الديناميكي مع سعر ابتدائي حقيقي.
	 *
	 * @return void
	 */
	public function render_dynamic_price() {
		$product = $this->get_current_product();
		if ( ! $this->product_is_target( $product ) ) {
			return;
		}
		$s       = $this->get_settings();
		$initial = (float) $product->get_variation_price( 'min', true );
		?>
		<div class="threeabar-price-box">
			<span class="threeabar-price-label"><?php echo esc_html( $s['price_label'] ); ?></span>
			<div class="threeabar-dynamic-price" data-initial="<?php echo esc_attr( $initial ); ?>">
				<?php echo wp_kses_post( wc_price( $initial ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * عرض كل محدّدات الخيارات (وزن/لون) مرتّبة وبدون تكرار.
	 *
	 * @return void
	 */
	public function render_selectors() {
		$product = $this->get_current_product();
		if ( ! $this->product_is_target( $product ) ) {
			return;
		}

		$attributes = $this->get_selector_attributes( $product );
		if ( empty( $attributes ) ) {
			return;
		}

		$available = $product->get_available_variations();
		$single    = ( 1 === count( $attributes ) );

		foreach ( $attributes as $attr ) {
			$this->render_one_selector( $product, $attr, $available, $single );
		}
	}

	/**
	 * عرض محدّد واحد (مجموعة أزرار لخاصية معيّنة).
	 *
	 * @param WC_Product $product   المنتج.
	 * @param array      $attr      وصف الخاصية (taxonomy/label).
	 * @param array      $available المتغيّرات المتاحة.
	 * @param bool       $single    هل توجد خاصية واحدة فقط؟
	 * @return void
	 */
	private function render_one_selector( $product, $attr, $available, $single ) {
		$taxonomy = $attr['taxonomy'];
		$slugs    = $this->get_ordered_slugs( $product, $taxonomy );
		if ( empty( $slugs ) ) {
			return;
		}

		$default_slug = $this->get_default_slug( $product, $taxonomy );
		?>
		<div class="threeabar-opt-group" data-attr="<?php echo esc_attr( $taxonomy ); ?>">
			<label class="threeabar-opt-title"><?php echo esc_html( $attr['label'] ); ?></label>
			<div class="threeabar-opt-options">
				<?php
				foreach ( $slugs as $slug ) {
					$term  = get_term_by( 'slug', $slug, $taxonomy );
					$name  = $term ? $term->name : ucfirst( str_replace( '-', ' ', $slug ) );
					$price = null;
					$stock = false;

					foreach ( $available as $v ) {
						$vslug = isset( $v['attributes'][ 'attribute_' . $taxonomy ] ) ? $v['attributes'][ 'attribute_' . $taxonomy ] : '';
						if ( '' === $vslug || $vslug === $slug ) {
							if ( ! empty( $v['is_in_stock'] ) ) {
								$stock = true;
							}
							if ( null === $price && isset( $v['display_price'] ) ) {
								$price = (float) $v['display_price'];
							}
						}
					}

					$is_def     = ( '' !== $default_slug && $default_slug === $slug );
					$data_price = ( $single && null !== $price ) ? $price : '';

					printf(
						'<button type="button" class="threeabar-opt-btn%1$s" data-slug="%2$s" data-price="%3$s"%4$s><span class="threeabar-opt-name">%5$s</span>%6$s</button>',
						$is_def ? ' selected' : '',
						esc_attr( $slug ),
						esc_attr( $data_price ),
						$stock ? '' : ' disabled',
						esc_html( $name ),
						( $single && null !== $price ) ? '<span class="threeabar-opt-price">' . wp_kses_post( wc_price( $price ) ) . '</span>' : ''
					);
				}
				?>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 *  الأصول (CSS/JS)
	 * ===================================================================== */

	/**
	 * تحميل الأصول للمنتج المستهدف فقط.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_target_page ) {
			return;
		}
		$s = $this->get_settings();

		wp_enqueue_script( 'jquery' );

		$config = array(
			'symbol'      => html_entity_decode( get_woocommerce_currency_symbol() ),
			'decimals'    => wc_get_price_decimals(),
			'decimalSep'  => wc_get_price_decimal_separator(),
			'thousandSep' => wc_get_price_thousand_separator(),
			'format'      => get_woocommerce_price_format(),
			'i18n'        => array( 'choose' => $s['choose_text'] ),
		);

		wp_register_style( '3abar-weight-pricing', false, array(), '1.2.0' );
		wp_enqueue_style( '3abar-weight-pricing' );
		wp_add_inline_style( '3abar-weight-pricing', $this->css() );

		wp_add_inline_script( 'jquery', 'window.ThreeAbarWeight = ' . wp_json_encode( $config ) . ';', 'after' );
		wp_add_inline_script( 'jquery', $this->js(), 'after' );
	}

	/**
	 * أنماط المنتج (بنطاق body.threeabar-weight-product).
	 *
	 * @return string
	 */
	private function css() {
		return '
		body.threeabar-weight-product .summary > .price,
		body.threeabar-weight-product p.price,
		body.threeabar-weight-product .woocommerce-variation-price,
		body.threeabar-weight-product .single_variation .price,
		body.threeabar-weight-product form.variations_form .variations{display:none!important}

		.threeabar-price-box{margin:18px 0 24px;padding:18px 22px;background:linear-gradient(180deg,#fffdf8,#fff6e6);border-radius:14px;border-inline-start:6px solid #e0a73c;box-shadow:0 6px 18px -10px rgba(184,128,28,.45)}
		.threeabar-price-label{display:block;font-size:.95rem;color:#6b5414;margin-bottom:8px;font-weight:600}
		.threeabar-dynamic-price{font-size:2.2rem;font-weight:800;color:#b97e16;line-height:1.1}
		.threeabar-dynamic-price .woocommerce-Price-amount{color:#b97e16}
		.threeabar-dynamic-price.is-empty{font-size:1.1rem;color:#a8986f;font-weight:600}

		.threeabar-opt-group{margin:18px 0 22px}
		.threeabar-opt-title{display:block;margin-bottom:12px;font-weight:700;font-size:1.05rem;color:#3a2a0c}
		.threeabar-opt-options{display:flex;gap:12px;flex-wrap:wrap}
		.threeabar-opt-btn{display:inline-flex;flex-direction:column;align-items:center;gap:4px;padding:12px 24px;border:2px solid #ead9b3;background:#fff;border-radius:16px;cursor:pointer;font-size:1rem;font-weight:700;color:#3a2a0c;transition:all .25s;min-width:96px}
		.threeabar-opt-btn:hover:not(:disabled){border-color:#e0a73c;transform:translateY(-2px);box-shadow:0 10px 22px -14px rgba(184,128,28,.6)}
		.threeabar-opt-name{font-weight:800}
		.threeabar-opt-price{font-size:.85rem;font-weight:800;color:#b97e16}
		/* الحالة المحددة: خلفية ذهبية داكنة ونص أبيض واضح */
		.threeabar-opt-btn.selected{background:linear-gradient(120deg,#7a5210 0%,#a16e12 55%,#c8881f 100%);color:#fff;border-color:#6b4410;box-shadow:0 12px 26px -10px rgba(122,82,16,.8);text-shadow:0 1px 2px rgba(0,0,0,.25)}
		.threeabar-opt-btn.selected .threeabar-opt-name{color:#fff}
		.threeabar-opt-btn.selected .threeabar-opt-price,
		.threeabar-opt-btn.selected .threeabar-opt-price *{color:#ffe6ad!important;text-shadow:0 1px 2px rgba(0,0,0,.3)}
		.threeabar-opt-btn:disabled{opacity:.4;cursor:not-allowed;text-decoration:line-through}

		@media(max-width:768px){
			.threeabar-price-box{padding:14px 16px}
			.threeabar-dynamic-price{font-size:1.7rem}
			.threeabar-opt-options{gap:9px}
			.threeabar-opt-btn{flex:1 1 calc(33.333% - 9px);min-width:0;padding:11px 8px}
			.threeabar-opt-title{font-size:1rem}
		}
		@media(max-width:380px){
			.threeabar-opt-btn{flex:1 1 calc(50% - 9px)}
		}
		';
	}

	/**
	 * سكربت المنتج (يدعم أي عدد من المحدّدات).
	 *
	 * @return string
	 */
	private function js() {
		ob_start();
		?>
		(function($){
			'use strict';
			var cfg = window.ThreeAbarWeight || {};

			function formatMoney(amount){
				var n = parseFloat(amount); if(isNaN(n)){ n = 0; }
				var dec = parseInt(cfg.decimals,10); if(isNaN(dec)){ dec = 2; }
				var parts = n.toFixed(dec).split('.');
				parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousandSep || ',');
				var num = parts.length > 1 ? parts[0] + (cfg.decimalSep || '.') + parts[1] : parts[0];
				var fmt = cfg.format || '%1$s%2$s';
				var html = fmt.replace('%1$s', '<span class="woocommerce-Price-currencySymbol">'+ (cfg.symbol||'') +'</span>').replace('%2$s', num);
				return '<span class="woocommerce-Price-amount amount"><bdi>'+ html +'</bdi></span>';
			}

			$(function(){
				var $form = $('form.variations_form');
				if(!$form.length){ return; }

				var $price  = $('.threeabar-dynamic-price');
				var $qty    = $form.find('input.qty');
				var current = parseFloat($price.data('initial')) || 0;

				function markSelected(attr, val){
					var $g = $('.threeabar-opt-group[data-attr="'+ attr +'"]');
					$g.find('.threeabar-opt-btn').removeClass('selected');
					if(val){ $g.find('.threeabar-opt-btn[data-slug="'+ val +'"]').addClass('selected'); }
				}

				function render(){
					var qty = parseFloat($qty.val()) || 1;
					// رسوم اللون (إن وُجد بلاجن الألوان) تُضاف لسعر الوحدة قبل الضرب في الكمية.
					var colorFee = parseFloat(window.ThreeAbarColorSurcharge) || 0;
					var unit = current + (current > 0 ? colorFee : 0);
					if(current > 0){
						$price.removeClass('is-empty').html(formatMoney(unit * qty));
					} else {
						$price.addClass('is-empty').text(cfg.i18n ? cfg.i18n.choose : '');
					}
				}

				// إعادة الحساب عند تغيير اللون من بلاجن الألوان.
				$(document.body).on('threeabar_color_changed', render);

				$(document).on('click', '.threeabar-opt-btn:not(:disabled)', function(){
					var $b   = $(this);
					var attr = $b.closest('.threeabar-opt-group').data('attr');
					var slug = $b.data('slug');
					var dp   = $b.data('price');
					if(dp !== undefined && dp !== ''){ current = parseFloat(dp) || current; }
					markSelected(attr, slug);
					$form.find('select[name="attribute_' + attr + '"]').val(slug).trigger('change');
					render();
				});

				// المصدر الموثوق للسعر عند تطابق كل الخصائص.
				$form.on('found_variation', function(e, variation){
					if(!variation){ return; }
					current = parseFloat(variation.display_price);
					if(isNaN(current)){ current = parseFloat(variation.price) || 0; }
					$.each(variation.attributes || {}, function(key, val){
						markSelected(key.replace('attribute_',''), val);
					});
					render();
				});

				$qty.on('change keyup input', render);

				$form.on('reset_data', function(){
					current = parseFloat($price.data('initial')) || 0;
					render();
				});

				// التشغيل الأولي: اختيار الافتراضي أو أول متاح لكل محدّد.
				setTimeout(function(){
					$('.threeabar-opt-group').each(function(){
						var $g     = $(this);
						var attr   = $g.data('attr');
						var $select = $form.find('select[name="attribute_' + attr + '"]');
						var val    = $select.val();
						if(!val){
							var $btn = $g.find('.threeabar-opt-btn.selected:not(:disabled)').first();
							if(!$btn.length){ $btn = $g.find('.threeabar-opt-btn:not(:disabled)').first(); }
							val = $btn.length ? $btn.data('slug') : '';
							if(val){ $select.val(val); }
						}
						if(val){ markSelected(attr, val); }
					});
					$form.find('.variations select').trigger('change');
					$form.trigger('check_variations');
					render();
				}, 500);

				render();
			});
		})(jQuery);
		<?php
		return ob_get_clean();
	}

	/* =====================================================================
	 *  الكاش
	 * ===================================================================== */

	/**
	 * إدراج الخيارات المختارة ضمن هاش كاش الأسعار.
	 *
	 * @param array      $hash       الهاش.
	 * @param WC_Product $product    المنتج.
	 * @param bool       $for_display للعرض؟
	 * @return array
	 */
	public function price_hash( $hash, $product, $for_display ) {
		$s   = $this->get_settings();
		$key = 'attribute_' . $s['weight_taxonomy'];
		$hash[] = isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $hash;
	}

	/* =====================================================================
	 *  دوال مساعدة
	 * ===================================================================== */

	/**
	 * المنتج الحالي بأمان.
	 *
	 * @return WC_Product|null
	 */
	private function get_current_product() {
		global $product;
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		$id = get_queried_object_id();
		return $id ? wc_get_product( $id ) : null;
	}

	/**
	 * هل المنتج متغيّر ويستخدم خاصية مستهدفة (وزن أو لون مفعّل)؟
	 *
	 * @param WC_Product|null $product المنتج.
	 * @return bool
	 */
	private function product_is_target( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return false;
		}
		return ! empty( $this->get_selector_attributes( $product ) );
	}

	/**
	 * قائمة الخصائص التي سنعرض لها محدّدات (حسب الإعدادات وما يستخدمه المنتج فعليًا).
	 *
	 * @param WC_Product $product المنتج.
	 * @return array
	 */
	private function get_selector_attributes( $product ) {
		$s    = $this->get_settings();
		$used = array_keys( (array) $product->get_variation_attributes() );
		$list = array();

		$weight_tax = $s['weight_taxonomy'];
		if ( $weight_tax && in_array( $weight_tax, $used, true ) ) {
			$list[] = array(
				'taxonomy' => $weight_tax,
				'label'    => $s['weight_label'],
				'is_color' => false,
			);
		}

		return $list;
	}

	/**
	 * استخراج قيم الخاصية (slugs) بنفس ترتيب لوحة التحكم تمامًا.
	 *
	 * نعتمد على ترتيب خيارات الخاصية المحفوظة على المنتج (get_options)
	 * بدل get_variation_attributes الذي قد يعيد ترتيبًا مختلفًا.
	 *
	 * @param WC_Product $product  المنتج.
	 * @param string     $taxonomy الخاصية.
	 * @return string[]
	 */
	private function get_ordered_slugs( $product, $taxonomy ) {
		$attributes = $product->get_attributes();
		if ( ! isset( $attributes[ $taxonomy ] ) ) {
			return array();
		}
		$attribute = $attributes[ $taxonomy ];

		// القيم المستخدمة فعليًا في المتغيّرات (لاستبعاد ما لا متغيّر له).
		$var_attrs  = $product->get_variation_attributes();
		$used       = isset( $var_attrs[ $taxonomy ] ) ? array_map( 'strval', (array) $var_attrs[ $taxonomy ] ) : array();
		$use_filter = ! empty( $used );

		$ordered = array();
		if ( $attribute->is_taxonomy() ) {
			// نستخدم wc_get_product_terms لأنه يُرجع المصطلحات بنفس ترتيب
			// لوحة التحكم (ترتيب الخاصية المُعرّف) — وهو نفس ترتيب قائمة
			// المتغيّرات الافتراضية في WooCommerce.
			$terms = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'all' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$ordered[] = $term->slug;
				}
			}
		}

		// خاصية محلية (غير taxonomy) أو احتياطي: ترتيب خيارات المنتج كما حُفظت.
		if ( empty( $ordered ) ) {
			foreach ( (array) $attribute->get_options() as $option ) {
				if ( $attribute->is_taxonomy() ) {
					$term      = get_term( (int) $option, $taxonomy );
					$ordered[] = ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
				} else {
					$ordered[] = sanitize_title( $option );
				}
			}
			$ordered = array_filter( $ordered );
		}

		if ( $use_filter ) {
			$ordered = array_values(
				array_filter(
					$ordered,
					function ( $slug ) use ( $used ) {
						return in_array( (string) $slug, $used, true );
					}
				)
			);
		}

		return $ordered;
	}

	/**
	 * الوزن/الخيار الافتراضي (slug) لخاصية معيّنة.
	 *
	 * @param WC_Product $product  المنتج.
	 * @param string     $taxonomy الخاصية.
	 * @return string
	 */
	private function get_default_slug( $product, $taxonomy ) {
		$defaults = $product->get_default_attributes();
		return ! empty( $defaults[ $taxonomy ] ) ? $defaults[ $taxonomy ] : '';
	}
}

ThreeAbar_Weight_Pricing::instance();

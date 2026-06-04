<?php
/**
 * Plugin Name: 3abar Weight Pricing
 * Plugin URI:  https://3abar.com
 * Description: حل احترافي لمنتجات الوزن في WooCommerce: أزرار وزن مرتّبة حسب لوحة التحكم، تحديد الوزن الافتراضي، سعر إجمالي ديناميكي (وزن × كمية) بتنسيق العملة الصحيح — مع إخفاء السعر الافتراضي لمنتجات الوزن فقط، وعدم المساس بأسعار باقي المنتجات (البسيطة وغير ذات الوزن تظل تعرض سعرها طبيعيًا).
 * Version:     1.1.0
 * Author:      Shawky El Moazamy
 * Author URI:  https://3abar.com
 * Text Domain: 3abar-weight-pricing
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 *
 * @package ThreeAbar_Weight_Pricing
 *
 * ملاحظات التطوير مقارنةً بالكود الأصلي:
 * 1) لا يُخفى السعر عالميًا — يقتصر التأثير على منتجات الوزن المتغيّرة فقط،
 *    لذلك تظهر المنتجات البسيطة والمتغيّرة بدون "pa_weight" بسعرها الطبيعي.
 * 2) سعر ابتدائي حقيقي (الافتراضي أو الأدنى) بدل صفر.
 * 3) تنسيق العملة يُؤخذ من إعدادات WooCommerce بدل "OMR" الثابت.
 * 4) إزالة التكرار في الأوزان عبر خريطة slug ⇒ variation مع الحفاظ على ترتيب لوحة التحكم.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * الكلاس الرئيسي لتسعير الوزن.
 */
final class ThreeAbar_Weight_Pricing {

	/**
	 * اسم خاصية الوزن (taxonomy).
	 */
	const ATTR = 'pa_weight';

	/**
	 * النسخة الوحيدة.
	 *
	 * @var ThreeAbar_Weight_Pricing|null
	 */
	private static $instance = null;

	/**
	 * هل الصفحة الحالية منتج وزن (لتفعيل الأصول)؟
	 *
	 * @var bool
	 */
	private $is_weight_page = false;

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
	}

	/**
	 * تجهيز صفحة المنتج: لا نتدخّل إلا لمنتجات الوزن المتغيّرة.
	 *
	 * @return void
	 */
	public function setup_single_product() {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = $this->get_current_product();
		if ( ! $this->is_weight_variable( $product ) ) {
			return; // المنتجات الأخرى تبقى بسعرها الطبيعي دون أي تعديل.
		}

		$this->is_weight_page = true;

		// إخفاء السعر الافتراضي لمنتج الوزن فقط.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
		add_filter( 'woocommerce_show_variation_price', '__return_false' );

		// عناصرنا المخصصة.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_dynamic_price' ), 11 );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_weight_buttons' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * إضافة كلاس للجسم لتحديد نطاق إخفاء السعر بدقّة.
	 *
	 * @param array $classes كلاسات الجسم.
	 * @return array
	 */
	public function body_class( $classes ) {
		if ( $this->is_weight_page ) {
			$classes[] = 'threeabar-weight-product';
		}
		return $classes;
	}

	/* =====================================================================
	 *  العرض
	 * ===================================================================== */

	/**
	 * عرض حاوية السعر الديناميكي مع سعر ابتدائي حقيقي.
	 *
	 * @return void
	 */
	public function render_dynamic_price() {
		$product = $this->get_current_product();
		if ( ! $this->is_weight_variable( $product ) ) {
			return;
		}

		$initial = $this->get_initial_price( $product );
		?>
		<div class="threeabar-price-box">
			<span class="threeabar-price-label"><?php esc_html_e( 'السعر الإجمالي حسب الوزن والكمية', '3abar-weight-pricing' ); ?></span>
			<div class="threeabar-dynamic-price" data-initial="<?php echo esc_attr( $initial ); ?>">
				<?php echo wp_kses_post( wc_price( $initial ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * عرض أزرار الوزن مرتّبة حسب لوحة التحكم وبدون تكرار.
	 *
	 * @return void
	 */
	public function render_weight_buttons() {
		$product = $this->get_current_product();
		if ( ! $this->is_weight_variable( $product ) ) {
			return;
		}

		// خريطة slug ⇒ متغيّر (تحافظ على أول متغيّر مرئي لكل وزن وتمنع التكرار).
		$variation_map = $this->get_weight_variation_map( $product );
		if ( empty( $variation_map ) ) {
			return;
		}

		// الترتيب حسب لوحة التحكم: نأخذ ترتيب مصطلحات الخاصية كما هو مُعرّف للمنتج.
		$ordered_terms = wc_get_product_terms( $product->get_id(), self::ATTR, array( 'fields' => 'all' ) );
		$default_slug  = $this->get_default_slug( $product );
		?>
		<div class="threeabar-weight-wrap">
			<label class="threeabar-weight-title"><?php esc_html_e( 'اختر الوزن:', '3abar-weight-pricing' ); ?></label>
			<div class="threeabar-weight-options">
				<?php
				foreach ( $ordered_terms as $term ) {
					if ( ! isset( $variation_map[ $term->slug ] ) ) {
						continue;
					}
					$variation = $variation_map[ $term->slug ];
					$price     = (float) wc_get_price_to_display( $variation );
					$in_stock  = $variation->is_in_stock();
					$is_def    = ( $default_slug && $default_slug === $term->slug );

					printf(
						'<button type="button" class="threeabar-weight-btn%1$s" data-slug="%2$s" data-price="%3$s"%4$s>'
						. '<span class="threeabar-weight-name">%5$s</span>'
						. '<span class="threeabar-weight-unit-price">%6$s</span>'
						. '</button>',
						$is_def ? ' selected' : '',
						esc_attr( $term->slug ),
						esc_attr( $price ),
						$in_stock ? '' : ' disabled',
						esc_html( $term->name ),
						wp_kses_post( wc_price( $price ) )
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
	 * تحميل الأصول لمنتج الوزن فقط.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_weight_page ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		$config = array(
			'symbol'      => html_entity_decode( get_woocommerce_currency_symbol() ),
			'decimals'    => wc_get_price_decimals(),
			'decimalSep'  => wc_get_price_decimal_separator(),
			'thousandSep' => wc_get_price_thousand_separator(),
			'format'      => get_woocommerce_price_format(),
			'attr'        => self::ATTR,
			'i18n'        => array(
				'choose' => __( 'اختر الوزن لعرض السعر', '3abar-weight-pricing' ),
			),
		);

		wp_register_style( '3abar-weight-pricing', false, array(), '1.1.0' );
		wp_enqueue_style( '3abar-weight-pricing' );
		wp_add_inline_style( '3abar-weight-pricing', $this->css() );

		wp_add_inline_script( 'jquery', 'window.ThreeAbarWeight = ' . wp_json_encode( $config ) . ';', 'after' );
		wp_add_inline_script( 'jquery', $this->js(), 'after' );
	}

	/**
	 * أنماط منتج الوزن (مع تحديد النطاق على body.threeabar-weight-product).
	 *
	 * @return string
	 */
	private function css() {
		return '
		/* إخفاء السعر الافتراضي لمنتج الوزن فقط (لا يؤثر على باقي المنتجات) */
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

		.threeabar-weight-wrap{margin:20px 0 26px}
		.threeabar-weight-title{display:block;margin-bottom:12px;font-weight:700;font-size:1.05rem;color:#3a2a0c}
		.threeabar-weight-options{display:flex;gap:12px;flex-wrap:wrap}
		.threeabar-weight-btn{display:inline-flex;flex-direction:column;align-items:center;gap:3px;padding:12px 24px;border:2px solid #ead9b3;background:#fff;border-radius:16px;cursor:pointer;font-size:1rem;font-weight:600;color:#3a2a0c;transition:all .25s;min-width:96px}
		.threeabar-weight-btn:hover:not(:disabled){border-color:#e0a73c;transform:translateY(-2px);box-shadow:0 10px 22px -14px rgba(184,128,28,.6)}
		.threeabar-weight-btn .threeabar-weight-unit-price{font-size:.82rem;font-weight:700;color:#b97e16;opacity:.9}
		.threeabar-weight-btn.selected{background:linear-gradient(120deg,#b97e16,#e0a73c);color:#1a1206;border-color:#a16e12;box-shadow:0 10px 22px -10px rgba(184,128,28,.7)}
		.threeabar-weight-btn.selected .threeabar-weight-unit-price{color:#2a1e06}
		.threeabar-weight-btn:disabled{opacity:.4;cursor:not-allowed;text-decoration:line-through}
		';
	}

	/**
	 * سكربت منتج الوزن.
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
				var fixed = n.toFixed(dec);
				var parts = fixed.split('.');
				parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousandSep || ',');
				var num = parts.length > 1 ? parts[0] + (cfg.decimalSep || '.') + parts[1] : parts[0];
				var fmt = cfg.format || '%1$s%2$s';
				var html = fmt.replace('%1$s', '<span class="woocommerce-Price-currencySymbol">'+ (cfg.symbol||'') +'</span>').replace('%2$s', num);
				return '<span class="woocommerce-Price-amount amount"><bdi>'+ html +'</bdi></span>';
			}

			$(function(){
				var $form = $('form.variations_form');
				if(!$form.length){ return; }

				var $price   = $('.threeabar-dynamic-price');
				var $qty     = $form.find('input.qty');
				var current  = parseFloat($price.data('initial')) || 0;

				function selectBtn(slug){
					$('.threeabar-weight-btn').removeClass('selected');
					if(slug){ $('.threeabar-weight-btn[data-slug="'+ slug +'"]').addClass('selected'); }
				}

				function render(){
					var qty = parseFloat($qty.val()) || 1;
					if(current > 0){
						$price.removeClass('is-empty').html(formatMoney(current * qty));
					} else {
						$price.addClass('is-empty').text(cfg.i18n ? cfg.i18n.choose : '');
					}
				}

				// نقر زر الوزن: يضبط قائمة المتغيّرات الأصلية (للحفاظ على عمل الإضافة للسلة).
				$(document).on('click', '.threeabar-weight-btn:not(:disabled)', function(){
					var slug = $(this).data('slug');
					current  = parseFloat($(this).data('price')) || current; // تحديث فوري سريع.
					selectBtn(slug);
					$form.find('select[name="attribute_' + cfg.attr + '"]').val(slug).trigger('change');
					render();
				});

				// المصدر الموثوق للسعر عند تطابق المتغيّر.
				$form.on('found_variation', function(e, variation){
					if(!variation){ return; }
					current = parseFloat(variation.display_price);
					if(isNaN(current)){ current = parseFloat(variation.price) || 0; }
					selectBtn(variation.attributes['attribute_' + cfg.attr]);
					render();
				});

				$qty.on('change keyup input', render);

				$form.on('reset_data', function(){
					current = parseFloat($price.data('initial')) || 0;
					render();
				});

				// التشغيل الأولي مع تحديد الافتراضي أو أول وزن متاح.
				setTimeout(function(){
					var slug = $form.find('select[name="attribute_' + cfg.attr + '"]').val();
					if(!slug){ slug = $('.threeabar-weight-btn.selected').data('slug'); }
					if(!slug){ slug = $('.threeabar-weight-btn:not(:disabled)').first().data('slug'); }
					if(slug){
						$('.threeabar-weight-btn[data-slug="'+ slug +'"]').not(':disabled').trigger('click');
					} else {
						render();
					}
				}, 600);

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
	 * إدراج الوزن المختار ضمن هاش كاش الأسعار.
	 *
	 * @param array      $hash       الهاش.
	 * @param WC_Product $product    المنتج.
	 * @param bool       $for_display للعرض؟
	 * @return array
	 */
	public function price_hash( $hash, $product, $for_display ) {
		$key       = 'attribute_' . self::ATTR;
		$selected  = '';
		if ( isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$selected = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
		}
		$hash[] = $selected;
		return $hash;
	}

	/* =====================================================================
	 *  دوال مساعدة
	 * ===================================================================== */

	/**
	 * الحصول على المنتج الحالي بأمان.
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
	 * هل المنتج متغيّر ويستخدم خاصية الوزن؟
	 *
	 * @param WC_Product|null $product المنتج.
	 * @return bool
	 */
	private function is_weight_variable( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return false;
		}
		$attributes = $product->get_variation_attributes();
		return is_array( $attributes ) && array_key_exists( self::ATTR, $attributes );
	}

	/**
	 * بناء خريطة slug ⇒ متغيّر مرئي (تمنع التكرار وتُستخدم للأسعار والمخزون).
	 *
	 * @param WC_Product $product المنتج.
	 * @return array
	 */
	private function get_weight_variation_map( $product ) {
		$map = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_visible() ) {
				continue;
			}
			$slug = $variation->get_attribute( self::ATTR );
			if ( '' !== $slug && ! isset( $map[ $slug ] ) ) {
				$map[ $slug ] = $variation;
			}
		}
		return $map;
	}

	/**
	 * الوزن الافتراضي (slug) إن وُجد.
	 *
	 * @param WC_Product $product المنتج.
	 * @return string
	 */
	private function get_default_slug( $product ) {
		$defaults = $product->get_default_attributes();
		return ! empty( $defaults[ self::ATTR ] ) ? $defaults[ self::ATTR ] : '';
	}

	/**
	 * السعر الابتدائي: سعر الوزن الافتراضي إن وُجد، وإلا السعر الأدنى للعرض.
	 *
	 * @param WC_Product $product المنتج.
	 * @return float
	 */
	private function get_initial_price( $product ) {
		$slug = $this->get_default_slug( $product );
		if ( $slug ) {
			$map = $this->get_weight_variation_map( $product );
			if ( isset( $map[ $slug ] ) ) {
				return (float) wc_get_price_to_display( $map[ $slug ] );
			}
		}
		return (float) $product->get_variation_price( 'min', true );
	}
}

ThreeAbar_Weight_Pricing::instance();

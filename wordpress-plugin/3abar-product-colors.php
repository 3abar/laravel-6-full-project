<?php
/**
 * Plugin Name: 3abar Product Colors
 * Plugin URI:  https://3abar.com
 * Description: نظام ألوان مخصّص لمنتجات WooCommerce — عرّف ألوانك العامة من الإعدادات (درجة لون أو صورة) مع سعر إضافي اختياري لكل لون، ثم اختر الألوان المتاحة لكل منتج من لوحة المنتج مع إمكانية تخصيص صورة/سعر لكل لون. يعمل مع المنتج العادي، ومنتجات الوزن، ومع بلاجن الإضافات — ويُضيف سعر اللون تلقائيًا للسلة والطلب.
 * Version:     1.0.0
 * Author:      Shawky El Moazamy
 * Author URI:  https://3abar.com
 * Text Domain: 3abar-product-colors
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 *
 * @package ThreeAbar_Product_Colors
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * الكلاس الرئيسي لنظام الألوان المخصّص.
 */
final class ThreeAbar_Product_Colors {

	const OPTION        = 'threeabar_colors_settings';
	const META_ENABLED  = '_threeabar_colors_enabled';
	const META_REQUIRED = '_threeabar_colors_required';
	const META_LABEL    = '_threeabar_colors_label';
	const META_COLORS   = '_threeabar_colors';
	const NONCE_ACTION  = 'threeabar_colors_save';
	const CART_KEY      = 'threeabar_color';

	/**
	 * النسخة الوحيدة.
	 *
	 * @var ThreeAbar_Product_Colors|null
	 */
	private static $instance = null;

	/**
	 * الحصول على النسخة الوحيدة.
	 *
	 * @return ThreeAbar_Product_Colors
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

		// إعدادات عامة.
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ) );

		// لوحة المنتج.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_meta_box' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// الواجهة الأمامية.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_color_selector' ), 8 );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );

		// السلة والطلب.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_color' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_color_price' ), 25, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
	}

	/* =====================================================================
	 *  الإعدادات العامة (الألوان)
	 * ===================================================================== */

	/**
	 * قراءة الإعدادات.
	 *
	 * @return array
	 */
	public function get_settings() {
		$opt = get_option( self::OPTION, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		$opt['label']  = isset( $opt['label'] ) && '' !== $opt['label'] ? $opt['label'] : __( 'اختر اللون:', '3abar-product-colors' );
		$opt['colors'] = isset( $opt['colors'] ) && is_array( $opt['colors'] ) ? $opt['colors'] : array();
		return $opt;
	}

	/**
	 * خريطة الألوان العامة id ⇒ بيانات.
	 *
	 * @return array
	 */
	public function get_global_colors() {
		$settings = $this->get_settings();
		$map      = array();
		foreach ( $settings['colors'] as $color ) {
			if ( empty( $color['id'] ) ) {
				continue;
			}
			$map[ $color['id'] ] = wp_parse_args(
				$color,
				array(
					'id'    => '',
					'name'  => '',
					'type'  => 'shade',
					'color' => '#000000',
					'image' => 0,
					'price' => 0,
				)
			);
		}
		return $map;
	}

	/**
	 * تسجيل صفحة الإعدادات.
	 *
	 * @return void
	 */
	public function register_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'ألوان المنتجات (3abar)', '3abar-product-colors' ),
			__( '3abar الألوان', '3abar-product-colors' ),
			'manage_woocommerce',
			'threeabar-product-colors',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * تسجيل الإعداد + التعقيم.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'threeabar_colors_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * تعقيم إعدادات الألوان.
	 *
	 * @param array $input المدخلات.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array(
			'label'  => sanitize_text_field( $input['label'] ?? __( 'اختر اللون:', '3abar-product-colors' ) ),
			'colors' => array(),
		);

		$rows = isset( $input['colors'] ) && is_array( $input['colors'] ) ? $input['colors'] : array();
		foreach ( $rows as $row ) {
			$name = sanitize_text_field( $row['name'] ?? '' );
			if ( '' === $name ) {
				continue; // تجاهل الصفوف الفارغة.
			}
			$id   = ! empty( $row['id'] ) ? sanitize_key( $row['id'] ) : 'c' . uniqid();
			$type = ( isset( $row['type'] ) && 'image' === $row['type'] ) ? 'image' : 'shade';

			$output['colors'][] = array(
				'id'    => $id,
				'name'  => $name,
				'type'  => $type,
				'color' => sanitize_hex_color( $row['color'] ?? '' ) ? sanitize_hex_color( $row['color'] ) : '#000000',
				'image' => absint( $row['image'] ?? 0 ),
				'price' => wc_format_decimal( $row['price'] ?? 0 ),
			);
		}
		return $output;
	}

	/**
	 * رابط الإعدادات.
	 *
	 * @param array $links الروابط.
	 * @return array
	 */
	public function settings_link( $links ) {
		$url  = admin_url( 'admin.php?page=threeabar-product-colors' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'الإعدادات', '3abar-product-colors' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * عرض صفحة الإعدادات (جدول ألوان قابل للتكرار + رفع صور).
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$settings = $this->get_settings();
		wp_enqueue_media();
		?>
		<div class="wrap threeabar-colors-settings">
			<h1><?php esc_html_e( 'ألوان المنتجات — 3abar', '3abar-product-colors' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'threeabar_colors_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tc_label"><?php esc_html_e( 'المسمّى الافتراضي', '3abar-product-colors' ); ?></label></th>
						<td><input type="text" id="tc_label" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[label]" value="<?php echo esc_attr( $settings['label'] ); ?>" />
						<p class="description"><?php esc_html_e( 'يمكن تجاوزه لكل منتج من لوحة المنتج.', '3abar-product-colors' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'الألوان العامة', '3abar-product-colors' ); ?></h2>
				<p class="description"><?php esc_html_e( 'عرّف الألوان مرة واحدة هنا (درجة لون أو صورة + سعر إضافي اختياري)، ثم فعّلها لكل منتج.', '3abar-product-colors' ); ?></p>

				<table class="widefat threeabar-colors-table" style="max-width:980px;margin-top:10px">
					<thead>
						<tr>
							<th style="width:60px"><?php esc_html_e( 'معاينة', '3abar-product-colors' ); ?></th>
							<th><?php esc_html_e( 'الاسم', '3abar-product-colors' ); ?></th>
							<th style="width:120px"><?php esc_html_e( 'النوع', '3abar-product-colors' ); ?></th>
							<th style="width:90px"><?php esc_html_e( 'درجة اللون', '3abar-product-colors' ); ?></th>
							<th style="width:160px"><?php esc_html_e( 'الصورة', '3abar-product-colors' ); ?></th>
							<th style="width:120px"><?php esc_html_e( 'سعر إضافي', '3abar-product-colors' ); ?></th>
							<th style="width:50px"></th>
						</tr>
					</thead>
					<tbody id="threeabar-colors-rows">
						<?php
						$rows = $settings['colors'];
						if ( empty( $rows ) ) {
							$rows = array( array() ); // صف فارغ افتراضي.
						}
						foreach ( $rows as $index => $row ) {
							$this->render_settings_row( $index, $row );
						}
						?>
					</tbody>
				</table>
				<p><button type="button" class="button button-secondary" id="threeabar-add-color">+ <?php esc_html_e( 'إضافة لون', '3abar-product-colors' ); ?></button></p>

				<?php submit_button(); ?>
			</form>
		</div>

		<script type="text/template" id="threeabar-color-row-tpl">
			<?php $this->render_settings_row( '__INDEX__', array() ); ?>
		</script>
		<?php
	}

	/**
	 * عرض صفّ لون واحد في الإعدادات.
	 *
	 * @param int|string $index الفهرس.
	 * @param array      $row   بيانات اللون.
	 * @return void
	 */
	private function render_settings_row( $index, $row ) {
		$row   = wp_parse_args(
			$row,
			array(
				'id'    => '',
				'name'  => '',
				'type'  => 'shade',
				'color' => '#e0a73c',
				'image' => 0,
				'price' => '',
			)
		);
		$name  = esc_attr( self::OPTION ) . '[colors][' . esc_attr( $index ) . ']';
		$img_url = $row['image'] ? wp_get_attachment_image_url( $row['image'], 'thumbnail' ) : '';
		?>
		<tr class="threeabar-color-row">
			<td class="threeabar-prev-cell">
				<?php if ( $img_url ) : ?>
					<img class="threeabar-prev-img" src="<?php echo esc_url( $img_url ); ?>" style="width:38px;height:38px;border-radius:8px;object-fit:cover" />
				<?php else : ?>
					<span class="threeabar-prev-dot" style="display:inline-block;width:32px;height:32px;border-radius:50%;border:1px solid #ccc;background:<?php echo esc_attr( $row['color'] ); ?>"></span>
				<?php endif; ?>
			</td>
			<td><input type="text" name="<?php echo $name; // phpcs:ignore ?>[name]" value="<?php echo esc_attr( $row['name'] ); ?>" class="regular-text" /></td>
			<td>
				<input type="hidden" name="<?php echo $name; // phpcs:ignore ?>[id]" value="<?php echo esc_attr( $row['id'] ); ?>" />
				<select name="<?php echo $name; // phpcs:ignore ?>[type]" class="threeabar-type-select">
					<option value="shade" <?php selected( $row['type'], 'shade' ); ?>><?php esc_html_e( 'درجة لون', '3abar-product-colors' ); ?></option>
					<option value="image" <?php selected( $row['type'], 'image' ); ?>><?php esc_html_e( 'صورة', '3abar-product-colors' ); ?></option>
				</select>
			</td>
			<td><input type="color" name="<?php echo $name; // phpcs:ignore ?>[color]" value="<?php echo esc_attr( $row['color'] ? $row['color'] : '#e0a73c' ); ?>" /></td>
			<td class="threeabar-img-cell">
				<input type="hidden" class="threeabar-img-id" name="<?php echo $name; // phpcs:ignore ?>[image]" value="<?php echo esc_attr( $row['image'] ); ?>" />
				<button type="button" class="button threeabar-upload-img"><?php esc_html_e( 'اختر صورة', '3abar-product-colors' ); ?></button>
				<button type="button" class="button-link threeabar-remove-img" style="<?php echo $row['image'] ? '' : 'display:none'; ?>"><?php esc_html_e( 'حذف', '3abar-product-colors' ); ?></button>
			</td>
			<td><input type="number" step="0.01" min="0" name="<?php echo $name; // phpcs:ignore ?>[price]" value="<?php echo esc_attr( $row['price'] ); ?>" style="width:90px" placeholder="0" /></td>
			<td><button type="button" class="button-link threeabar-remove-row" style="color:#b32d2e">&times;</button></td>
		</tr>
		<?php
	}

	/* =====================================================================
	 *  لوحة المنتج (Meta Box)
	 * ===================================================================== */

	/**
	 * تسجيل الميتا بوكس.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		add_meta_box(
			'threeabar_colors_metabox',
			__( '🎨 ألوان 3abar', '3abar-product-colors' ),
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * عرض الميتا بوكس.
	 *
	 * @param WP_Post $post المنتج.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, 'threeabar_colors_nonce' );

		$enabled   = 'yes' === get_post_meta( $post->ID, self::META_ENABLED, true );
		$required  = 'yes' === get_post_meta( $post->ID, self::META_REQUIRED, true );
		$label     = get_post_meta( $post->ID, self::META_LABEL, true );
		$overrides = (array) get_post_meta( $post->ID, self::META_COLORS, true );
		$globals   = $this->get_global_colors();
		?>
		<div class="threeabar-colors-mb">
			<p>
				<label><input type="checkbox" name="<?php echo esc_attr( self::META_ENABLED ); ?>" value="yes" <?php checked( $enabled ); ?> /> <strong><?php esc_html_e( 'تفعيل اختيار اللون لهذا المنتج', '3abar-product-colors' ); ?></strong></label>
			</p>
			<p>
				<label><input type="checkbox" name="<?php echo esc_attr( self::META_REQUIRED ); ?>" value="yes" <?php checked( $required ); ?> /> <?php esc_html_e( 'اختيار اللون إجباري', '3abar-product-colors' ); ?></label>
			</p>
			<p>
				<label><?php esc_html_e( 'مسمّى مخصّص (اختياري):', '3abar-product-colors' ); ?>
					<input type="text" name="<?php echo esc_attr( self::META_LABEL ); ?>" value="<?php echo esc_attr( $label ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $this->get_settings()['label'] ); ?>" />
				</label>
			</p>

			<?php if ( empty( $globals ) ) : ?>
				<p style="color:#b32d2e">
					<?php
					printf(
						/* translators: %s settings url */
						wp_kses_post( __( 'لا توجد ألوان مُعرّفة بعد. أضِف ألوانك من <a href="%s">إعدادات الألوان</a>.', '3abar-product-colors' ) ),
						esc_url( admin_url( 'admin.php?page=threeabar-product-colors' ) )
					);
					?>
				</p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'اختر الألوان المتاحة لهذا المنتج، ويمكنك تخصيص سعر أو صورة لكل لون (اتركها فارغة لاستخدام الافتراضي).', '3abar-product-colors' ); ?></p>
				<table class="widefat" style="max-width:760px">
					<thead><tr>
						<th style="width:40px"></th>
						<th><?php esc_html_e( 'اللون', '3abar-product-colors' ); ?></th>
						<th style="width:130px"><?php esc_html_e( 'سعر مخصّص', '3abar-product-colors' ); ?></th>
						<th style="width:200px"><?php esc_html_e( 'صورة مخصّصة', '3abar-product-colors' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $globals as $id => $color ) :
						$is_on    = array_key_exists( $id, $overrides );
						$ov_price = $is_on && isset( $overrides[ $id ]['price'] ) ? $overrides[ $id ]['price'] : '';
						$ov_image = $is_on && ! empty( $overrides[ $id ]['image'] ) ? absint( $overrides[ $id ]['image'] ) : 0;
						$ov_url   = $ov_image ? wp_get_attachment_image_url( $ov_image, 'thumbnail' ) : '';
						$base     = 'image' === $color['type'] && $color['image'] ? wp_get_attachment_image_url( $color['image'], 'thumbnail' ) : '';
						$mb_name  = esc_attr( self::META_COLORS ) . '[' . esc_attr( $id ) . ']';
						?>
						<tr>
							<td><input type="checkbox" name="<?php echo $mb_name; // phpcs:ignore ?>[on]" value="1" <?php checked( $is_on ); ?> /></td>
							<td>
								<?php if ( $base ) : ?>
									<img src="<?php echo esc_url( $base ); ?>" style="width:26px;height:26px;border-radius:6px;object-fit:cover;vertical-align:middle;margin-inline-end:8px" />
								<?php else : ?>
									<span style="display:inline-block;width:22px;height:22px;border-radius:50%;border:1px solid #ccc;background:<?php echo esc_attr( $color['color'] ); ?>;vertical-align:middle;margin-inline-end:8px"></span>
								<?php endif; ?>
								<?php echo esc_html( $color['name'] ); ?>
								<?php if ( (float) $color['price'] > 0 ) : ?>
									<small style="color:#777">(+<?php echo esc_html( wp_strip_all_tags( wc_price( $color['price'] ) ) ); ?>)</small>
								<?php endif; ?>
							</td>
							<td><input type="number" step="0.01" min="0" name="<?php echo $mb_name; // phpcs:ignore ?>[price]" value="<?php echo esc_attr( $ov_price ); ?>" placeholder="<?php echo esc_attr( $color['price'] ); ?>" style="width:110px" /></td>
							<td class="threeabar-img-cell">
								<input type="hidden" class="threeabar-img-id" name="<?php echo $mb_name; // phpcs:ignore ?>[image]" value="<?php echo esc_attr( $ov_image ); ?>" />
								<span class="threeabar-img-prev"><?php echo $ov_url ? '<img src="' . esc_url( $ov_url ) . '" style="width:26px;height:26px;border-radius:6px;object-fit:cover;vertical-align:middle" />' : ''; ?></span>
								<button type="button" class="button threeabar-upload-img"><?php esc_html_e( 'صورة', '3abar-product-colors' ); ?></button>
								<button type="button" class="button-link threeabar-remove-img" style="<?php echo $ov_image ? '' : 'display:none'; ?>"><?php esc_html_e( 'حذف', '3abar-product-colors' ); ?></button>
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
	 * حفظ بيانات الميتا بوكس.
	 *
	 * @param int $post_id معرّف المنتج.
	 * @return void
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['threeabar_colors_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['threeabar_colors_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_ENABLED, ( isset( $_POST[ self::META_ENABLED ] ) && 'yes' === $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, self::META_REQUIRED, ( isset( $_POST[ self::META_REQUIRED ] ) && 'yes' === $_POST[ self::META_REQUIRED ] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, self::META_LABEL, isset( $_POST[ self::META_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_LABEL ] ) ) : '' );

		$globals = $this->get_global_colors();
		$raw     = isset( $_POST[ self::META_COLORS ] ) && is_array( $_POST[ self::META_COLORS ] ) ? wp_unslash( $_POST[ self::META_COLORS ] ) : array();
		$clean   = array();
		foreach ( $raw as $id => $data ) {
			$id = sanitize_key( $id );
			if ( ! isset( $globals[ $id ] ) || empty( $data['on'] ) ) {
				continue; // لا نحفظ إلا الألوان المُفعّلة والمعروفة.
			}
			$clean[ $id ] = array(
				'price' => ( isset( $data['price'] ) && '' !== $data['price'] ) ? wc_format_decimal( $data['price'] ) : '',
				'image' => isset( $data['image'] ) ? absint( $data['image'] ) : 0,
			);
		}
		update_post_meta( $post_id, self::META_COLORS, $clean );
	}

	/**
	 * أصول لوحة التحكم (مكتبة الوسائط + سكربت الرفع والصفوف).
	 *
	 * @param string $hook الصفحة.
	 * @return void
	 */
	public function admin_assets( $hook ) {
		$screen = get_current_screen();
		$is_product_edit = $screen && 'product' === $screen->post_type;
		$is_settings     = isset( $_GET['page'] ) && 'threeabar-product-colors' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $is_product_edit && ! $is_settings ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script( 'jquery', $this->admin_js() );
	}

	/**
	 * سكربت لوحة التحكم.
	 *
	 * @return string
	 */
	private function admin_js() {
		ob_start();
		?>
		(function($){
			$(function(){
				// رفع الصور (يعمل في الإعدادات وفي لوحة المنتج).
				$(document).on('click', '.threeabar-upload-img', function(e){
					e.preventDefault();
					var $btn = $(this);
					var $cell = $btn.closest('.threeabar-img-cell, td');
					var frame = wp.media({ title:'اختر صورة', multiple:false, library:{type:'image'} });
					frame.on('select', function(){
						var att = frame.state().get('selection').first().toJSON();
						var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
						$cell.find('.threeabar-img-id').val(att.id);
						if($cell.find('.threeabar-img-prev').length){
							$cell.find('.threeabar-img-prev').html('<img src="'+url+'" style="width:26px;height:26px;border-radius:6px;object-fit:cover;vertical-align:middle" />');
						}
						// معاينة في صفحة الإعدادات.
						var $row = $btn.closest('tr');
						$row.find('.threeabar-prev-cell').html('<img class="threeabar-prev-img" src="'+url+'" style="width:38px;height:38px;border-radius:8px;object-fit:cover" />');
						$cell.find('.threeabar-remove-img').show();
					});
					frame.open();
				});

				$(document).on('click', '.threeabar-remove-img', function(e){
					e.preventDefault();
					var $cell = $(this).closest('.threeabar-img-cell, td');
					$cell.find('.threeabar-img-id').val('');
					$cell.find('.threeabar-img-prev').html('');
					$(this).hide();
				});

				// إضافة صف لون في الإعدادات.
				$('#threeabar-add-color').on('click', function(){
					var tpl = $('#threeabar-color-row-tpl').html();
					var idx = 'n' + Date.now();
					$('#threeabar-colors-rows').append(tpl.replace(/__INDEX__/g, idx));
				});

				// حذف صف.
				$(document).on('click', '.threeabar-remove-row', function(){
					$(this).closest('tr').remove();
				});
			});
		})(jQuery);
		<?php
		return ob_get_clean();
	}

	/* =====================================================================
	 *  الواجهة الأمامية
	 * ===================================================================== */

	/**
	 * عرض محدّد الألوان داخل نموذج الإضافة للسلة.
	 *
	 * @return void
	 */
	public function render_color_selector() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$colors = $this->get_product_colors( $product->get_id() );
		if ( empty( $colors ) ) {
			return;
		}
		$label    = get_post_meta( $product->get_id(), self::META_LABEL, true );
		$label    = $label ? $label : $this->get_settings()['label'];
		$required = 'yes' === get_post_meta( $product->get_id(), self::META_REQUIRED, true );
		?>
		<div class="threeabar-colors-wrap" data-required="<?php echo $required ? '1' : '0'; ?>">
			<label class="threeabar-colors-title"><?php echo esc_html( $label ); ?><?php echo $required ? ' <span class="threeabar-req">*</span>' : ''; ?></label>
			<input type="hidden" name="<?php echo esc_attr( self::CART_KEY ); ?>" class="threeabar-color-input" value="" />
			<div class="threeabar-colors-options">
				<?php foreach ( $colors as $color ) :
					$img = ( 'image' === $color['type'] && $color['image'] ) ? wp_get_attachment_image_url( $color['image'], 'woocommerce_thumbnail' ) : '';
					$fee = (float) $color['price'];
					?>
					<button type="button" class="threeabar-color-btn<?php echo $img ? ' has-img' : ''; ?>"
							data-id="<?php echo esc_attr( $color['id'] ); ?>"
							data-price="<?php echo esc_attr( $fee ); ?>"
							title="<?php echo esc_attr( $color['name'] ); ?>">
						<span class="threeabar-color-swatch" style="<?php echo $img ? "background-image:url('" . esc_url( $img ) . "')" : 'background:' . esc_attr( $color['color'] ); ?>"></span>
						<span class="threeabar-color-name"><?php echo esc_html( $color['name'] ); ?></span>
						<span class="threeabar-color-fee"><?php echo $fee > 0 ? '+' . wp_kses_post( wc_price( $fee ) ) : esc_html__( 'مجاني', '3abar-product-colors' ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * تحميل أصول الواجهة.
	 *
	 * @return void
	 */
	public function frontend_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		global $post;
		if ( ! $post || empty( $this->get_product_colors( $post->ID ) ) ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		wp_register_style( '3abar-product-colors', false, array(), '1.0.0' );
		wp_enqueue_style( '3abar-product-colors' );
		wp_add_inline_style( '3abar-product-colors', $this->frontend_css() );
		wp_add_inline_script( 'jquery', $this->frontend_js(), 'after' );
	}

	/**
	 * أنماط الواجهة.
	 *
	 * @return string
	 */
	private function frontend_css() {
		return '
		.threeabar-colors-wrap{margin:18px 0 22px}
		.threeabar-colors-title{display:block;margin-bottom:12px;font-weight:700;font-size:1.05rem;color:#3a2a0c}
		.threeabar-colors-title .threeabar-req{color:#db2777}
		.threeabar-colors-options{display:flex;gap:12px;flex-wrap:wrap}
		.threeabar-color-btn{display:inline-flex;flex-direction:column;align-items:center;gap:6px;padding:10px 14px;border:2px solid #ead9b3;background:#fff;border-radius:14px;cursor:pointer;transition:all .25s;min-width:84px}
		.threeabar-color-btn:hover{border-color:#e0a73c;transform:translateY(-2px);box-shadow:0 10px 22px -14px rgba(184,128,28,.55)}
		.threeabar-color-btn.selected{border-color:#a16e12;box-shadow:0 10px 22px -10px rgba(184,128,28,.6);background:linear-gradient(180deg,#fffaf0,#fdf3da)}
		.threeabar-color-swatch{width:46px;height:46px;border-radius:50%;background-size:cover;background-position:center;border:2px solid rgba(0,0,0,.08);box-shadow:inset 0 0 0 2px #fff}
		.threeabar-color-btn.selected .threeabar-color-swatch{box-shadow:inset 0 0 0 2px #fff,0 0 0 3px #e0a73c}
		.threeabar-color-name{font-size:.9rem;font-weight:600;color:#3a2a0c}
		.threeabar-color-fee{font-size:.78rem;font-weight:700;color:#b97e16}
		';
	}

	/**
	 * سكربت الواجهة.
	 *
	 * @return string
	 */
	private function frontend_js() {
		ob_start();
		?>
		(function($){
			'use strict';
			$(function(){
				var $wrap = $('.threeabar-colors-wrap');
				if(!$wrap.length){ return; }
				var $input = $wrap.find('.threeabar-color-input');
				var required = $wrap.data('required') == 1;
				var $addBtn = $('form.cart').find('.single_add_to_cart_button');

				window.ThreeAbarColorSurcharge = 0;

				function refreshAddBtn(){
					if(required && !$input.val()){
						$addBtn.addClass('disabled').attr('aria-disabled','true');
					} else {
						$addBtn.removeClass('disabled').removeAttr('aria-disabled');
					}
				}

				$(document).on('click', '.threeabar-color-btn', function(){
					var $b = $(this);
					$('.threeabar-color-btn').removeClass('selected');
					$b.addClass('selected');
					$input.val($b.data('id'));
					window.ThreeAbarColorSurcharge = parseFloat($b.data('price')) || 0;
					// إعلام بلاجن الوزن (وأي مستمع) لإعادة حساب السعر الحي.
					$(document.body).trigger('threeabar_color_changed');
					refreshAddBtn();
				});

				// إعادة تطبيق التعطيل بعد أحداث المتغيّرات في WooCommerce.
				$('form.variations_form').on('found_variation show_variation woocommerce_variation_has_changed', function(){
					setTimeout(refreshAddBtn, 0);
				});

				refreshAddBtn();
			});
		})(jQuery);
		<?php
		return ob_get_clean();
	}

	/* =====================================================================
	 *  السلة والطلب
	 * ===================================================================== */

	/**
	 * منع الإضافة عند كون اللون إجباريًا وغير مُختار.
	 *
	 * @param bool $passed     النتيجة.
	 * @param int  $product_id المنتج.
	 * @param int  $quantity   الكمية.
	 * @return bool
	 */
	public function validate_color( $passed, $product_id, $quantity ) {
		if ( 'yes' !== get_post_meta( $product_id, self::META_REQUIRED, true ) ) {
			return $passed;
		}
		if ( empty( $this->get_product_colors( $product_id ) ) ) {
			return $passed;
		}
		$selected = isset( $_REQUEST[ self::CART_KEY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::CART_KEY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $selected ) {
			wc_add_notice( __( 'الرجاء اختيار اللون أولًا.', '3abar-product-colors' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * تخزين اللون المختار في بيانات عنصر السلة.
	 *
	 * @param array $cart_item_data البيانات.
	 * @param int   $product_id     المنتج.
	 * @param int   $variation_id   المتغيّر.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( isset( $cart_item_data[ self::CART_KEY ] ) ) {
			return $cart_item_data; // مُمرّر مسبقًا (مثلًا من بلاجن الإضافات).
		}
		$selected = isset( $_REQUEST[ self::CART_KEY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::CART_KEY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $selected ) {
			$cart_item_data[ self::CART_KEY ] = $selected;
		}
		return $cart_item_data;
	}

	/**
	 * إضافة سعر اللون لسعر عنصر السلة.
	 *
	 * @param WC_Cart $cart السلة.
	 * @return void
	 */
	public function apply_color_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::CART_KEY ] ) ) {
				continue;
			}
			$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			$color      = $this->resolve_color( $product_id, $cart_item[ self::CART_KEY ] );
			if ( ! $color || (float) $color['price'] <= 0 ) {
				continue;
			}
			$current = (float) $cart_item['data']->get_price( 'edit' );
			$cart_item['data']->set_price( $current + (float) $color['price'] );
		}
	}

	/**
	 * عرض اللون في السلة/الدفع.
	 *
	 * @param array $item_data البيانات.
	 * @param array $cart_item عنصر السلة.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}
		$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
		$color      = $this->resolve_color( $product_id, $cart_item[ self::CART_KEY ] );
		if ( ! $color ) {
			return $item_data;
		}
		// عيّنة لون مصغّرة بجانب الاسم.
		if ( 'image' === $color['type'] && $color['image'] ) {
			$img    = wp_get_attachment_image_url( $color['image'], 'thumbnail' );
			$swatch = '<img src="' . esc_url( $img ) . '" style="width:20px;height:20px;border-radius:50%;object-fit:cover;vertical-align:middle;display:inline-block;margin-inline-end:6px" />';
		} else {
			$swatch = '<span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:' . esc_attr( $color['color'] ) . ';border:1px solid rgba(0,0,0,.15);vertical-align:middle;margin-inline-end:6px"></span>';
		}

		$display = $swatch . '<strong style="color:#8a5a12">' . esc_html( $color['name'] ) . '</strong>';
		if ( (float) $color['price'] > 0 ) {
			$display .= ' <span style="background:#2c7a4d;color:#fff;border-radius:20px;padding:1px 9px;font-size:12px;font-weight:700">+' . wp_strip_all_tags( wc_price( $color['price'] ) ) . '</span>';
		}

		$item_data[] = array(
			'key'     => __( 'اللون', '3abar-product-colors' ),
			'display' => $display,
			'value'   => $color['name'],
		);
		return $item_data;
	}

	/**
	 * حفظ اللون في سطر الطلب.
	 *
	 * @param WC_Order_Item_Product $item          السطر.
	 * @param string                $cart_item_key المفتاح.
	 * @param array                 $values        بيانات العنصر.
	 * @param WC_Order              $order         الطلب.
	 * @return void
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values[ self::CART_KEY ] ) ) {
			return;
		}
		$product_id = isset( $values['product_id'] ) ? absint( $values['product_id'] ) : 0;
		$color      = $this->resolve_color( $product_id, $values[ self::CART_KEY ] );
		if ( ! $color ) {
			return;
		}
		$value = $color['name'];
		if ( (float) $color['price'] > 0 ) {
			$value .= ' (+' . wp_strip_all_tags( wc_price( $color['price'] ) ) . ')';
		}
		$item->add_meta_data( __( 'اللون', '3abar-product-colors' ), $value, true );
	}

	/* =====================================================================
	 *  دوال مساعدة
	 * ===================================================================== */

	/**
	 * ألوان منتج معيّن (مدموجة مع التخصيصات).
	 *
	 * @param int $product_id المنتج.
	 * @return array id ⇒ بيانات اللون النهائية.
	 */
	public function get_product_colors( $product_id ) {
		if ( 'yes' !== get_post_meta( $product_id, self::META_ENABLED, true ) ) {
			return array();
		}
		$overrides = (array) get_post_meta( $product_id, self::META_COLORS, true );
		$globals   = $this->get_global_colors();
		$out       = array();
		foreach ( $globals as $id => $color ) {
			if ( ! array_key_exists( $id, $overrides ) ) {
				continue;
			}
			$ov    = $overrides[ $id ];
			$price = ( isset( $ov['price'] ) && '' !== $ov['price'] ) ? (float) $ov['price'] : (float) $color['price'];
			$image = ! empty( $ov['image'] ) ? absint( $ov['image'] ) : absint( $color['image'] );
			$type  = $image ? ( ! empty( $ov['image'] ) ? 'image' : $color['type'] ) : 'shade';

			$out[ $id ] = array(
				'id'    => $id,
				'name'  => $color['name'],
				'type'  => $type,
				'color' => $color['color'],
				'image' => $image,
				'price' => $price,
			);
		}
		return $out;
	}

	/**
	 * حل لون واحد لمنتج.
	 *
	 * @param int    $product_id المنتج.
	 * @param string $color_id   معرّف اللون.
	 * @return array|null
	 */
	private function resolve_color( $product_id, $color_id ) {
		$colors   = $this->get_product_colors( $product_id );
		$color_id = sanitize_key( $color_id );
		return isset( $colors[ $color_id ] ) ? $colors[ $color_id ] : null;
	}
}

ThreeAbar_Product_Colors::instance();

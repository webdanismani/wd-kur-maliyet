<?php
/**
 * Plugin Name:       WD Kur Fiyat – Kur/Maliyet Bazlı Otomatik Fiyatlama
 * Plugin URI:        https://oblifex.com
 * Description:       Ürün maliyetini döviz (USD/EUR/GBP…) olarak girin; TCMB kuruna, kâr marjına ve yuvarlama kurallarınıza göre WooCommerce fiyatları otomatik güncellensin. Kur sıçrama koruması, onay kuyruğu, planlı zam + sepet bildirimi ve fiyat geçmişi içerir.
 * Version:           1.0.0
 * Author:            Web Danışmanı
 * Author URI:        https://webdanismani.com
 * Text Domain:       wd-kur-fiyat
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 * License:           GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'WDKF_VERSION', '1.0.0' );
define( 'WDKF_FILE', __FILE__ );
define( 'WDKF_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDKF_URL', plugin_dir_url( __FILE__ ) );
define( 'WDKF_SUPPORT_URL', 'https://oblifex.com' );
define( 'WDKF_REPO_URL', 'https://github.com/webdanismani/wd-kur-maliyet' );

// Eksik yükleme koruması: dosyalar eksikse (ör. GitHub web yüklemesinde klasörler atlanmışsa)
// site çökmez; eklenti çalışmaz ve yöneticiye yeniden kurulum uyarısı gösterilir.
$wdkf_required = array(
	'includes/class-wdkf-settings.php',
	'includes/class-wdkf-install.php',
	'includes/class-wdkf-rates.php',
	'includes/class-wdkf-engine.php',
	'includes/class-wdkf-product.php',
	'includes/class-wdkf-frontend.php',
	'includes/class-wdkf-admin.php',
	'assets/css/admin.css',
	'assets/css/frontend.css',
	'assets/js/admin.js',
	'assets/js/product.js',
);
$wdkf_missing  = array();
foreach ( $wdkf_required as $wdkf_file ) {
	if ( ! is_readable( WDKF_DIR . $wdkf_file ) ) {
		$wdkf_missing[] = $wdkf_file;
	}
}
if ( $wdkf_missing ) {
	add_action(
		'admin_notices',
		function () use ( $wdkf_missing ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			$list = implode( ', ', array_slice( $wdkf_missing, 0, 5 ) ) . ( count( $wdkf_missing ) > 5 ? ' …' : '' );
			printf(
				'<div class="notice notice-error"><p><strong>WD Kur Fiyat eksik yüklenmiş, bu yüzden çalıştırılmadı.</strong> Bulunamayan dosyalar: <code>%s</code></p><p>Eklentiyi silip <a href="%s" target="_blank" rel="noopener">GitHub sayfasından</a> (Code → Download ZIP) ya da <a href="%s" target="_blank" rel="noopener">oblifex.com</a> üzerindeki paketle yeniden kurun.</p></div>',
				esc_html( $list ),
				esc_url( WDKF_REPO_URL ),
				esc_url( WDKF_SUPPORT_URL )
			);
		}
	);
	return;
}
foreach ( $wdkf_required as $wdkf_file ) {
	if ( '.php' === substr( $wdkf_file, -4 ) ) {
		require_once WDKF_DIR . $wdkf_file;
	}
}
unset( $wdkf_required, $wdkf_missing, $wdkf_file );

register_activation_hook( __FILE__, array( 'WDKF_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WDKF_Install', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>WD Kur Fiyat</strong> çalışmak için WooCommerce eklentisine ihtiyaç duyar.</p></div>';
				}
			);
			return;
		}

		WDKF_Install::maybe_upgrade();
		WDKF_Engine::init();
		new WDKF_Frontend();

		if ( is_admin() ) {
			new WDKF_Product();
			new WDKF_Admin();
		}
	},
	20
);

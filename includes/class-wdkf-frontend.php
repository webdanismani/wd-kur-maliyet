<?php
defined( 'ABSPATH' ) || exit;

/**
 * Mağaza tarafı: planlı zam uyarısı, kur notu, [wdkf_kur] kısa kodu.
 */
class WDKF_Frontend {

	private $done = false;

	public function __construct() {
		add_action( 'woocommerce_single_product_summary', array( $this, 'notice' ), 11 );
		add_filter( 'render_block', array( $this, 'block_notice' ), 10, 3 );
		add_shortcode( 'wdkf_kur', array( $this, 'shortcode_rate' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_style( 'wdkf-front', WDKF_URL . 'assets/css/frontend.css', array(), WDKF_VERSION );
		}
	}

	/** Ürünün (veya varyasyonlarının) en yakın planlı zam tarihi. */
	private static function scheduled_for( WC_Product $product ) {
		global $wpdb;
		$ids = array( $product->get_id() );
		if ( $product->is_type( 'variable' ) ) {
			$ids = array_merge( $ids, $product->get_children() );
		}
		$ids = implode( ',', array_map( 'intval', $ids ) );
		$t   = $wpdb->prefix . 'wdkf_changes';
		return $wpdb->get_var( "SELECT MIN(scheduled_at) FROM {$t} WHERE status = 'scheduled' AND product_id IN ({$ids})" ); // phpcs:ignore
	}

	public static function html( WC_Product $product ) {
		$s   = WDKF_Settings::all();
		$out = '';
		if ( 'yes' === $s['product_notice'] && (int) $s['increase_delay_hours'] > 0 ) {
			$when = self::scheduled_for( $product );
			if ( $when && strtotime( $when . ' UTC' ) > time() ) {
				$text = str_replace( '{tarih}', get_date_from_gmt( $when, 'd.m.Y H:i' ), $s['notice_text'] );
				$out .= '<p class="wdkf-notice" role="status"><span class="wdkf-notice__dot"></span>' . esc_html( $text ) . '</p>';
			}
		}
		if ( 'yes' === $s['show_rate_note'] ) {
			$has = 'yes' === get_post_meta( $product->get_id(), '_wdkf_enabled', true );
			if ( ! $has && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $vid ) {
					if ( 'yes' === get_post_meta( $vid, '_wdkf_enabled', true ) ) {
						$has = true;
						break;
					}
				}
			}
			if ( $has ) {
				$out .= '<p class="wdkf-rate-note">' . esc_html( $s['rate_note_text'] ) . '</p>';
			}
		}
		return $out;
	}

	public function notice() {
		global $product;
		if ( $this->done || ! $product instanceof WC_Product ) {
			return;
		}
		$h = self::html( $product );
		if ( $h ) {
			$this->done = true;
			echo $h; // phpcs:ignore
		}
	}

	public function block_notice( $content, $block, $instance = null ) {
		if ( $this->done || empty( $block['blockName'] ) || 'woocommerce/product-price' !== $block['blockName'] || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $content;
		}
		$pid = ( $instance && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : get_the_ID();
		if ( $pid !== (int) get_queried_object_id() ) {
			return $content;
		}
		$p = wc_get_product( $pid );
		$h = $p ? self::html( $p ) : '';
		if ( $h ) {
			$this->done = true;
			$content   .= $h;
		}
		return $content;
	}

	/** [wdkf_kur currency="USD" decimals="4"] → güncel kur. */
	public function shortcode_rate( $atts ) {
		$a    = shortcode_atts(
			array(
				'currency' => 'USD',
				'decimals' => 4,
				'buffer'   => 'no',
			),
			$atts,
			'wdkf_kur'
		);
		$cur  = strtoupper( sanitize_key( $a['currency'] ) );
		$rate = 'yes' === $a['buffer'] ? WDKF_Rates::effective( $cur ) : WDKF_Rates::base( $cur );
		if ( $rate <= 0 ) {
			return '';
		}
		return '<span class="wdkf-rate" data-currency="' . esc_attr( $cur ) . '">' . esc_html( number_format_i18n( $rate, max( 0, min( 6, (int) $a['decimals'] ) ) ) ) . '</span>';
	}
}

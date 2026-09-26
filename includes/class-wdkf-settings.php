<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ayarlar, yuvarlama kuralları ve para birimleri.
 */
class WDKF_Settings {

	const OPTION = 'wdkf_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			// Kur.
			'auto_update'          => 'yes',
			'rate_field'           => 'ForexSelling',
			'currencies'           => array( 'USD', 'EUR', 'GBP' ),
			'rate_buffer'          => 0,
			'manual_rates'         => array(),

			// Fiyatlama.
			'default_margin'       => 30,
			'default_rounding'     => 'int',
			'default_extra'        => 0,
			'vat_add'              => 0,
			'scale_sale'           => 'yes',
			'category_rules'       => array(),

			// Güvenlik.
			'decrease_policy'      => 'threshold',
			'decrease_threshold'   => 3,
			'approval_threshold'   => 15,
			'rate_guard'           => 5,

			// Planlı zam.
			'increase_delay_hours' => 0,
			'notify_cart'          => 'yes',
			'product_notice'       => 'yes',
			'notice_text'          => 'Bu ürünün fiyatı {tarih} itibarıyla güncellenecek. Mevcut fiyattan yararlanmak için siparişinizi şimdi verin.',
			'show_rate_note'       => 'no',
			'rate_note_text'       => 'Fiyatlar güncel döviz kuruna göre otomatik güncellenir.',

			'delete_on_uninstall'  => 'no',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$a = self::all();
		return isset( $a[ $key ] ) ? $a[ $key ] : null;
	}

	public static function yes( $key ) {
		return 'yes' === self::get( $key );
	}

	public static function save( array $v ) {
		update_option( self::OPTION, $v );
		self::$cache = null;
	}

	/** TCMB'nin yayımladığı başlıca para birimleri. */
	public static function supported_currencies() {
		return array(
			'USD' => 'ABD Doları',
			'EUR' => 'Euro',
			'GBP' => 'İngiliz Sterlini',
			'CHF' => 'İsviçre Frangı',
			'JPY' => 'Japon Yeni',
			'CNY' => 'Çin Yuanı',
			'CAD' => 'Kanada Doları',
			'AUD' => 'Avustralya Doları',
			'SAR' => 'Suudi Arabistan Riyali',
			'AED' => 'BAE Dirhemi',
			'RUB' => 'Rus Rublesi',
			'SEK' => 'İsveç Kronu',
			'NOK' => 'Norveç Kronu',
			'DKK' => 'Danimarka Kronu',
		);
	}

	public static function currency_symbol( $cur ) {
		$map = array(
			'TRY' => '₺',
			'USD' => '$',
			'EUR' => '€',
			'GBP' => '£',
			'CHF' => 'CHF ',
			'JPY' => '¥',
			'CNY' => '¥',
		);
		return isset( $map[ $cur ] ) ? $map[ $cur ] : $cur . ' ';
	}

	public static function rounding_options() {
		return array(
			'none' => 'Yuvarlama yok (1.234,56)',
			'int'  => 'Tam sayıya yukarı (1.235)',
			'x9'   => 'Sonu 9 (1.239)',
			'x90'  => 'Sonu 90 (1.290)',
			'x99'  => 'Sonu 99 (1.299)',
			'd99'  => 'Kuruşu ,99 (1.234,99)',
			'5'    => '5\'in katına yukarı (1.235)',
			'10'   => '10\'un katına yukarı (1.240)',
			'50'   => '50\'nin katına yukarı (1.250)',
			'100'  => '100\'ün katına yukarı (1.300)',
		);
	}

	public static function rate_fields() {
		return array(
			'ForexSelling'    => 'Döviz satış',
			'ForexBuying'     => 'Döviz alış',
			'BanknoteSelling' => 'Efektif satış',
			'BanknoteBuying'  => 'Efektif alış',
		);
	}
}

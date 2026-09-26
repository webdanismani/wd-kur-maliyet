<?php
defined( 'ABSPATH' ) || exit;

/**
 * Döviz kurları: TCMB today.xml, elle geçersiz kılma, kur tamponu, sıçrama koruması, geçmiş.
 *
 * Tüm iç kurlar "1 birim döviz = X TL" biçimindedir.
 */
class WDKF_Rates {

	const TCMB_URL   = 'https://www.tcmb.gov.tr/kurlar/today.xml';
	const OPT_TCMB   = 'wdkf_tcmb';
	const OPT_APPLY  = 'wdkf_applied_rates';
	const OPT_ERROR  = 'wdkf_rate_error';

	/**
	 * TCMB'den kurları çeker.
	 *
	 * @return array|WP_Error Değişen para birimleri listesi.
	 */
	public static function fetch() {
		$url  = apply_filters( 'wdkf_tcmb_url', self::TCMB_URL );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WD-Kur-Fiyat/' . WDKF_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return self::fail( 'TCMB bağlantı hatası: ' . $resp->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return self::fail( 'TCMB yanıt kodu: ' . wp_remote_retrieve_response_code( $resp ) );
		}

		$parsed = self::parse( wp_remote_retrieve_body( $resp ) );
		if ( is_wp_error( $parsed ) ) {
			return self::fail( $parsed->get_error_message() );
		}

		$before = self::effective_map();
		update_option(
			self::OPT_TCMB,
			array(
				'date'    => $parsed['date'],
				'fetched' => time(),
				'rates'   => $parsed['rates'],
			),
			false
		);
		delete_option( self::OPT_ERROR );

		return self::record_changes( $before, 'tcmb', $parsed['date'] );
	}

	private static function fail( $msg ) {
		update_option(
			self::OPT_ERROR,
			array(
				'message' => $msg,
				'time'    => time(),
			),
			false
		);
		return new WP_Error( 'wdkf_rate', $msg );
	}

	/** TCMB XML'ini ayrıştırır; seçili alan boşsa döviz satışa düşer. Birim (ör. JPY 100) dikkate alınır. */
	public static function parse( $xml_string ) {
		if ( ! $xml_string || false === strpos( $xml_string, '<Currency' ) ) {
			return new WP_Error( 'wdkf_xml', 'TCMB yanıtı beklenen biçimde değil.' );
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_use_internal_errors( $prev );
		if ( ! $xml ) {
			return new WP_Error( 'wdkf_xml', 'TCMB XML okunamadı.' );
		}

		$field = WDKF_Settings::get( 'rate_field' );
		$rates = array();
		foreach ( $xml->Currency as $c ) { // phpcs:ignore
			$code = strtoupper( (string) $c['CurrencyCode'] );
			$unit = max( 1, (int) $c->Unit ); // phpcs:ignore
			$val  = (string) $c->{$field};
			if ( '' === trim( $val ) ) {
				$val = (string) $c->ForexSelling; // phpcs:ignore
			}
			$num = (float) str_replace( ',', '.', $val );
			if ( $code && $num > 0 ) {
				$rates[ $code ] = round( $num / $unit, 6 );
			}
		}
		if ( ! $rates ) {
			return new WP_Error( 'wdkf_xml', 'TCMB yanıtında kur bulunamadı.' );
		}
		return array(
			'date'  => (string) $xml['Tarih'],
			'rates' => $rates,
		);
	}

	/** Değişen etkin kurları geçmiş tablosuna yazar; değişen para birimlerini döndürür. */
	public static function record_changes( array $before, $source, $date = '' ) {
		global $wpdb;
		$changed = array();
		$d       = $date ? DateTime::createFromFormat( 'd.m.Y', $date ) : false;
		foreach ( self::effective_map() as $cur => $rate ) {
			$last = self::last_recorded( $cur );
			if ( null === $last || abs( $last - $rate ) > 0.0000005 ) {
				$wpdb->insert(
					$wpdb->prefix . 'wdkf_rates',
					array(
						'currency'   => $cur,
						'rate'       => $rate,
						'source'     => self::is_manual( $cur ) ? 'manual' : $source,
						'rate_date'  => $d ? $d->format( 'Y-m-d' ) : null,
						'fetched_at' => current_time( 'mysql', true ),
					)
				);
			}
			if ( ! isset( $before[ $cur ] ) || abs( $before[ $cur ] - $rate ) > 0.0000005 ) {
				$changed[] = $cur;
			}
		}
		return $changed;
	}

	private static function last_recorded( $cur ) {
		global $wpdb;
		$t = $wpdb->prefix . 'wdkf_rates';
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT rate FROM {$t} WHERE currency = %s ORDER BY id DESC LIMIT 1", $cur ) ); // phpcs:ignore
		return null === $v ? null : (float) $v;
	}

	/* --------------------------------------------------------------------
	 * Okuma
	 * ------------------------------------------------------------------ */

	public static function tcmb() {
		$t = get_option( self::OPT_TCMB, array() );
		return is_array( $t ) ? $t : array();
	}

	/** Tampon uygulanmamış kaynak kur (elle girilen varsa o). */
	public static function base( $cur ) {
		$cur = strtoupper( $cur );
		if ( 'TRY' === $cur ) {
			return 1.0;
		}
		$manual = (array) WDKF_Settings::get( 'manual_rates' );
		if ( ! empty( $manual[ $cur ] ) && (float) $manual[ $cur ] > 0 ) {
			return (float) $manual[ $cur ];
		}
		$t = self::tcmb();
		return isset( $t['rates'][ $cur ] ) ? (float) $t['rates'][ $cur ] : 0.0;
	}

	public static function is_manual( $cur ) {
		$manual = (array) WDKF_Settings::get( 'manual_rates' );
		return ! empty( $manual[ $cur ] ) && (float) $manual[ $cur ] > 0;
	}

	/** Fiyatlamada kullanılan kur (tampon dahil). */
	public static function effective( $cur ) {
		$cur = strtoupper( $cur );
		if ( 'TRY' === $cur ) {
			return 1.0;
		}
		$b = self::base( $cur );
		return $b > 0 ? round( $b * ( 1 + (float) WDKF_Settings::get( 'rate_buffer' ) / 100 ), 6 ) : 0.0;
	}

	public static function effective_map() {
		$out = array();
		foreach ( (array) WDKF_Settings::get( 'currencies' ) as $cur ) {
			$r = self::effective( $cur );
			if ( $r > 0 ) {
				$out[ $cur ] = $r;
			}
		}
		return $out;
	}

	/**
	 * 1 birim $cur kaç birim mağaza para birimi eder.
	 *
	 * @param array $override Simülasyon için [cur => TL kuru] (tampon dahil kabul edilir).
	 */
	public static function to_store( $cur, array $override = array() ) {
		$cur   = strtoupper( $cur );
		$store = get_woocommerce_currency();
		if ( $cur === $store ) {
			return 1.0;
		}
		$get = function ( $c ) use ( $override ) {
			if ( 'TRY' === $c ) {
				return 1.0;
			}
			return isset( $override[ $c ] ) && (float) $override[ $c ] > 0 ? (float) $override[ $c ] : self::effective( $c );
		};
		$from = $get( $cur );
		$to   = $get( $store );
		if ( $from <= 0 || $to <= 0 ) {
			return 0.0;
		}
		return $from / $to;
	}

	/* --------------------------------------------------------------------
	 * Sıçrama koruması
	 * ------------------------------------------------------------------ */

	public static function applied() {
		$a = get_option( self::OPT_APPLY, array() );
		return is_array( $a ) ? $a : array();
	}

	/** Son uygulanan kura göre % değişim. */
	public static function jump_pct( $cur ) {
		$a = self::applied();
		$e = self::effective( $cur );
		if ( empty( $a[ $cur ] ) || $e <= 0 ) {
			return 0.0;
		}
		return ( $e - (float) $a[ $cur ] ) / (float) $a[ $cur ] * 100;
	}

	public static function guard_tripped( $cur ) {
		$g = (float) WDKF_Settings::get( 'rate_guard' );
		if ( $g <= 0 || 'TRY' === $cur ) {
			return false;
		}
		$a = self::applied();
		if ( empty( $a[ $cur ] ) ) {
			return false;
		}
		return abs( self::jump_pct( $cur ) ) > $g;
	}

	public static function guarded_currencies() {
		$out = array();
		foreach ( (array) WDKF_Settings::get( 'currencies' ) as $cur ) {
			if ( self::guard_tripped( $cur ) ) {
				$out[] = $cur;
			}
		}
		return $out;
	}

	/** Koruma tetiklenmemiş (veya zorla onaylanmış) kurları "uygulanan" olarak işaretler. */
	public static function mark_applied( array $currencies = array(), $force = false ) {
		$a    = self::applied();
		$list = $currencies ? $currencies : (array) WDKF_Settings::get( 'currencies' );
		foreach ( $list as $cur ) {
			$e = self::effective( $cur );
			if ( $e <= 0 ) {
				continue;
			}
			if ( $force || empty( $a[ $cur ] ) || ! self::guard_tripped( $cur ) ) {
				$a[ $cur ] = $e;
			}
		}
		update_option( self::OPT_APPLY, $a, false );
	}

	/* --------------------------------------------------------------------
	 * Geçmiş
	 * ------------------------------------------------------------------ */

	public static function history( $cur, $limit = 30 ) {
		global $wpdb;
		$t = $wpdb->prefix . 'wdkf_rates';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE currency = %s ORDER BY id DESC LIMIT %d", $cur, $limit ), ARRAY_A ); // phpcs:ignore
	}

	/** Bir önceki kayıtlı kur (panelde değişim oku için). */
	public static function previous( $cur ) {
		$h = self::history( $cur, 2 );
		return isset( $h[1] ) ? (float) $h[1]['rate'] : null;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fiyat motoru.
 *
 * Formül (maliyet modu):
 *   fiyat = yuvarla( ( maliyet × kur + ek maliyet ) × (1 + marj) × (1 + KDV) )
 * Döviz fiyat modu:
 *   fiyat = yuvarla( döviz fiyatı × kur × (1 + KDV) )
 *
 * Otomatik çalışmada güvenlik katmanları sırasıyla:
 *   indirim politikası → kur sıçrama koruması → onay eşiği → planlı zam (gecikme)
 */
class WDKF_Engine {

	const T_CHANGES = 'wdkf_changes';

	/** Değişen varyasyonların ebeveynleri; istek sonunda tek sefer senkronlanır. */
	private static $sync_parents = array();

	public static function init() {
		add_action( WDKF_Install::CRON_HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'wdkf_batch', array( __CLASS__, 'run_batch' ), 10, 2 );
		add_action( 'shutdown', array( __CLASS__, 'sync_parents' ) );
	}

	/* ====================================================================
	 * Ürün yapılandırması ve kurallar
	 * ================================================================== */

	public static function meta_keys() {
		return array( '_wdkf_enabled', '_wdkf_mode', '_wdkf_amount', '_wdkf_currency', '_wdkf_extra', '_wdkf_margin', '_wdkf_rounding' );
	}

	/** @return array|null */
	public static function config( $product ) {
		$product = wc_get_product( $product );
		if ( ! $product || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			return null;
		}
		$id = $product->get_id();
		if ( 'yes' !== get_post_meta( $id, '_wdkf_enabled', true ) ) {
			return null;
		}
		$amount = (float) get_post_meta( $id, '_wdkf_amount', true );
		if ( $amount <= 0 ) {
			return null;
		}
		$margin   = get_post_meta( $id, '_wdkf_margin', true );
		$extra    = get_post_meta( $id, '_wdkf_extra', true );
		$currency = strtoupper( (string) get_post_meta( $id, '_wdkf_currency', true ) );
		return array(
			'mode'     => 'fx' === get_post_meta( $id, '_wdkf_mode', true ) ? 'fx' : 'cost',
			'amount'   => $amount,
			'currency' => $currency ? $currency : 'USD',
			'extra'    => '' === $extra ? null : (float) $extra,
			'margin'   => '' === $margin ? null : (float) $margin,
			'rounding' => (string) get_post_meta( $id, '_wdkf_rounding', true ),
		);
	}

	/** Kategori kuralı → genel varsayılan. Kurallar listedeki sıraya göre öncelik taşır. */
	public static function rule_for( $product ) {
		$s    = WDKF_Settings::all();
		$rule = array(
			'margin'   => (float) $s['default_margin'],
			'rounding' => (string) $s['default_rounding'],
			'extra'    => (float) $s['default_extra'],
			'source'   => 'Genel',
		);
		$product = wc_get_product( $product );
		if ( ! $product ) {
			return $rule;
		}
		$pid   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$terms = wc_get_product_term_ids( $pid, 'product_cat' );
		if ( ! $terms ) {
			return $rule;
		}
		// Alt kategoriler üst kategorinin kuralını devralır.
		$all = $terms;
		foreach ( $terms as $t ) {
			$all = array_merge( $all, get_ancestors( $t, 'product_cat', 'taxonomy' ) );
		}
		foreach ( (array) $s['category_rules'] as $r ) {
			if ( ! empty( $r['term_id'] ) && in_array( (int) $r['term_id'], array_map( 'intval', $all ), true ) ) {
				$term = get_term( (int) $r['term_id'], 'product_cat' );
				if ( '' !== (string) $r['margin'] ) {
					$rule['margin'] = (float) $r['margin'];
				}
				if ( ! empty( $r['rounding'] ) ) {
					$rule['rounding'] = $r['rounding'];
				}
				if ( isset( $r['extra'] ) && '' !== (string) $r['extra'] ) {
					$rule['extra'] = (float) $r['extra'];
				}
				$rule['source'] = 'Kategori: ' . ( $term && ! is_wp_error( $term ) ? $term->name : '#' . $r['term_id'] );
				break;
			}
		}
		return $rule;
	}

	/* ====================================================================
	 * Hesaplama
	 * ================================================================== */

	public static function ceil_clean( $x ) {
		return ceil( round( $x, 6 ) );
	}

	public static function round_price( $p, $mode ) {
		$p = round( (float) $p, 4 );
		switch ( (string) $mode ) {
			case 'int':
				return self::ceil_clean( $p );
			case 'x9':
				return self::ceil_clean( ( $p + 1 ) / 10 ) * 10 - 1;
			case 'x90':
				return self::ceil_clean( ( $p + 10 ) / 100 ) * 100 - 10;
			case 'x99':
				return self::ceil_clean( ( $p + 1 ) / 100 ) * 100 - 1;
			case 'd99':
				return round( self::ceil_clean( $p + 0.01 ) - 0.01, 2 );
			case '5':
			case '10':
			case '50':
			case '100':
				$n = (int) $mode;
				return self::ceil_clean( $p / $n ) * $n;
			default:
				return round( $p, wc_get_price_decimals() );
		}
	}

	/**
	 * @param array $override_rates Simülasyon kurları [cur => TL].
	 * @return array|null
	 */
	public static function calculate( $product, array $override_rates = array() ) {
		$product = wc_get_product( $product );
		$cfg     = $product ? self::config( $product ) : null;
		if ( ! $cfg ) {
			return null;
		}
		$rate = WDKF_Rates::to_store( $cfg['currency'], $override_rates );
		if ( $rate <= 0 ) {
			return null;
		}
		$rule     = self::rule_for( $product );
		$margin   = null !== $cfg['margin'] ? $cfg['margin'] : $rule['margin'];
		$extra    = null !== $cfg['extra'] ? $cfg['extra'] : $rule['extra'];
		$rounding = '' !== $cfg['rounding'] ? $cfg['rounding'] : $rule['rounding'];
		$vat      = (float) WDKF_Settings::get( 'vat_add' );

		$base = $cfg['amount'] * $rate;
		if ( 'fx' === $cfg['mode'] ) {
			$raw    = $base * ( 1 + $vat / 100 );
			$margin = 0;
			$extra  = 0;
		} else {
			$raw = ( $base + $extra ) * ( 1 + $margin / 100 ) * ( 1 + $vat / 100 );
		}

		$price = self::round_price( $raw, $rounding );

		return array(
			'price'       => (float) $price,
			'raw'         => round( $raw, 4 ),
			'base'        => round( $base, 4 ),
			'rate'        => round( $rate, 6 ),
			'currency'    => $cfg['currency'],
			'amount'      => $cfg['amount'],
			'mode'        => $cfg['mode'],
			'margin'      => $margin,
			'extra'       => $extra,
			'vat'         => $vat,
			'rounding'    => $rounding,
			'rule_source' => null !== $cfg['margin'] ? 'Ürüne özel' : $rule['source'],
			'profit'      => 'cost' === $cfg['mode'] ? round( $price / ( 1 + $vat / 100 ) - $base - $extra, 2 ) : null,
		);
	}

	/** İndirimli fiyatı normal fiyattaki değişimle orantılı ölçekler. */
	private static function scaled_sale( $old_r, $old_s, $new_r, $rounding ) {
		if ( null === $old_s ) {
			return null;
		}
		if ( ! WDKF_Settings::yes( 'scale_sale' ) ) {
			return $old_s < $new_r ? $old_s : null;
		}
		if ( $old_r <= 0 ) {
			return null;
		}
		$raw = $old_s * $new_r / $old_r;
		$r   = self::round_price( $raw, $rounding );
		if ( $r >= $new_r ) {
			$r = round( $raw, wc_get_price_decimals() );
		}
		return $r < $new_r ? (float) $r : null;
	}

	/* ====================================================================
	 * İşleme
	 * ================================================================== */

	/**
	 * Tek ürünü değerlendirir.
	 *
	 * @param string $context auto (zamanlanmış/kurallı) | manual (yönetici; doğrudan uygular) | import
	 * @return string same|applied|pending|scheduled|blocked|small|guard|skip
	 */
	public static function process( $product, $context = 'auto' ) {
		$product = wc_get_product( $product );
		$calc    = $product ? self::calculate( $product ) : null;
		if ( ! $calc ) {
			return 'skip';
		}
		$s     = WDKF_Settings::all();
		$old_r = '' === $product->get_regular_price( 'edit' ) ? null : (float) $product->get_regular_price( 'edit' );
		$old_s = '' === $product->get_sale_price( 'edit' ) ? null : (float) $product->get_sale_price( 'edit' );
		$new_r = $calc['price'];
		$new_s = self::scaled_sale( (float) $old_r, $old_s, $new_r, $calc['rounding'] );

		if ( null !== $old_r && abs( $new_r - $old_r ) < 0.005 && self::same( $old_s, $new_s ) ) {
			self::close_open( $product->get_id(), 'superseded', 'Fiyat zaten güncel.' );
			return 'same';
		}

		if ( 'auto' === $context && null !== $old_r && $old_r > 0 ) {
			$pct = ( $new_r - $old_r ) / $old_r * 100;

			if ( $new_r < $old_r ) {
				if ( 'never' === $s['decrease_policy'] ) {
					self::close_open( $product->get_id(), 'superseded', 'İndirim politikası: fiyat düşürülmez.' );
					return 'blocked';
				}
				if ( 'threshold' === $s['decrease_policy'] && abs( $pct ) < (float) $s['decrease_threshold'] ) {
					return 'small';
				}
			}

			if ( WDKF_Rates::guard_tripped( $calc['currency'] ) ) {
				return 'guard';
			}

			if ( (float) $s['approval_threshold'] > 0 && abs( $pct ) >= (float) $s['approval_threshold'] ) {
				self::upsert_open( $product, 'pending', $old_r, $new_r, $old_s, $new_s, $calc, sprintf( 'Değişim %%%s – onay eşiği aşıldı.', number_format_i18n( $pct, 1 ) ) );
				return 'pending';
			}

			if ( $new_r > $old_r && (int) $s['increase_delay_hours'] > 0 ) {
				self::upsert_open( $product, 'scheduled', $old_r, $new_r, $old_s, $new_s, $calc, 'Planlı zam.' );
				return 'scheduled';
			}
		}

		self::apply( $product, $new_r, $new_s, $calc, 'auto' === $context ? 'auto' : $context );
		return 'applied';
	}

	private static function same( $a, $b ) {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		return abs( $a - $b ) < 0.005;
	}

	/** Fiyatı ürüne yazar ve geçmişe kaydeder. */
	public static function apply( $product, $new_r, $new_s, array $calc, $reason = 'manual', $change_id = 0 ) {
		global $wpdb;
		$product = wc_get_product( $product );
		if ( ! $product ) {
			return false;
		}
		$old_r = $product->get_regular_price( 'edit' );
		$old_s = $product->get_sale_price( 'edit' );

		$product->set_regular_price( wc_format_decimal( $new_r ) );
		$product->set_sale_price( null === $new_s ? '' : wc_format_decimal( $new_s ) );
		$product->save();

		if ( $product->get_parent_id() ) {
			self::$sync_parents[ $product->get_parent_id() ] = true;
		}

		$now  = current_time( 'mysql', true );
		$data = array(
			'old_regular' => '' === $old_r ? null : (float) $old_r,
			'new_regular' => (float) $new_r,
			'old_sale'    => '' === $old_s ? null : (float) $old_s,
			'new_sale'    => null === $new_s ? null : (float) $new_s,
			'currency'    => $calc['currency'],
			'rate'        => $calc['rate'],
			'cost'        => $calc['amount'],
			'status'      => 'applied',
			'reason'      => $reason,
			'applied_at'  => $now,
			'user_id'     => get_current_user_id(),
		);

		if ( $change_id ) {
			$wpdb->update( self::table(), $data, array( 'id' => (int) $change_id ) );
		} else {
			$wpdb->insert(
				self::table(),
				array_merge(
					$data,
					array(
						'product_id' => $product->get_id(),
						'parent_id'  => $product->get_parent_id(),
						'note'       => $calc['rule_source'],
						'created_at' => $now,
					)
				)
			);
		}
		self::close_open( $product->get_id(), 'superseded', 'Yeni fiyat uygulandı.', $change_id );
		do_action( 'wdkf_price_applied', $product->get_id(), $new_r, $new_s, $calc );
		return true;
	}

	public static function sync_parents() {
		foreach ( array_keys( self::$sync_parents ) as $pid ) {
			WC_Product_Variable::sync( $pid );
			wc_delete_product_transients( $pid );
		}
		self::$sync_parents = array();
	}

	/* ====================================================================
	 * Açık kayıtlar (onay bekleyen / planlı)
	 * ================================================================== */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::T_CHANGES;
	}

	public static function open_change( $product_id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE product_id = %d AND status IN ('pending','scheduled') ORDER BY id DESC LIMIT 1", $product_id ), ARRAY_A ); // phpcs:ignore
	}

	private static function upsert_open( $product, $status, $old_r, $new_r, $old_s, $new_s, array $calc, $note ) {
		global $wpdb;
		$row  = self::open_change( $product->get_id() );
		$data = array(
			'old_regular' => $old_r,
			'new_regular' => $new_r,
			'old_sale'    => $old_s,
			'new_sale'    => $new_s,
			'currency'    => $calc['currency'],
			'rate'        => $calc['rate'],
			'cost'        => $calc['amount'],
			'status'      => $status,
			'reason'      => 'auto',
			'note'        => $note,
		);
		if ( 'scheduled' === $status ) {
			$keep = $row && 'scheduled' === $row['status'] && $row['scheduled_at'];
			$data['scheduled_at'] = $keep ? $row['scheduled_at'] : gmdate( 'Y-m-d H:i:s', time() + (int) WDKF_Settings::get( 'increase_delay_hours' ) * HOUR_IN_SECONDS );
		}
		if ( $row ) {
			if ( 'scheduled' === $status && ( 'scheduled' !== $row['status'] ) ) {
				$data['notified'] = 0;
			}
			$wpdb->update( self::table(), $data, array( 'id' => (int) $row['id'] ) );
			return (int) $row['id'];
		}
		$wpdb->insert(
			self::table(),
			array_merge(
				$data,
				array(
					'product_id' => $product->get_id(),
					'parent_id'  => $product->get_parent_id(),
					'created_at' => current_time( 'mysql', true ),
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function close_open( $product_id, $status, $note, $except_id = 0 ) {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = %s, note = %s WHERE product_id = %d AND id <> %d AND status IN ('pending','scheduled')", $status, $note, $product_id, $except_id ) ); // phpcs:ignore
	}

	public static function get_change( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
	}

	/** Onay bekleyen / planlı kaydı kaydedilen değerlerle uygular. */
	public static function approve( $id ) {
		$row = self::get_change( $id );
		if ( ! $row || ! in_array( $row['status'], array( 'pending', 'scheduled' ), true ) ) {
			return false;
		}
		$product = wc_get_product( $row['product_id'] );
		$calc    = $product ? self::calculate( $product ) : null;
		if ( ! $product || ! $calc ) {
			self::set_status( $id, 'cancelled', 'Ürün artık kur fiyatlamasında değil.' );
			return false;
		}
		return self::apply( $product, (float) $row['new_regular'], null === $row['new_sale'] ? null : (float) $row['new_sale'], $calc, 'pending' === $row['status'] ? 'approval' : 'schedule', (int) $row['id'] );
	}

	public static function set_status( $id, $status, $note = '' ) {
		global $wpdb;
		$data = array( 'status' => $status );
		if ( $note ) {
			$data['note'] = $note;
		}
		return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	public static function count_status( $status ) {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", $status ) ); // phpcs:ignore
	}

	/* ====================================================================
	 * Toplu çalıştırma
	 * ================================================================== */

	public static function enabled_ids() {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wdkf_enabled' AND m.meta_value = 'yes'
				WHERE p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.ID ASC"
			)
		);
	}

	/**
	 * Tüm kur fiyatlı ürünleri işler. Büyük kataloglarda Action Scheduler ile 50'lik parçalara böler.
	 *
	 * @return array Sonuç sayaçları (senkron çalıştıysa) veya ['queued' => n].
	 */
	public static function run_all( $context = 'auto' ) {
		$ids = self::enabled_ids();
		if ( count( $ids ) > 150 && function_exists( 'as_enqueue_async_action' ) ) {
			foreach ( array_chunk( $ids, 50 ) as $chunk ) {
				as_enqueue_async_action( 'wdkf_batch', array( $chunk, $context ), 'wdkf' );
			}
			return array( 'queued' => count( $ids ) );
		}
		return self::run_batch( $ids, $context );
	}

	public static function run_batch( $ids, $context = 'auto' ) {
		$counts = array();
		foreach ( (array) $ids as $id ) {
			$r            = self::process( (int) $id, $context );
			$counts[ $r ] = isset( $counts[ $r ] ) ? $counts[ $r ] + 1 : 1;
		}
		self::sync_parents();
		return $counts;
	}

	/** Saatlik görev: kurları çek → değiştiyse fiyatla → vadesi gelen zamları uygula → sepet bildirimleri. */
	public static function cron() {
		$changed = array();
		if ( WDKF_Settings::yes( 'auto_update' ) ) {
			$res = WDKF_Rates::fetch();
			if ( ! is_wp_error( $res ) ) {
				$changed = $res;
			}
		}
		if ( $changed || get_option( 'wdkf_force_recalc' ) ) {
			delete_option( 'wdkf_force_recalc' );
			self::run_all( 'auto' );
			WDKF_Rates::mark_applied();
			update_option( 'wdkf_last_run', time(), false );
		}
		self::apply_due();
		self::notify_carts();
	}

	/** Vadesi gelen planlı zamları güncel hesapla uygular. */
	public static function apply_due() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'scheduled' AND scheduled_at <= %s LIMIT 500", current_time( 'mysql', true ) ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$product = wc_get_product( $row['product_id'] );
			$calc    = $product ? self::calculate( $product ) : null;
			if ( ! $calc ) {
				self::set_status( $row['id'], 'cancelled', 'Ürün artık kur fiyatlamasında değil.' );
				continue;
			}
			$old_r = (float) $product->get_regular_price( 'edit' );
			$old_s = '' === $product->get_sale_price( 'edit' ) ? null : (float) $product->get_sale_price( 'edit' );
			self::apply( $product, $calc['price'], self::scaled_sale( $old_r, $old_s, $calc['price'], $calc['rounding'] ), $calc, 'schedule', (int) $row['id'] );
		}
		self::sync_parents();
		return count( $rows );
	}

	/* ====================================================================
	 * Sepet bildirimi (planlı zamlar)
	 * ================================================================== */

	public static function notify_carts() {
		global $wpdb;
		if ( ! WDKF_Settings::yes( 'notify_cart' ) || (int) WDKF_Settings::get( 'increase_delay_hours' ) <= 0 ) {
			return 0;
		}
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE status = 'scheduled' AND notified = 0 LIMIT 200", ARRAY_A ); // phpcs:ignore
		if ( ! $rows ) {
			return 0;
		}

		$meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
		$by_user  = array();

		foreach ( $rows as $row ) {
			$pid  = (int) $row['product_id'];
			$like = array(
				'%' . $wpdb->esc_like( '"product_id";i:' . $pid . ';' ) . '%',
				'%' . $wpdb->esc_like( '"variation_id";i:' . $pid . ';' ) . '%',
			);
			$users = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND (meta_value LIKE %s OR meta_value LIKE %s)", $meta_key, $like[0], $like[1] ) ); // phpcs:ignore
			$users = apply_filters( 'wdkf_notify_user_ids', array_map( 'intval', $users ), $pid, $row );
			foreach ( $users as $uid ) {
				$by_user[ $uid ][] = $row;
			}
			$wpdb->update( $t, array( 'notified' => 1 ), array( 'id' => (int) $row['id'] ) );
		}

		$sent = 0;
		foreach ( $by_user as $uid => $items ) {
			$last = (int) get_user_meta( $uid, '_wdkf_notified_at', true );
			if ( $last && time() - $last < 12 * HOUR_IN_SECONDS ) {
				continue;
			}
			$user = get_userdata( $uid );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}
			if ( self::send_cart_mail( $user, $items ) ) {
				update_user_meta( $uid, '_wdkf_notified_at', time() );
				++$sent;
			}
		}
		return $sent;
	}

	private static function send_cart_mail( WP_User $user, array $items ) {
		$mailer  = WC()->mailer();
		$heading = 'Sepetinizdeki ürünlerin fiyatı güncelleniyor';
		$list    = '';
		$first   = null;
		foreach ( $items as $row ) {
			$p = wc_get_product( $row['product_id'] );
			if ( ! $p ) {
				continue;
			}
			$when  = get_date_from_gmt( $row['scheduled_at'], 'd.m.Y H:i' );
			$first = $first ? $first : $when;
			$list .= sprintf( '<li><strong>%s</strong> – şu anki fiyat: %s (güncelleme: %s)</li>', esc_html( $p->get_name() ), wc_price( $p->get_price() ), esc_html( $when ) );
		}
		if ( ! $list ) {
			return false;
		}
		$body = sprintf(
			'<p>Merhaba %1$s,</p><p>Sepetinizde bekleyen ürünlerin fiyatı döviz kurundaki değişim nedeniyle yakında güncellenecek. Mevcut fiyattan yararlanmak için siparişinizi güncellemeden önce tamamlayabilirsiniz.</p><ul>%2$s</ul><p><a href="%3$s" style="display:inline-block;padding:12px 22px;background:#111;color:#fff;text-decoration:none;border-radius:6px">Sepete git</a></p>',
			esc_html( $user->first_name ? $user->first_name : $user->display_name ),
			$list,
			esc_url( wc_get_cart_url() )
		);
		return (bool) $mailer->send( $user->user_email, $heading, $mailer->wrap_message( $heading, $body ) );
	}
}

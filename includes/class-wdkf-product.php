<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ürün ve varyasyon düzenleme ekranındaki kur/maliyet alanları, ürün listesi sütunu.
 */
class WDKF_Product {

	public function __construct() {
		add_action( 'woocommerce_product_options_pricing', array( $this, 'simple_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple' ), 50 );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 50, 2 );

		add_filter( 'manage_edit-product_columns', array( $this, 'column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'column_content' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'product', 'edit-product' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wdkf-admin', WDKF_URL . 'assets/css/admin.css', array(), WDKF_VERSION );
		wp_enqueue_script( 'wdkf-product', WDKF_URL . 'assets/js/product.js', array( 'jquery' ), WDKF_VERSION, true );
		$rates = array( 'TRY' => WDKF_Rates::to_store( 'TRY' ) );
		foreach ( (array) WDKF_Settings::get( 'currencies' ) as $c ) {
			$rates[ $c ] = WDKF_Rates::to_store( $c );
		}
		wp_localize_script(
			'wdkf-product',
			'wdkfProduct',
			array(
				'rates'    => $rates,
				'vat'      => (float) WDKF_Settings::get( 'vat_add' ),
				'decimals' => wc_get_price_decimals(),
				'format'   => get_woocommerce_price_format(),
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decSep'   => wc_get_price_decimal_separator(),
				'thouSep'  => wc_get_price_thousand_separator(),
			)
		);
	}

	/* ---------------- Alanlar ---------------- */

	private static function values( $id ) {
		$v = array();
		foreach ( WDKF_Engine::meta_keys() as $k ) {
			$v[ substr( $k, 6 ) ] = get_post_meta( $id, $k, true );
		}
		return $v;
	}

	private function render( $id, $name, $rule, $variation = false ) {
		$v        = self::values( $id );
		$on       = 'yes' === $v['enabled'];
		$cur_list = array_unique( array_merge( array( 'USD', 'EUR' ), (array) WDKF_Settings::get( 'currencies' ), array( 'TRY' ) ) );
		$rounds   = WDKF_Settings::rounding_options();
		$field    = function ( $key ) use ( $name ) {
			return $name . '[' . $key . ']';
		};
		$calc = $on ? WDKF_Engine::calculate( $id ) : null;
		$open = $on ? WDKF_Engine::open_change( $id ) : null;
		?>
		<div class="wdkf-box<?php echo $variation ? ' wdkf-box--var' : ''; ?>"
			data-margin="<?php echo esc_attr( $rule['margin'] ); ?>"
			data-rounding="<?php echo esc_attr( $rule['rounding'] ); ?>"
			data-extra="<?php echo esc_attr( $rule['extra'] ); ?>">
			<p class="wdkf-toggle">
				<label>
					<input type="checkbox" class="wdkf-enabled" name="<?php echo esc_attr( $field( 'enabled' ) ); ?>" value="yes" <?php checked( $on ); ?>>
					<strong>Kur/maliyet bazlı otomatik fiyat</strong>
				</label>
				<span class="wdkf-hint">Açıkken normal fiyat kurla otomatik hesaplanır.</span>
			</p>
			<div class="wdkf-fields"<?php echo $on ? '' : ' hidden'; ?>>
				<div class="wdkf-grid">
					<label>Mod
						<select name="<?php echo esc_attr( $field( 'mode' ) ); ?>" class="wdkf-mode">
							<option value="cost" <?php selected( $v['mode'], 'cost' ); ?>>Maliyet + kâr marjı</option>
							<option value="fx" <?php selected( $v['mode'], 'fx' ); ?>>Döviz satış fiyatı</option>
						</select>
					</label>
					<label><span class="wdkf-amount-label"><?php echo 'fx' === $v['mode'] ? 'Döviz satış fiyatı' : 'Maliyet'; ?></span>
						<span class="wdkf-money">
							<input type="text" inputmode="decimal" class="wdkf-amount" name="<?php echo esc_attr( $field( 'amount' ) ); ?>" value="<?php echo esc_attr( '' === $v['amount'] ? '' : wc_format_localized_decimal( $v['amount'] ) ); ?>" placeholder="0,00">
							<select name="<?php echo esc_attr( $field( 'currency' ) ); ?>" class="wdkf-currency">
								<?php foreach ( $cur_list as $c ) : ?>
									<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $v['currency'] ? $v['currency'] : 'USD', $c ); ?>><?php echo esc_html( $c ); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
					</label>
					<label class="wdkf-cost-only">Kâr marjı (%)
						<input type="text" inputmode="decimal" class="wdkf-margin" name="<?php echo esc_attr( $field( 'margin' ) ); ?>" value="<?php echo esc_attr( '' === $v['margin'] ? '' : wc_format_localized_decimal( $v['margin'] ) ); ?>" placeholder="<?php echo esc_attr( 'Kural: %' . wc_format_localized_decimal( $rule['margin'] ) ); ?>">
					</label>
					<label class="wdkf-cost-only">Ek maliyet (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)
						<input type="text" inputmode="decimal" class="wdkf-extra" name="<?php echo esc_attr( $field( 'extra' ) ); ?>" value="<?php echo esc_attr( '' === $v['extra'] ? '' : wc_format_localized_decimal( $v['extra'] ) ); ?>" placeholder="<?php echo esc_attr( 'Kural: ' . wc_format_localized_decimal( $rule['extra'] ) ); ?>">
					</label>
					<label>Yuvarlama
						<select name="<?php echo esc_attr( $field( 'rounding' ) ); ?>" class="wdkf-rounding">
							<option value=""><?php echo esc_html( 'Kurala göre – ' . ( isset( $rounds[ $rule['rounding'] ] ) ? $rounds[ $rule['rounding'] ] : $rule['rounding'] ) ); ?></option>
							<?php foreach ( $rounds as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $v['rounding'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
				<div class="wdkf-preview">
					<span class="wdkf-preview__label">Hesaplanan fiyat</span>
					<strong class="wdkf-preview__price"><?php echo $calc ? wp_kses_post( wc_price( $calc['price'] ) ) : '—'; ?></strong>
					<span class="wdkf-preview__detail"><?php echo $calc ? esc_html( self::detail_text( $calc ) ) : 'Maliyet ve para birimi girin.'; ?></span>
				</div>
				<?php if ( $open ) : ?>
					<p class="wdkf-open wdkf-open--<?php echo esc_attr( $open['status'] ); ?>">
						<?php
						if ( 'pending' === $open['status'] ) {
							printf( 'Onay bekleyen değişiklik: %s → <strong>%s</strong>. ', wp_kses_post( wc_price( $open['old_regular'] ) ), wp_kses_post( wc_price( $open['new_regular'] ) ) );
						} else {
							printf( 'Planlı zam: <strong>%s</strong> tarihinde %s → <strong>%s</strong>. ', esc_html( get_date_from_gmt( $open['scheduled_at'], 'd.m.Y H:i' ) ), wp_kses_post( wc_price( $open['old_regular'] ) ), wp_kses_post( wc_price( $open['new_regular'] ) ) );
						}
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wdkf-queue' ) ); ?>">Kuyruğa git</a>
					</p>
				<?php endif; ?>
				<p class="wdkf-hint">Kaydettiğinizde fiyat hemen uygulanır. Sonraki kur değişimlerinde otomatik güncellenir. Kural: <?php echo esc_html( $rule['source'] ); ?>.</p>
			</div>
		</div>
		<?php
	}

	public static function detail_text( array $c ) {
		$parts = array( sprintf( '%s %s × %s', WDKF_Settings::currency_symbol( $c['currency'] ), wc_format_localized_decimal( $c['amount'] ), number_format_i18n( $c['rate'], 4 ) ) );
		if ( 'cost' === $c['mode'] ) {
			if ( $c['extra'] ) {
				$parts[] = '+ ' . wc_format_localized_decimal( $c['extra'] ) . ' ek';
			}
			$parts[] = '+%' . wc_format_localized_decimal( $c['margin'] ) . ' marj';
		}
		if ( $c['vat'] ) {
			$parts[] = '+%' . wc_format_localized_decimal( $c['vat'] ) . ' KDV';
		}
		$txt = implode( ' ', $parts );
		if ( null !== $c['profit'] ) {
			$txt .= ' · kâr ≈ ' . html_entity_decode( wp_strip_all_tags( wc_price( $c['profit'] ) ) );
		}
		return $txt;
	}

	public function simple_fields() {
		global $post;
		// Değişken ürünlerde alanlar her varyasyonun içinde gösterilir.
		echo '<div class="show_if_simple wdkf-simple">';
		$this->render( $post->ID, 'wdkf', WDKF_Engine::rule_for( $post->ID ) );
		echo '</div>';
	}

	public function variation_fields( $loop, $variation_data, $variation ) {
		echo '<div class="wdkf-variation">';
		$this->render( $variation->ID, 'wdkf_var[' . (int) $loop . ']', WDKF_Engine::rule_for( $variation->ID ), true );
		echo '</div>';
	}

	/* ---------------- Kaydetme ---------------- */

	private static function save_meta( $id, $in ) {
		$in      = is_array( $in ) ? $in : array();
		$enabled = ! empty( $in['enabled'] ) && 'yes' === $in['enabled'];
		update_post_meta( $id, '_wdkf_enabled', $enabled ? 'yes' : 'no' );

		$num = function ( $k ) use ( $in ) {
			if ( ! isset( $in[ $k ] ) || '' === trim( (string) $in[ $k ] ) ) {
				return '';
			}
			return wc_format_decimal( wc_clean( wp_unslash( $in[ $k ] ) ) );
		};
		update_post_meta( $id, '_wdkf_mode', ( isset( $in['mode'] ) && 'fx' === $in['mode'] ) ? 'fx' : 'cost' );
		update_post_meta( $id, '_wdkf_amount', $num( 'amount' ) );
		$cur = isset( $in['currency'] ) ? strtoupper( sanitize_key( $in['currency'] ) ) : 'USD';
		update_post_meta( $id, '_wdkf_currency', preg_match( '/^[A-Z]{3}$/', $cur ) ? $cur : 'USD' );
		update_post_meta( $id, '_wdkf_margin', $num( 'margin' ) );
		update_post_meta( $id, '_wdkf_extra', $num( 'extra' ) );
		$r = isset( $in['rounding'] ) ? sanitize_key( $in['rounding'] ) : '';
		update_post_meta( $id, '_wdkf_rounding', isset( WDKF_Settings::rounding_options()[ $r ] ) ? $r : '' );
		return $enabled;
	}

	public function save_simple( $post_id ) {
		if ( ! isset( $_POST['wdkf'] ) || ! current_user_can( 'edit_product', $post_id ) ) { // phpcs:ignore -- WooCommerce ürün nonce'u doğrulandıktan sonra çalışır.
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return;
		}
		if ( self::save_meta( $post_id, $_POST['wdkf'] ) ) { // phpcs:ignore
			WDKF_Engine::process( $post_id, 'manual' );
		}
	}

	public function save_variation( $variation_id, $i ) {
		if ( ! isset( $_POST['wdkf_var'][ $i ] ) || ! current_user_can( 'edit_product', $variation_id ) ) { // phpcs:ignore
			return;
		}
		if ( self::save_meta( $variation_id, $_POST['wdkf_var'][ $i ] ) ) { // phpcs:ignore
			WDKF_Engine::process( $variation_id, 'manual' );
		}
	}

	/* ---------------- Ürün listesi sütunu ---------------- */

	public function column( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'price' === $k ) {
				$out['wdkf'] = 'Kur fiyat';
			}
		}
		if ( ! isset( $out['wdkf'] ) ) {
			$out['wdkf'] = 'Kur fiyat';
		}
		return $out;
	}

	public function column_content( $col, $post_id ) {
		if ( 'wdkf' !== $col ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			$n = 0;
			foreach ( $product->get_children() as $vid ) {
				if ( 'yes' === get_post_meta( $vid, '_wdkf_enabled', true ) ) {
					++$n;
				}
			}
			echo $n ? '<span class="wdkf-badge">' . (int) $n . ' varyasyon</span>' : '<span class="wdkf-dim">—</span>';
			return;
		}
		$cfg = WDKF_Engine::config( $product );
		if ( ! $cfg ) {
			echo '<span class="wdkf-dim">—</span>';
			return;
		}
		$open = WDKF_Engine::open_change( $post_id );
		printf(
			'<span class="wdkf-badge">%s%s</span><br><span class="wdkf-dim">%s</span>',
			esc_html( WDKF_Settings::currency_symbol( $cfg['currency'] ) ),
			esc_html( wc_format_localized_decimal( $cfg['amount'] ) ),
			esc_html( 'fx' === $cfg['mode'] ? 'döviz fiyatı' : ( null !== $cfg['margin'] ? '%' . wc_format_localized_decimal( $cfg['margin'] ) . ' marj' : 'kural marjı' ) )
		);
		if ( $open ) {
			echo '<br><span class="wdkf-pill wdkf-pill--' . esc_attr( $open['status'] ) . '">' . ( 'pending' === $open['status'] ? 'Onay bekliyor' : 'Planlı zam' ) . '</span>';
		}
	}
}

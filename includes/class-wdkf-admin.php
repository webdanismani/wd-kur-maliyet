<?php
defined( 'ABSPATH' ) || exit;

/**
 * Yönetim paneli: genel bakış, onay/planlı kuyruk, simülasyon, fiyat geçmişi, CSV içe/dışa aktarma, ayarlar.
 */
class WDKF_Admin {

	const CAP = 'manage_woocommerce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'global_notice' ) );

		foreach ( array( 'fetch', 'recalc', 'accept_guard', 'queue', 'save_settings', 'export', 'import' ) as $a ) {
			add_action( 'admin_post_wdkf_' . $a, array( $this, 'handle_' . $a ) );
		}

		add_filter( 'plugin_action_links_' . plugin_basename( WDKF_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
	}

	/* ====================================================================
	 * Altyapı
	 * ================================================================== */

	public function menu() {
		$pending = WDKF_Engine::count_status( 'pending' );
		$guard   = count( WDKF_Rates::guarded_currencies() );
		$n       = $pending + $guard;
		$bubble  = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';

		add_menu_page( 'WD Kur Fiyat', 'Kur Fiyat' . $bubble, self::CAP, 'wdkf', array( $this, 'page_dashboard' ), 'dashicons-chart-line', 56 );
		add_submenu_page( 'wdkf', 'Genel Bakış', 'Genel Bakış', self::CAP, 'wdkf', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'wdkf', 'Onay ve Planlı Zamlar', 'Onay Kuyruğu' . ( $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '' ), self::CAP, 'wdkf-queue', array( $this, 'page_queue' ) );
		add_submenu_page( 'wdkf', 'Kur Simülasyonu', 'Simülasyon', self::CAP, 'wdkf-simulate', array( $this, 'page_simulate' ) );
		add_submenu_page( 'wdkf', 'Fiyat Geçmişi', 'Fiyat Geçmişi', self::CAP, 'wdkf-history', array( $this, 'page_history' ) );
		add_submenu_page( 'wdkf', 'İçe / Dışa Aktar', 'İçe / Dışa Aktar', self::CAP, 'wdkf-io', array( $this, 'page_io' ) );
		add_submenu_page( 'wdkf', 'Ayarlar ve Kurallar', 'Ayarlar', self::CAP, 'wdkf-settings', array( $this, 'page_settings' ) );
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		if ( 0 !== strpos( $page, 'wdkf' ) ) {
			return;
		}
		wp_enqueue_style( 'wdkf-admin', WDKF_URL . 'assets/css/admin.css', array(), WDKF_VERSION );
		wp_enqueue_script( 'wdkf-admin', WDKF_URL . 'assets/js/admin.js', array( 'jquery' ), WDKF_VERSION, true );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'wdkf-settings' ) ) . '">Ayarlar</a>' );
		return $links;
	}

	public function row_meta( $links, $file ) {
		if ( plugin_basename( WDKF_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( WDKF_SUPPORT_URL ) . '" target="_blank" rel="noopener">Destek (oblifex.com)</a>';
			$links[] = '<a href="' . esc_url( WDKF_REPO_URL ) . '" target="_blank" rel="noopener">GitHub</a>';
		}
		return $links;
	}

	public function footer_text( $text ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		if ( 0 !== strpos( $page, 'wdkf' ) ) {
			return $text;
		}
		return sprintf(
			'WD Kur Fiyat ücretsizdir. Soru, öneri ve destek için <a href="%s" target="_blank" rel="noopener">oblifex.com</a> webmaster forumuna katılın. · <a href="https://webdanismani.com" target="_blank" rel="noopener">Web Danışmanı</a>',
			esc_url( WDKF_SUPPORT_URL )
		);
	}

	/** Kur sıçraması tüm yönetim ekranlarında görünür. */
	public function global_notice() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		if ( 'wdkf' === $page ) {
			return;
		}
		$g = WDKF_Rates::guarded_currencies();
		if ( $g ) {
			printf(
				'<div class="notice notice-warning"><p><strong>WD Kur Fiyat:</strong> %s kurunda ani sıçrama algılandı; bu para birimindeki otomatik fiyat güncellemeleri durduruldu. <a href="%s">İncele ve onayla</a></p></div>',
				esc_html( implode( ', ', $g ) ),
				esc_url( self::url( 'wdkf' ) )
			);
		}
	}

	private static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function check() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Bu işlem için yetkiniz yok.', 403 );
		}
	}

	private static function redirect( $page, array $args = array() ) {
		wp_safe_redirect( self::url( $page, array_map( 'rawurlencode', $args ) ) );
		exit;
	}

	private static function post_form( $action, $inner, $class = '', $confirm = '' ) {
		return sprintf(
			'<form method="post" action="%s" class="wdkf-inline %s"%s><input type="hidden" name="action" value="wdkf_%s">%s%s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $class ),
			$confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '',
			esc_attr( $action ),
			wp_nonce_field( 'wdkf_' . $action, '_wpnonce', true, false ),
			$inner
		);
	}

	private static function rate_fmt( $r ) {
		return $r > 0 ? number_format_i18n( $r, 4 ) : '—';
	}

	private static function pct_html( $old, $new ) {
		if ( null === $old || '' === $old || (float) $old <= 0 || null === $new ) {
			return '<span class="wdkf-dim">yeni</span>';
		}
		$p   = ( (float) $new - (float) $old ) / (float) $old * 100;
		$cls = $p > 0.005 ? 'up' : ( $p < -0.005 ? 'down' : 'flat' );
		return '<span class="wdkf-delta wdkf-delta--' . $cls . '">' . ( $p > 0 ? '+' : '' ) . esc_html( number_format_i18n( $p, 1 ) ) . '%</span>';
	}

	private static function product_cell( $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return '#' . (int) $id . ' <span class="wdkf-dim">(silinmiş)</span>';
		}
		$edit = get_edit_post_link( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() );
		$name = $p->get_name();
		$sku  = $p->get_sku();
		return '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>' . ( $sku ? '<br><span class="wdkf-dim">SKU: ' . esc_html( $sku ) . '</span>' : '' );
	}

	private function header( $title, $actions = '' ) {
		$notice = isset( $_GET['wdkf_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['wdkf_notice'] ) ) : ''; // phpcs:ignore
		$error  = isset( $_GET['wdkf_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wdkf_error'] ) ) : ''; // phpcs:ignore
		echo '<div class="wrap wdkf-wrap"><div class="wdkf-top"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<span class="wdkf-brand">WD Kur Fiyat</span></div><hr class="wp-header-end">'; // phpcs:ignore
		if ( $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	private function footer() {
		echo '</div>';
	}

	private static function summary_text( array $c ) {
		if ( isset( $c['queued'] ) ) {
			return sprintf( '%d ürün arka planda işlenmek üzere kuyruğa alındı.', $c['queued'] );
		}
		$labels = array(
			'applied'   => 'uygulandı',
			'pending'   => 'onaya düştü',
			'scheduled' => 'planlı zama alındı',
			'same'      => 'zaten güncel',
			'blocked'   => 'indirim politikası nedeniyle düşürülmedi',
			'small'     => 'eşik altı indirim',
			'guard'     => 'kur sıçraması nedeniyle bekletildi',
			'skip'      => 'atlandı (maliyet/kur eksik)',
		);
		$parts = array();
		foreach ( $labels as $k => $l ) {
			if ( ! empty( $c[ $k ] ) ) {
				$parts[] = $c[ $k ] . ' ' . $l;
			}
		}
		return $parts ? 'Sonuç: ' . implode( ', ', $parts ) . '.' : 'İşlenecek ürün bulunamadı.';
	}

	/* ====================================================================
	 * Genel bakış
	 * ================================================================== */

	public function page_dashboard() {
		self::check();
		$s     = WDKF_Settings::all();
		$tcmb  = WDKF_Rates::tcmb();
		$err   = get_option( WDKF_Rates::OPT_ERROR );
		$guard = WDKF_Rates::guarded_currencies();

		$actions  = self::post_form( 'fetch', '<button class="page-title-action">Kurları şimdi güncelle</button>' );
		$this->header( 'Genel Bakış', $actions );

		if ( $err ) {
			printf( '<div class="notice notice-error"><p><strong>Kur güncellenemedi:</strong> %s <span class="wdkf-dim">(%s)</span>. Son başarılı kurlar kullanılmaya devam ediyor.</p></div>', esc_html( $err['message'] ), esc_html( wp_date( 'd.m.Y H:i', $err['time'] ) ) );
		}

		foreach ( $guard as $cur ) {
			$a = WDKF_Rates::applied();
			printf(
				'<div class="wdkf-alert"><div><strong>%1$s kurunda ani değişim: %2$s → %3$s (%4$s)</strong><br>Koruma eşiği %%%5$s. Bu para birimindeki ürünlerin otomatik güncellemesi durduruldu; kuru onaylayana kadar fiyatlar değişmez.</div>%6$s</div>',
				esc_html( $cur ),
				esc_html( self::rate_fmt( (float) $a[ $cur ] ) ),
				esc_html( self::rate_fmt( WDKF_Rates::effective( $cur ) ) ),
				wp_kses_post( self::pct_html( $a[ $cur ], WDKF_Rates::effective( $cur ) ) ),
				esc_html( wc_format_localized_decimal( $s['rate_guard'] ) ),
				self::post_form( 'accept_guard', '<input type="hidden" name="currency" value="' . esc_attr( $cur ) . '"><button class="button button-primary">Kuru onayla ve fiyatları güncelle</button>' ) // phpcs:ignore
			);
		}
		?>
		<div class="wdkf-rates">
			<?php
			foreach ( (array) $s['currencies'] as $cur ) :
				$base = WDKF_Rates::base( $cur );
				$eff  = WDKF_Rates::effective( $cur );
				$prev = WDKF_Rates::previous( $cur );
				?>
				<div class="wdkf-rate<?php echo in_array( $cur, $guard, true ) ? ' is-guard' : ''; ?>">
					<div class="wdkf-rate__head"><strong><?php echo esc_html( $cur ); ?></strong><span><?php echo esc_html( WDKF_Settings::supported_currencies()[ $cur ] ?? '' ); ?></span></div>
					<div class="wdkf-rate__value"><?php echo esc_html( self::rate_fmt( $base ) ); ?> <small>₺</small></div>
					<div class="wdkf-rate__meta">
						<?php echo null !== $prev ? wp_kses_post( self::pct_html( $prev, $eff ) ) . ' önceki kayda göre' : '<span class="wdkf-dim">ilk kayıt</span>'; ?>
					</div>
					<div class="wdkf-rate__meta">
						<?php
						if ( WDKF_Rates::is_manual( $cur ) ) {
							echo '<span class="wdkf-pill">Elle girilen kur</span>';
						} elseif ( $base <= 0 ) {
							echo '<span class="wdkf-pill wdkf-pill--pending">Kur alınamadı</span>';
						} else {
							echo 'Fiyatlamada: <strong>' . esc_html( self::rate_fmt( $eff ) ) . '</strong>' . ( (float) $s['rate_buffer'] ? ' <span class="wdkf-dim">(+%' . esc_html( wc_format_localized_decimal( $s['rate_buffer'] ) ) . ' tampon)</span>' : '' );
						}
						?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="wdkf-dim wdkf-source">
			Kaynak: TCMB <?php echo esc_html( WDKF_Settings::rate_fields()[ $s['rate_field'] ] ?? $s['rate_field'] ); ?> kuru
			<?php echo ! empty( $tcmb['date'] ) ? ' · Bülten tarihi: ' . esc_html( $tcmb['date'] ) : ''; ?>
			<?php echo ! empty( $tcmb['fetched'] ) ? ' · Son kontrol: ' . esc_html( wp_date( 'd.m.Y H:i', $tcmb['fetched'] ) ) : ' · Henüz kur alınmadı'; ?>
			<?php
			$next = wp_next_scheduled( WDKF_Install::CRON_HOOK );
			echo 'yes' === $s['auto_update'] && $next ? ' · Sonraki kontrol: ' . esc_html( wp_date( 'H:i', $next ) ) : ' · Otomatik güncelleme kapalı';
			?>
		</p>

		<?php
		$enabled = count( WDKF_Engine::enabled_ids() );
		$pending = WDKF_Engine::count_status( 'pending' );
		$sched   = WDKF_Engine::count_status( 'scheduled' );
		global $wpdb;
		$t     = WDKF_Engine::table();
		$week  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'applied' AND applied_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		?>
		<div class="wdkf-cards">
			<div class="wdkf-card"><span>Kur fiyatlı ürün/varyasyon</span><strong><?php echo esc_html( number_format_i18n( $enabled ) ); ?></strong></div>
			<a class="wdkf-card<?php echo $pending ? ' wdkf-card--warn' : ''; ?>" href="<?php echo esc_url( self::url( 'wdkf-queue' ) ); ?>"><span>Onay bekleyen</span><strong><?php echo esc_html( number_format_i18n( $pending ) ); ?></strong></a>
			<a class="wdkf-card" href="<?php echo esc_url( self::url( 'wdkf-queue', array( 'tab' => 'scheduled' ) ) ); ?>"><span>Planlı zam</span><strong><?php echo esc_html( number_format_i18n( $sched ) ); ?></strong></a>
			<div class="wdkf-card"><span>Son 7 günde güncellenen</span><strong><?php echo esc_html( number_format_i18n( $week ) ); ?></strong></div>
		</div>

		<div class="wdkf-panel wdkf-actions">
			<h2>Fiyatları yeniden hesapla</h2>
			<p class="description">Kurlar değiştiğinde saatlik görev bunu kendiliğinden yapar. Maliyet veya kural değiştirdiyseniz elle çalıştırabilirsiniz.</p>
			<?php
			echo self::post_form( 'recalc', '<input type="hidden" name="mode" value="auto"><button class="button button-primary">Kurallara göre hesapla</button> <span class="wdkf-dim">Onay eşiği, indirim politikası ve planlı zam uygulanır.</span>' ); // phpcs:ignore
			echo self::post_form( 'recalc', '<input type="hidden" name="mode" value="manual"><button class="button">Tümünü hemen uygula</button> <span class="wdkf-dim">Onay ve gecikme olmadan tüm fiyatlar hesaplanan değere çekilir.</span>', '', 'Tüm kur fiyatlı ürünlerin fiyatı onay beklemeden hemen güncellenecek. Emin misiniz?' ); // phpcs:ignore
			?>
		</div>

		<div class="wdkf-panel">
			<h2>Son fiyat değişiklikleri <a class="wdkf-more" href="<?php echo esc_url( self::url( 'wdkf-history' ) ); ?>">Tümü →</a></h2>
			<?php $this->changes_table( $this->query_changes( array( 'per_page' => 10 ) )['rows'] ); ?>
		</div>
		<?php
		$this->footer();
	}

	/* ====================================================================
	 * Kuyruk
	 * ================================================================== */

	public function page_queue() {
		self::check();
		$tab = isset( $_GET['tab'] ) && 'scheduled' === $_GET['tab'] ? 'scheduled' : 'pending'; // phpcs:ignore
		$this->header( 'Onay ve Planlı Zamlar' );
		printf(
			'<nav class="nav-tab-wrapper"><a href="%s" class="nav-tab %s">Onay bekleyen (%d)</a><a href="%s" class="nav-tab %s">Planlı zamlar (%d)</a></nav>',
			esc_url( self::url( 'wdkf-queue' ) ),
			'pending' === $tab ? 'nav-tab-active' : '',
			(int) WDKF_Engine::count_status( 'pending' ),
			esc_url( self::url( 'wdkf-queue', array( 'tab' => 'scheduled' ) ) ),
			'scheduled' === $tab ? 'nav-tab-active' : '',
			(int) WDKF_Engine::count_status( 'scheduled' )
		);
		echo '<p class="description wdkf-lead">' . ( 'pending' === $tab
			? 'Onay eşiğini (%' . esc_html( wc_format_localized_decimal( WDKF_Settings::get( 'approval_threshold' ) ) ) . ') aşan değişiklikler burada bekler. Onaylananlar hemen uygulanır.'
			: 'Planlı zamlar belirtilen saatte güncel kurla uygulanır. Bu sürede ürün sayfasında uyarı gösterilir ve ürün sepetinde olan müşterilere e-posta gider.' ) . '</p>';

		$list = $this->query_changes(
			array(
				'status'   => $tab,
				'per_page' => 200,
			)
		);
		if ( ! $list['rows'] ) {
			echo '<p class="wdkf-empty">Kuyruk boş.</p>';
			$this->footer();
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wdkf_queue">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'wdkf_queue' ); ?>
			<div class="tablenav top">
				<button class="button button-primary" name="do" value="approve"><?php echo 'pending' === $tab ? 'Seçilenleri onayla' : 'Seçilenleri şimdi uygula'; ?></button>
				<button class="button" name="do" value="reject"><?php echo 'pending' === $tab ? 'Seçilenleri reddet' : 'Seçilenleri iptal et'; ?></button>
			</div>
			<table class="widefat striped wdkf-table">
				<thead><tr>
					<td class="check-column"><input type="checkbox" class="wdkf-check-all"></td>
					<th>Ürün</th><th class="num">Mevcut</th><th class="num">Yeni</th><th class="num">Değişim</th><th>Maliyet × kur</th>
					<th><?php echo 'pending' === $tab ? 'Neden' : 'Uygulanacak'; ?></th><th>Tarih</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $list['rows'] as $r ) : ?>
					<tr>
						<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>"></th>
						<td><?php echo self::product_cell( $r['product_id'] ); // phpcs:ignore ?></td>
						<td class="num"><?php echo wp_kses_post( wc_price( $r['old_regular'] ) ); ?><?php echo null !== $r['old_sale'] ? '<br><span class="wdkf-dim">İnd. ' . wp_kses_post( wc_price( $r['old_sale'] ) ) . '</span>' : ''; ?></td>
						<td class="num"><strong><?php echo wp_kses_post( wc_price( $r['new_regular'] ) ); ?></strong><?php echo null !== $r['new_sale'] ? '<br><span class="wdkf-dim">İnd. ' . wp_kses_post( wc_price( $r['new_sale'] ) ) . '</span>' : ''; ?></td>
						<td class="num"><?php echo wp_kses_post( self::pct_html( $r['old_regular'], $r['new_regular'] ) ); ?></td>
						<td><?php echo esc_html( WDKF_Settings::currency_symbol( $r['currency'] ) . wc_format_localized_decimal( (float) $r['cost'] ) . ' × ' . self::rate_fmt( (float) $r['rate'] ) ); ?></td>
						<td><?php echo 'pending' === $tab ? esc_html( $r['note'] ) : esc_html( get_date_from_gmt( $r['scheduled_at'], 'd.m.Y H:i' ) ) . ( $r['notified'] ? '<br><span class="wdkf-dim">Sepet bildirimi gönderildi</span>' : '' ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'd.m.Y H:i' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php
		$this->footer();
	}

	/* ====================================================================
	 * Simülasyon
	 * ================================================================== */

	public function page_simulate() {
		self::check();
		$curs = (array) WDKF_Settings::get( 'currencies' );
		$in   = isset( $_GET['rate'] ) && is_array( $_GET['rate'] ) ? wp_unslash( $_GET['rate'] ) : array(); // phpcs:ignore
		$over = array();
		foreach ( $curs as $c ) {
			if ( isset( $in[ $c ] ) && '' !== trim( $in[ $c ] ) ) {
				$over[ $c ] = (float) wc_format_decimal( $in[ $c ] );
			}
		}

		$this->header( 'Kur Simülasyonu' );
		echo '<p class="description wdkf-lead">"Dolar 45 olursa fiyatlarım ne olur?" Kurları değiştirip etkisini görün. Hiçbir fiyat kaydedilmez.</p>';
		?>
		<form method="get" class="wdkf-panel wdkf-sim-form">
			<input type="hidden" name="page" value="wdkf-simulate">
			<?php foreach ( $curs as $c ) : ?>
				<label><?php echo esc_html( $c ); ?> <small class="wdkf-dim">şu an <?php echo esc_html( self::rate_fmt( WDKF_Rates::effective( $c ) ) ); ?></small>
					<input type="text" inputmode="decimal" name="rate[<?php echo esc_attr( $c ); ?>]" value="<?php echo esc_attr( isset( $over[ $c ] ) ? wc_format_localized_decimal( $over[ $c ] ) : '' ); ?>" placeholder="<?php echo esc_attr( wc_format_localized_decimal( round( WDKF_Rates::effective( $c ), 4 ) ) ); ?>">
				</label>
			<?php endforeach; ?>
			<button class="button button-primary">Hesapla</button>
			<span class="wdkf-dim">Girilen değerlere tampon eklenmez.</span>
		</form>
		<?php
		if ( ! $over ) {
			$this->footer();
			return;
		}

		$rows   = array();
		$tot_o  = 0;
		$tot_n  = 0;
		foreach ( array_slice( WDKF_Engine::enabled_ids(), 0, 1000 ) as $id ) {
			$p    = wc_get_product( $id );
			$calc = $p ? WDKF_Engine::calculate( $p, $over ) : null;
			if ( ! $calc ) {
				continue;
			}
			$old    = (float) $p->get_regular_price( 'edit' );
			$rows[] = array( $id, $old, $calc );
			$tot_o += $old;
			$tot_n += $calc['price'];
		}
		usort(
			$rows,
			function ( $a, $b ) {
				$pa = $a[1] > 0 ? abs( $a[2]['price'] - $a[1] ) / $a[1] : 0;
				$pb = $b[1] > 0 ? abs( $b[2]['price'] - $b[1] ) / $b[1] : 0;
				return $pb <=> $pa;
			}
		);
		$thr = (float) WDKF_Settings::get( 'approval_threshold' );
		$over_thr = 0;
		foreach ( $rows as $r ) {
			if ( $thr > 0 && $r[1] > 0 && abs( $r[2]['price'] - $r[1] ) / $r[1] * 100 >= $thr ) {
				++$over_thr;
			}
		}
		?>
		<div class="wdkf-cards">
			<div class="wdkf-card"><span>Ürün</span><strong><?php echo count( $rows ); ?></strong></div>
			<div class="wdkf-card"><span>Ortalama değişim</span><strong><?php echo wp_kses_post( self::pct_html( $tot_o, $tot_n ) ); ?></strong></div>
			<div class="wdkf-card"><span>Onaya düşecek</span><strong><?php echo (int) $over_thr; ?></strong></div>
		</div>
		<table class="widefat striped wdkf-table">
			<thead><tr><th>Ürün</th><th>Maliyet</th><th class="num">Mevcut fiyat</th><th class="num">Simüle fiyat</th><th class="num">Değişim</th><th class="num">Tahmini kâr</th><th>Kural</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $rows, 0, 300 ) as $r ) : ?>
				<tr>
					<td><?php echo self::product_cell( $r[0] ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( WDKF_Settings::currency_symbol( $r[2]['currency'] ) . wc_format_localized_decimal( $r[2]['amount'] ) ); ?><?php echo 'fx' === $r[2]['mode'] ? ' <span class="wdkf-dim">(döviz fiyatı)</span>' : ''; ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( $r[1] ) ); ?></td>
					<td class="num"><strong><?php echo wp_kses_post( wc_price( $r[2]['price'] ) ); ?></strong></td>
					<td class="num"><?php echo wp_kses_post( self::pct_html( $r[1], $r[2]['price'] ) ); ?></td>
					<td class="num"><?php echo null === $r[2]['profit'] ? '—' : wp_kses_post( wc_price( $r[2]['profit'] ) ); ?></td>
					<td><span class="wdkf-dim"><?php echo esc_html( $r[2]['rule_source'] ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->footer();
	}

	/* ====================================================================
	 * Geçmiş
	 * ================================================================== */

	private static function status_labels() {
		return array(
			'applied'    => 'Uygulandı',
			'pending'    => 'Onay bekliyor',
			'scheduled'  => 'Planlı',
			'rejected'   => 'Reddedildi',
			'cancelled'  => 'İptal',
			'superseded' => 'Geçersiz kaldı',
		);
	}

	private static function reason_labels() {
		return array(
			'auto'     => 'Otomatik (kur)',
			'manual'   => 'Elle / ürün kaydı',
			'approval' => 'Onaylandı',
			'schedule' => 'Planlı zam',
			'import'   => 'CSV içe aktarma',
		);
	}

	private function query_changes( array $args ) {
		global $wpdb;
		$t    = WDKF_Engine::table();
		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'search'   => '',
				'page'     => 1,
				'per_page' => 30,
			)
		);
		$where  = array( '1=1' );
		$params = array();
		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					's'              => $args['search'],
					'fields'         => 'ids',
					'posts_per_page' => 300,
				)
			);
			$sku = wc_get_product_id_by_sku( $args['search'] );
			if ( $sku ) {
				$ids[] = $sku;
			}
			$ids     = array_map( 'intval', $ids );
			$where[] = $ids ? '(product_id IN (' . implode( ',', $ids ) . ') OR parent_id IN (' . implode( ',', $ids ) . '))' : '1=0';
		}
		$w      = implode( ' AND ', $where );
		$pp     = max( 1, (int) $args['per_page'] );
		$off    = ( max( 1, (int) $args['page'] ) - 1 ) * $pp;
		$csql   = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$rsql   = "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT {$pp} OFFSET {$off}";
		if ( $params ) {
			$csql = $wpdb->prepare( $csql, $params ); // phpcs:ignore
			$rsql = $wpdb->prepare( $rsql, $params ); // phpcs:ignore
		}
		return array(
			'total' => (int) $wpdb->get_var( $csql ), // phpcs:ignore
			'rows'  => $wpdb->get_results( $rsql, ARRAY_A ), // phpcs:ignore
		);
	}

	private function changes_table( $rows ) {
		if ( ! $rows ) {
			echo '<p class="wdkf-empty">Henüz fiyat değişikliği yok.</p>';
			return;
		}
		$st = self::status_labels();
		$rs = self::reason_labels();
		?>
		<table class="widefat striped wdkf-table">
			<thead><tr><th>Tarih</th><th>Ürün</th><th class="num">Eski</th><th class="num">Yeni</th><th class="num">Değişim</th><th>Maliyet × kur</th><th>Durum</th><th>Kaynak</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $r['applied_at'] ? $r['applied_at'] : $r['created_at'], 'd.m.Y H:i' ) ); ?></td>
					<td><?php echo self::product_cell( $r['product_id'] ); // phpcs:ignore ?></td>
					<td class="num"><?php echo null === $r['old_regular'] ? '—' : wp_kses_post( wc_price( $r['old_regular'] ) ); ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( $r['new_regular'] ) ); ?><?php echo null !== $r['new_sale'] ? '<br><span class="wdkf-dim">İnd. ' . wp_kses_post( wc_price( $r['new_sale'] ) ) . '</span>' : ''; ?></td>
					<td class="num"><?php echo wp_kses_post( self::pct_html( $r['old_regular'], $r['new_regular'] ) ); ?></td>
					<td><?php echo esc_html( WDKF_Settings::currency_symbol( $r['currency'] ) . wc_format_localized_decimal( (float) $r['cost'] ) . ' × ' . self::rate_fmt( (float) $r['rate'] ) ); ?></td>
					<td><span class="wdkf-pill wdkf-pill--<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $st[ $r['status'] ] ?? $r['status'] ); ?></span></td>
					<td><?php echo esc_html( $rs[ $r['reason'] ] ?? $r['reason'] ); ?><?php echo $r['note'] ? '<br><span class="wdkf-dim">' . esc_html( $r['note'] ) . '</span>' : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function page_history() {
		self::check();
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
		if ( $status && ! isset( self::status_labels()[ $status ] ) ) {
			$status = '';
		}
		$list = $this->query_changes(
			array(
				'status'   => $status,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => 30,
			)
		);
		$this->header( 'Fiyat Geçmişi' );
		echo '<p class="description wdkf-lead">Her fiyat değişikliği kur, maliyet ve kaynağıyla birlikte saklanır. İndirim yaparken "son 30 günün en düşük fiyatı" gibi kontroller için de kullanılabilir.</p>';
		?>
		<form method="get" class="wdkf-filter">
			<input type="hidden" name="page" value="wdkf-history">
			<select name="status">
				<option value="">Tüm durumlar</option>
				<?php foreach ( self::status_labels() as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Ürün adı veya SKU">
			<button class="button">Filtrele</button>
		</form>
		<?php
		$this->changes_table( $list['rows'] );
		$pages = (int) ceil( $list['total'] / 30 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( // phpcs:ignore
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			) . '</div></div>';
		}
		$this->footer();
	}

	/* ====================================================================
	 * İçe / dışa aktarma
	 * ================================================================== */

	public function page_io() {
		self::check();
		$this->header( 'İçe / Dışa Aktar' );
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=wdkf_export' ), 'wdkf_export' );
		$rounds = implode( ', ', array_keys( WDKF_Settings::rounding_options() ) );
		?>
		<div class="wdkf-grid2">
			<div class="wdkf-panel">
				<h2>1. Şablonu indir</h2>
				<p>SKU'su olan tüm basit ürün ve varyasyonlar, mevcut fiyat ve kur ayarlarıyla birlikte indirilir. Excel'de açıp maliyet sütunlarını doldurun.</p>
				<p><a class="button button-primary" href="<?php echo esc_url( $export ); ?>">CSV indir</a></p>
			</div>
			<div class="wdkf-panel">
				<h2>2. Doldurulmuş dosyayı yükle</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="wdkf_import">
					<?php wp_nonce_field( 'wdkf_import' ); ?>
					<p><input type="file" name="csv" accept=".csv,text/csv" required></p>
					<p><label><input type="checkbox" name="apply" value="1" checked> Yüklenen ürünlerin fiyatını hemen hesapla ve uygula</label></p>
					<p><button class="button button-primary">İçe aktar</button></p>
				</form>
			</div>
		</div>
		<div class="wdkf-panel">
			<h2>Sütunlar</h2>
			<table class="widefat striped wdkf-table">
				<thead><tr><th>Sütun</th><th>Zorunlu</th><th>Açıklama</th></tr></thead>
				<tbody>
					<tr><td><code>sku</code></td><td>Evet</td><td>Ürün veya varyasyon stok kodu</td></tr>
					<tr><td><code>maliyet</code></td><td>Evet</td><td>Maliyet (veya döviz fiyatı modunda satış fiyatı). 12,50 veya 12.50 yazılabilir.</td></tr>
					<tr><td><code>para_birimi</code></td><td>Hayır</td><td>USD, EUR, GBP, TRY… (boşsa USD)</td></tr>
					<tr><td><code>marj</code></td><td>Hayır</td><td>Kâr marjı %. Boş bırakılırsa kategori/genel kural kullanılır.</td></tr>
					<tr><td><code>ek_maliyet</code></td><td>Hayır</td><td>Mağaza para biriminde sabit ek maliyet (kargo, gümrük…)</td></tr>
					<tr><td><code>mod</code></td><td>Hayır</td><td><code>maliyet</code> veya <code>doviz</code></td></tr>
					<tr><td><code>yuvarlama</code></td><td>Hayır</td><td><?php echo esc_html( $rounds ); ?> (boşsa kurala göre)</td></tr>
					<tr><td><code>aktif</code></td><td>Hayır</td><td><code>evet</code>/<code>hayir</code> (boşsa evet)</td></tr>
				</tbody>
			</table>
			<p class="description">Ayraç olarak noktalı virgül (;) veya virgül (,) kullanılabilir; ilk satır başlık olmalıdır.</p>
		</div>
		<?php
		$this->footer();
	}

	public function handle_export() {
		self::check();
		check_admin_referer( 'wdkf_export' );
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' AND m.meta_value <> ''
			WHERE p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.ID"
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=kur-fiyat-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'sku', 'urun', 'mevcut_fiyat', 'maliyet', 'para_birimi', 'marj', 'ek_maliyet', 'mod', 'yuvarlama', 'aktif' ), ';', '"', '\\' );
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || $p->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
				continue;
			}
			$g = function ( $k ) use ( $id ) {
				return get_post_meta( $id, $k, true );
			};
			$dec = function ( $v ) {
				return '' === $v ? '' : wc_format_localized_decimal( $v );
			};
			fputcsv(
				$out,
				array(
					$p->get_sku(),
					$p->get_name(),
					$dec( $p->get_regular_price( 'edit' ) ),
					$dec( $g( '_wdkf_amount' ) ),
					$g( '_wdkf_currency' ) ? $g( '_wdkf_currency' ) : '',
					$dec( $g( '_wdkf_margin' ) ),
					$dec( $g( '_wdkf_extra' ) ),
					'fx' === $g( '_wdkf_mode' ) ? 'doviz' : 'maliyet',
					$g( '_wdkf_rounding' ),
					'yes' === $g( '_wdkf_enabled' ) ? 'evet' : 'hayir',
				),
				';',
				'"',
				'\\'
			);
		}
		fclose( $out );
		exit;
	}

	public function handle_import() {
		self::check();
		check_admin_referer( 'wdkf_import' );
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore
			self::redirect( 'wdkf-io', array( 'wdkf_error' => 'Dosya yüklenemedi.' ) );
		}
		$res = self::import_file( $_FILES['csv']['tmp_name'], ! empty( $_POST['apply'] ) ); // phpcs:ignore
		if ( is_wp_error( $res ) ) {
			self::redirect( 'wdkf-io', array( 'wdkf_error' => $res->get_error_message() ) );
		}
		$msg = sprintf( '%d satır işlendi: %d ürün güncellendi, %d fiyat uygulandı.', $res['rows'], $res['updated'], $res['applied'] );
		if ( $res['missing'] ) {
			$msg .= ' Bulunamayan SKU: ' . implode( ', ', array_slice( $res['missing'], 0, 15 ) ) . ( count( $res['missing'] ) > 15 ? '…' : '' );
		}
		if ( $res['invalid'] ) {
			$msg .= ' Geçersiz satır: ' . $res['invalid'] . '.';
		}
		self::redirect( 'wdkf-io', array( 'wdkf_notice' => $msg ) );
	}

	/** @return array|WP_Error */
	public static function import_file( $path, $apply = true ) {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'wdkf_csv', 'Dosya okunamadı.' );
		}
		$first = fgets( $fh );
		if ( false === $first ) {
			return new WP_Error( 'wdkf_csv', 'Dosya boş.' );
		}
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
		$delim = substr_count( $first, ';' ) >= substr_count( $first, ',' ) ? ';' : ',';
		$head  = array_map(
			function ( $h ) {
				$h = strtolower( trim( $h, " \t\n\r\0\x0B\"" ) );
				$map = array(
					'cost'     => 'maliyet',
					'currency' => 'para_birimi',
					'margin'   => 'marj',
					'extra'    => 'ek_maliyet',
					'mode'     => 'mod',
					'rounding' => 'yuvarlama',
					'enabled'  => 'aktif',
				);
				return isset( $map[ $h ] ) ? $map[ $h ] : $h;
			},
			str_getcsv( $first, $delim, '"', '\\' )
		);
		if ( ! in_array( 'sku', $head, true ) || ! in_array( 'maliyet', $head, true ) ) {
			fclose( $fh );
			return new WP_Error( 'wdkf_csv', 'Başlık satırında "sku" ve "maliyet" sütunları bulunmalı.' );
		}

		$rounds = WDKF_Settings::rounding_options();
		$stats  = array(
			'rows'    => 0,
			'updated' => 0,
			'applied' => 0,
			'missing' => array(),
			'invalid' => 0,
		);
		$dec = function ( $v ) {
			$v = trim( (string) $v );
			if ( '' === $v ) {
				return '';
			}
			if ( false !== strpos( $v, ',' ) ) {
				$v = str_replace( array( '.', ',' ), array( '', '.' ), $v );
			}
			return is_numeric( $v ) ? wc_format_decimal( $v ) : null;
		};

		while ( ( $cols = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) {
			if ( array( null ) === $cols || ! array_filter( $cols, 'strlen' ) ) {
				continue;
			}
			++$stats['rows'];
			$row = array();
			foreach ( $head as $i => $h ) {
				$row[ $h ] = isset( $cols[ $i ] ) ? trim( $cols[ $i ] ) : '';
			}
			$sku = wc_clean( $row['sku'] );
			$id  = $sku ? wc_get_product_id_by_sku( $sku ) : 0;
			$p   = $id ? wc_get_product( $id ) : null;
			if ( ! $p || $p->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
				$stats['missing'][] = $sku ? $sku : '(boş)';
				continue;
			}
			$amount = $dec( $row['maliyet'] );
			if ( null === $amount || '' === $amount || (float) $amount <= 0 ) {
				++$stats['invalid'];
				continue;
			}
			$margin = isset( $row['marj'] ) ? $dec( str_replace( '%', '', $row['marj'] ) ) : '';
			$extra  = isset( $row['ek_maliyet'] ) ? $dec( $row['ek_maliyet'] ) : '';
			$cur    = isset( $row['para_birimi'] ) && preg_match( '/^[A-Za-z]{3}$/', $row['para_birimi'] ) ? strtoupper( $row['para_birimi'] ) : 'USD';
			$mode   = isset( $row['mod'] ) && in_array( strtolower( $row['mod'] ), array( 'doviz', 'döviz', 'fx' ), true ) ? 'fx' : 'cost';
			$round  = isset( $row['yuvarlama'] ) && isset( $rounds[ $row['yuvarlama'] ] ) ? $row['yuvarlama'] : '';
			$active = ! isset( $row['aktif'] ) || '' === $row['aktif'] || in_array( strtolower( $row['aktif'] ), array( 'evet', 'yes', '1', 'true', 'e' ), true );

			update_post_meta( $id, '_wdkf_enabled', $active ? 'yes' : 'no' );
			update_post_meta( $id, '_wdkf_amount', $amount );
			update_post_meta( $id, '_wdkf_currency', $cur );
			update_post_meta( $id, '_wdkf_mode', $mode );
			update_post_meta( $id, '_wdkf_margin', null === $margin ? '' : $margin );
			update_post_meta( $id, '_wdkf_extra', null === $extra ? '' : $extra );
			update_post_meta( $id, '_wdkf_rounding', $round );
			++$stats['updated'];

			if ( $apply && $active && 'applied' === WDKF_Engine::process( $id, 'import' ) ) {
				++$stats['applied'];
			}
		}
		fclose( $fh );
		WDKF_Engine::sync_parents();
		return $stats;
	}

	/* ====================================================================
	 * Ayarlar
	 * ================================================================== */

	public function page_settings() {
		self::check();
		$s      = WDKF_Settings::all();
		$rounds = WDKF_Settings::rounding_options();
		$sym    = get_woocommerce_currency_symbol();
		$this->header( 'Ayarlar ve Kurallar' );
		if ( 'TRY' !== get_woocommerce_currency() ) {
			printf( '<div class="notice notice-info"><p>Mağaza para birimi %s. Kurlar TL üzerinden çapraz hesaplanır; %s para biriminin de aşağıda seçili olması gerekir.</p></div>', esc_html( get_woocommerce_currency() ), esc_html( get_woocommerce_currency() ) );
		}
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wdkf-settings">
			<input type="hidden" name="action" value="wdkf_save_settings">
			<?php wp_nonce_field( 'wdkf_save_settings' ); ?>

			<div class="wdkf-panel">
				<h2>Kur kaynağı</h2>
				<table class="form-table">
					<tr><th>Otomatik güncelleme</th><td><label><input type="checkbox" name="s[auto_update]" value="yes" <?php checked( $s['auto_update'], 'yes' ); ?>> TCMB kurlarını saatlik kontrol et; değişince fiyatları güncelle</label></td></tr>
					<tr><th>Kur türü</th><td><select name="s[rate_field]">
						<?php foreach ( WDKF_Settings::rate_fields() as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['rate_field'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select><p class="description">Tedarikçi ödemelerinde genellikle döviz satış kuru kullanılır.</p></td></tr>
					<tr><th>Para birimleri</th><td class="wdkf-checks">
						<?php foreach ( WDKF_Settings::supported_currencies() as $k => $l ) : ?>
							<label><input type="checkbox" name="s[currencies][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['currencies'], true ) ); ?>> <?php echo esc_html( $k . ' – ' . $l ); ?></label>
						<?php endforeach; ?>
					</td></tr>
					<tr><th>Kur tamponu (%)</th><td><input type="text" inputmode="decimal" name="s[rate_buffer]" value="<?php echo esc_attr( wc_format_localized_decimal( $s['rate_buffer'] ) ); ?>" class="small-text"><p class="description">Kura eklenen güvenlik payı. Örn. 1 → 41,00 kur 41,41 olarak kullanılır.</p></td></tr>
					<tr><th>Elle kur</th><td class="wdkf-manual">
						<?php foreach ( (array) $s['currencies'] as $c ) : ?>
							<label><?php echo esc_html( $c ); ?> <input type="text" inputmode="decimal" name="s[manual_rates][<?php echo esc_attr( $c ); ?>]" value="<?php echo esc_attr( ! empty( $s['manual_rates'][ $c ] ) ? wc_format_localized_decimal( $s['manual_rates'][ $c ] ) : '' ); ?>" placeholder="TCMB" class="small-text"></label>
						<?php endforeach; ?>
						<p class="description">Doldurulursa TCMB yerine bu kur kullanılır (ör. tedarikçinizin kuru). Boş = TCMB.</p>
					</td></tr>
				</table>
			</div>

			<div class="wdkf-panel">
				<h2>Varsayılan fiyatlama</h2>
				<table class="form-table">
					<tr><th>Kâr marjı (%)</th><td><input type="text" inputmode="decimal" name="s[default_margin]" value="<?php echo esc_attr( wc_format_localized_decimal( $s['default_margin'] ) ); ?>" class="small-text"></td></tr>
					<tr><th>Ek maliyet (<?php echo esc_html( $sym ); ?>)</th><td><input type="text" inputmode="decimal" name="s[default_extra]" value="<?php echo esc_attr( wc_format_localized_decimal( $s['default_extra'] ) ); ?>" class="small-text"><p class="description">Her ürüne eklenen sabit maliyet (kargo, paketleme). Marjdan önce eklenir.</p></td></tr>
					<tr><th>Yuvarlama</th><td><select name="s[default_rounding]">
						<?php foreach ( $rounds as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['default_rounding'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select><p class="description">Tüm yuvarlamalar yukarı yapılır; fiyat hiçbir zaman hesaplanandan düşük olmaz.</p></td></tr>
					<tr><th>KDV ekle (%)</th><td><input type="text" inputmode="decimal" name="s[vat_add]" value="<?php echo esc_attr( wc_format_localized_decimal( $s['vat_add'] ) ); ?>" class="small-text"><p class="description">Maliyetler KDV hariçse ve mağaza fiyatları KDV dahil giriliyorsa 20 yazın. Aksi halde 0.</p></td></tr>
					<tr><th>İndirimli fiyat</th><td><label><input type="checkbox" name="s[scale_sale]" value="yes" <?php checked( $s['scale_sale'], 'yes' ); ?>> Ürünün indirimli fiyatını da aynı oranda güncelle</label></td></tr>
				</table>
			</div>

			<div class="wdkf-panel">
				<h2>Kategori kuralları</h2>
				<p class="description">Listede üstteki kural önceliklidir. Alt kategoriler üst kategorinin kuralını devralır. Ürüne özel marj her zaman kazanır.</p>
				<table class="widefat wdkf-rules">
					<thead><tr><th>Kategori</th><th>Marj (%)</th><th>Ek maliyet (<?php echo esc_html( $sym ); ?>)</th><th>Yuvarlama</th><th></th></tr></thead>
					<tbody>
					<?php
					$rules   = array_values( (array) $s['category_rules'] );
					$rules[] = array(
						'term_id'  => 0,
						'margin'   => '',
						'extra'    => '',
						'rounding' => '',
						'_tpl'     => true,
					);
					foreach ( $rules as $i => $r ) :
						$tpl  = ! empty( $r['_tpl'] );
						$name = $tpl ? 's[category_rules][__i__]' : 's[category_rules][' . $i . ']';
						?>
						<tr class="<?php echo $tpl ? 'wdkf-rule-tpl' : 'wdkf-rule'; ?>"<?php echo $tpl ? ' hidden' : ''; ?>>
							<td><select name="<?php echo esc_attr( $name ); ?>[term_id]"<?php echo $tpl ? ' disabled' : ''; ?>>
								<option value="">Kategori seçin…</option>
								<?php
								if ( ! is_wp_error( $cats ) ) :
									foreach ( $cats as $c ) :
										$depth = count( get_ancestors( $c->term_id, 'product_cat', 'taxonomy' ) );
										?>
									<option value="<?php echo (int) $c->term_id; ?>" <?php selected( (int) $r['term_id'], $c->term_id ); ?>><?php echo esc_html( str_repeat( '— ', $depth ) . $c->name ); ?></option>
										<?php
									endforeach;
								endif;
								?>
							</select></td>
							<td><input type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $name ); ?>[margin]" value="<?php echo esc_attr( '' === (string) $r['margin'] ? '' : wc_format_localized_decimal( $r['margin'] ) ); ?>" placeholder="genel"<?php echo $tpl ? ' disabled' : ''; ?>></td>
							<td><input type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $name ); ?>[extra]" value="<?php echo esc_attr( '' === (string) ( $r['extra'] ?? '' ) ? '' : wc_format_localized_decimal( $r['extra'] ) ); ?>" placeholder="genel"<?php echo $tpl ? ' disabled' : ''; ?>></td>
							<td><select name="<?php echo esc_attr( $name ); ?>[rounding]"<?php echo $tpl ? ' disabled' : ''; ?>>
								<option value="">Genel ayar</option>
								<?php foreach ( $rounds as $k => $l ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['rounding'], $k ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><button type="button" class="button-link wdkf-rule-del" aria-label="Kuralı sil">Sil</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button wdkf-rule-add">+ Kural ekle</button></p>
			</div>

			<div class="wdkf-panel">
				<h2>Güvenlik</h2>
				<table class="form-table">
					<tr><th>Fiyat düşüşleri</th><td>
						<select name="s[decrease_policy]">
							<option value="always" <?php selected( $s['decrease_policy'], 'always' ); ?>>Kur düşünce fiyatı da düşür</option>
							<option value="threshold" <?php selected( $s['decrease_policy'], 'threshold' ); ?>>Yalnızca belirli oranın üzerindeki düşüşlerde düşür</option>
							<option value="never" <?php selected( $s['decrease_policy'], 'never' ); ?>>Fiyatı otomatik olarak asla düşürme</option>
						</select>
						eşik % <input type="text" inputmode="decimal" name="s[decrease_threshold]" value="<?php echo esc_attr( wc_format_localized_decimal( $s['decrease_threshold'] ) ); ?>" class="small-text">
						<p class="description">Kurdaki küçük dalgalanmalarda fiyatın sürekli inip çıkmasını engeller.</p>
					</td></tr>
					<tr><th>Onay eşiği (%)</th><td><input type="text" inputmode="decimal" name="s[approval_threshold]" value="<?php echo esc_attr( wc_format_localized_decimal( $s['approval_threshold'] ) ); ?>" class="small-text"><p class="description">Tek seferde bu orandan fazla değişen fiyatlar uygulanmaz, onay kuyruğuna düşer. 0 = kapalı.</p></td></tr>
					<tr><th>Kur sıçrama koruması (%)</th><td><input type="text" inputmode="decimal" name="s[rate_guard]" value="<?php echo esc_attr( wc_format_localized_decimal( $s['rate_guard'] ) ); ?>" class="small-text"><p class="description">Bir kur, son uygulanan değerden bu orandan fazla saparsa o para birimindeki tüm otomatik güncellemeler siz onaylayana kadar durur. Hatalı veri ve ani dalgalanmalara karşı korur. 0 = kapalı.</p></td></tr>
				</table>
			</div>

			<div class="wdkf-panel">
				<h2>Planlı zam ve bildirim</h2>
				<table class="form-table">
					<tr><th>Zamları geciktir (saat)</th><td><input type="number" min="0" max="168" name="s[increase_delay_hours]" value="<?php echo esc_attr( (int) $s['increase_delay_hours'] ); ?>" class="small-text"><p class="description">0 = zamlar hemen uygulanır. Örn. 24: artışlar 24 saat sonra uygulanır; bu sürede müşteriye "fiyat güncellenecek" bilgisi verilir. İndirimler hep hemen uygulanır.</p></td></tr>
					<tr><th>Sepet bildirimi</th><td><label><input type="checkbox" name="s[notify_cart]" value="yes" <?php checked( $s['notify_cart'], 'yes' ); ?>> Ürünü sepetinde bekleten üyelere zam öncesi e-posta gönder</label></td></tr>
					<tr><th>Ürün sayfası uyarısı</th><td><label><input type="checkbox" name="s[product_notice]" value="yes" <?php checked( $s['product_notice'], 'yes' ); ?>> Planlı zam süresince ürün sayfasında uyarı göster</label>
						<p><input type="text" name="s[notice_text]" value="<?php echo esc_attr( $s['notice_text'] ); ?>" class="large-text"></p><p class="description">{tarih} zamın uygulanacağı tarih/saattir.</p></td></tr>
					<tr><th>Kur notu</th><td><label><input type="checkbox" name="s[show_rate_note]" value="yes" <?php checked( $s['show_rate_note'], 'yes' ); ?>> Kur fiyatlı ürünlerde bilgilendirme notu göster</label>
						<p><input type="text" name="s[rate_note_text]" value="<?php echo esc_attr( $s['rate_note_text'] ); ?>" class="large-text"></p></td></tr>
				</table>
			</div>

			<div class="wdkf-panel">
				<h2>Gelişmiş</h2>
				<table class="form-table">
					<tr><th>Kaldırma</th><td><label><input type="checkbox" name="s[delete_on_uninstall]" value="yes" <?php checked( $s['delete_on_uninstall'], 'yes' ); ?>> Eklenti silinirken tüm verileri sil (ürün fiyatları korunur)</label></td></tr>
					<tr><th>Kısa kod</th><td><code>[wdkf_kur currency="USD"]</code> güncel kuru yazdırır.</td></tr>
				</table>
			</div>

			<?php submit_button( 'Ayarları kaydet' ); ?>
		</form>
		<?php
		$this->footer();
	}

	public function handle_save_settings() {
		self::check();
		check_admin_referer( 'wdkf_save_settings' );
		$in  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore
		$d   = WDKF_Settings::defaults();
		$old = WDKF_Settings::all();
		$out = array();

		$dec = function ( $k, $min = 0, $max = 1000000 ) use ( $in, $d ) {
			if ( ! isset( $in[ $k ] ) || '' === trim( (string) $in[ $k ] ) ) {
				return $d[ $k ];
			}
			return max( $min, min( $max, (float) wc_format_decimal( $in[ $k ] ) ) );
		};
		foreach ( array( 'auto_update', 'scale_sale', 'notify_cart', 'product_notice', 'show_rate_note', 'delete_on_uninstall' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) && 'yes' === $in[ $k ] ? 'yes' : 'no';
		}
		$out['rate_field']       = isset( $in['rate_field'] ) && isset( WDKF_Settings::rate_fields()[ $in['rate_field'] ] ) ? $in['rate_field'] : $d['rate_field'];
		$out['currencies']       = array_values( array_intersect( array_map( 'strtoupper', (array) ( $in['currencies'] ?? array() ) ), array_keys( WDKF_Settings::supported_currencies() ) ) );
		$out['rate_buffer']      = $dec( 'rate_buffer', -50, 100 );
		$out['default_margin']   = $dec( 'default_margin', -99, 10000 );
		$out['default_extra']    = $dec( 'default_extra', 0 );
		$out['vat_add']          = $dec( 'vat_add', 0, 100 );
		$out['default_rounding'] = isset( $in['default_rounding'] ) && isset( WDKF_Settings::rounding_options()[ $in['default_rounding'] ] ) ? $in['default_rounding'] : $d['default_rounding'];
		$out['decrease_policy']  = isset( $in['decrease_policy'] ) && in_array( $in['decrease_policy'], array( 'always', 'threshold', 'never' ), true ) ? $in['decrease_policy'] : $d['decrease_policy'];
		$out['decrease_threshold']   = $dec( 'decrease_threshold', 0, 100 );
		$out['approval_threshold']   = $dec( 'approval_threshold', 0, 1000 );
		$out['rate_guard']           = $dec( 'rate_guard', 0, 100 );
		$out['increase_delay_hours'] = isset( $in['increase_delay_hours'] ) ? max( 0, min( 168, (int) $in['increase_delay_hours'] ) ) : 0;
		$out['notice_text']          = isset( $in['notice_text'] ) && '' !== trim( $in['notice_text'] ) ? sanitize_text_field( $in['notice_text'] ) : $d['notice_text'];
		$out['rate_note_text']       = isset( $in['rate_note_text'] ) && '' !== trim( $in['rate_note_text'] ) ? sanitize_text_field( $in['rate_note_text'] ) : $d['rate_note_text'];

		$out['manual_rates'] = array();
		foreach ( (array) ( $in['manual_rates'] ?? array() ) as $c => $v ) {
			$c = strtoupper( sanitize_key( $c ) );
			$v = (float) wc_format_decimal( $v );
			if ( $v > 0 && preg_match( '/^[A-Z]{3}$/', $c ) ) {
				$out['manual_rates'][ $c ] = $v;
			}
		}

		$out['category_rules'] = array();
		$seen                  = array();
		foreach ( (array) ( $in['category_rules'] ?? array() ) as $r ) {
			$tid = isset( $r['term_id'] ) ? absint( $r['term_id'] ) : 0;
			if ( ! $tid || isset( $seen[ $tid ] ) ) {
				continue;
			}
			$seen[ $tid ]            = true;
			$m                       = isset( $r['margin'] ) && '' !== trim( $r['margin'] ) ? (float) wc_format_decimal( $r['margin'] ) : '';
			$e                       = isset( $r['extra'] ) && '' !== trim( $r['extra'] ) ? (float) wc_format_decimal( $r['extra'] ) : '';
			$ro                      = isset( $r['rounding'] ) && isset( WDKF_Settings::rounding_options()[ $r['rounding'] ] ) ? $r['rounding'] : '';
			$out['category_rules'][] = array(
				'term_id'  => $tid,
				'margin'   => $m,
				'extra'    => $e,
				'rounding' => $ro,
			);
		}

		WDKF_Settings::save( $out );

		// Kaynak kur değiştiyse (tampon, elle kur, kur türü) geçmişe yaz ve sonraki çalışmada fiyatla.
		$rate_keys_changed = $old['rate_buffer'] != $out['rate_buffer'] || $old['manual_rates'] != $out['manual_rates'] || $old['rate_field'] !== $out['rate_field'] || $old['currencies'] != $out['currencies']; // phpcs:ignore
		if ( $rate_keys_changed ) {
			if ( $old['rate_field'] !== $out['rate_field'] ) {
				WDKF_Rates::fetch();
			}
			WDKF_Rates::record_changes( array(), 'manual' );
		}

		self::redirect( 'wdkf-settings', array( 'wdkf_notice' => 'Ayarlar kaydedildi. Yeni kuralları mevcut fiyatlara uygulamak için Genel Bakış\'taki "Kurallara göre hesapla" ya da "Tümünü hemen uygula" düğmesini kullanın.' ) );
	}

	/* ====================================================================
	 * İşleyiciler
	 * ================================================================== */

	public function handle_fetch() {
		self::check();
		check_admin_referer( 'wdkf_fetch' );
		$res = WDKF_Rates::fetch();
		if ( is_wp_error( $res ) ) {
			self::redirect( 'wdkf', array( 'wdkf_error' => $res->get_error_message() ) );
		}
		$msg = $res ? 'Kurlar güncellendi (' . implode( ', ', $res ) . ' değişti). ' : 'Kurlar kontrol edildi, değişiklik yok. ';
		if ( $res ) {
			$c = WDKF_Engine::run_all( 'auto' );
			WDKF_Rates::mark_applied();
			$msg .= self::summary_text( $c );
		}
		self::redirect( 'wdkf', array( 'wdkf_notice' => $msg ) );
	}

	public function handle_recalc() {
		self::check();
		check_admin_referer( 'wdkf_recalc' );
		$mode = isset( $_POST['mode'] ) && 'manual' === $_POST['mode'] ? 'manual' : 'auto';
		$c    = WDKF_Engine::run_all( $mode );
		if ( 'auto' === $mode ) {
			WDKF_Rates::mark_applied();
		}
		self::redirect( 'wdkf', array( 'wdkf_notice' => self::summary_text( $c ) ) );
	}

	public function handle_accept_guard() {
		self::check();
		check_admin_referer( 'wdkf_accept_guard' );
		$cur = isset( $_POST['currency'] ) ? strtoupper( sanitize_key( $_POST['currency'] ) ) : '';
		WDKF_Rates::mark_applied( array( $cur ), true );
		$c = WDKF_Engine::run_all( 'auto' );
		self::redirect( 'wdkf', array( 'wdkf_notice' => $cur . ' kuru onaylandı. ' . self::summary_text( $c ) ) );
	}

	public function handle_queue() {
		self::check();
		check_admin_referer( 'wdkf_queue' );
		$tab = isset( $_POST['tab'] ) && 'scheduled' === $_POST['tab'] ? 'scheduled' : 'pending';
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$n   = 0;
		foreach ( $ids as $id ) {
			if ( 'approve' === $do && WDKF_Engine::approve( $id ) ) {
				++$n;
			} elseif ( 'reject' === $do ) {
				$row = WDKF_Engine::get_change( $id );
				if ( $row && $row['status'] === $tab ) {
					WDKF_Engine::set_status( $id, 'pending' === $tab ? 'rejected' : 'cancelled', 'Yönetici tarafından ' . ( 'pending' === $tab ? 'reddedildi' : 'iptal edildi' ) . '.' );
					++$n;
				}
			}
		}
		WDKF_Engine::sync_parents();
		$msg = $n . ' kayıt ' . ( 'approve' === $do ? 'uygulandı' : ( 'pending' === $tab ? 'reddedildi' : 'iptal edildi' ) ) . '.';
		self::redirect( 'wdkf-queue', array_merge( array( 'wdkf_notice' => $msg ), 'scheduled' === $tab ? array( 'tab' => 'scheduled' ) : array() ) );
	}
}

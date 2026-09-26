<?php
defined( 'ABSPATH' ) || exit;

class WDKF_Install {

	const DB_VERSION = '1.0.0';
	const CRON_HOOK  = 'wdkf_hourly';

	public static function activate() {
		self::create_tables();
		add_option( WDKF_Settings::OPTION, WDKF_Settings::defaults() );
		update_option( 'wdkf_db_version', self::DB_VERSION );
		self::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wdkf_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'wdkf_db_version', self::DB_VERSION );
		}
		self::schedule();
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c       = $wpdb->get_charset_collate();
		$rates   = $wpdb->prefix . 'wdkf_rates';
		$changes = $wpdb->prefix . 'wdkf_changes';

		dbDelta(
			"CREATE TABLE {$rates} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  currency char(3) NOT NULL DEFAULT '',
  rate decimal(18,6) NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'tcmb',
  rate_date date NULL,
  fetched_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY currency (currency, fetched_at)
) {$c};
CREATE TABLE {$changes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  old_regular decimal(14,4) NULL,
  new_regular decimal(14,4) NULL,
  old_sale decimal(14,4) NULL,
  new_sale decimal(14,4) NULL,
  currency char(3) NOT NULL DEFAULT '',
  rate decimal(18,6) NOT NULL DEFAULT 0,
  cost decimal(14,4) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'applied',
  reason varchar(20) NOT NULL DEFAULT 'auto',
  note varchar(255) NOT NULL DEFAULT '',
  scheduled_at datetime NULL,
  notified tinyint(1) NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  applied_at datetime NULL,
  PRIMARY KEY  (id),
  KEY product_id (product_id),
  KEY status (status),
  KEY created_at (created_at)
) {$c};"
		);
	}
}

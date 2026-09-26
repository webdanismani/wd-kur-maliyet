<?php
/**
 * Eklenti silinirken çalışır. Ürün fiyatları her durumda korunur.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'wdkf_hourly' );

$wdkf_settings = get_option( 'wdkf_settings', array() );
if ( is_array( $wdkf_settings ) && isset( $wdkf_settings['delete_on_uninstall'] ) && 'yes' === $wdkf_settings['delete_on_uninstall'] ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wdkf_rates" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wdkf_changes" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_wdkf\_%'" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_wdkf_notified_at'" ); // phpcs:ignore
	foreach ( array( 'wdkf_settings', 'wdkf_db_version', 'wdkf_tcmb', 'wdkf_applied_rates', 'wdkf_rate_error', 'wdkf_last_run', 'wdkf_force_recalc' ) as $o ) {
		delete_option( $o );
	}
}

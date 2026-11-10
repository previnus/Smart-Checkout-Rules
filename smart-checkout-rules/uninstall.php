<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$scr_cleanup_site = static function (): void {
	$config = json_decode( (string) get_option( 'scr_rules_v2', '' ), true );
	if ( true === ( $config['settings']['delete_on_uninstall'] ?? false ) ) {
		delete_option( 'scr_rules_v1' );
		delete_option( 'scr_rules_v2' );
		delete_transient( 'scr_last_diagnostic' );
	}
};

if ( is_multisite() ) {
	$scr_offset = 0;
	do {
		$scr_sites = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 100,
				'offset'  => $scr_offset,
				'orderby' => 'id',
			)
		);
		foreach ( $scr_sites as $scr_site ) {
			switch_to_blog( (int) $scr_site );
			$scr_cleanup_site();
			restore_current_blog();
		}
		$scr_batch_size = count( $scr_sites );
		$scr_offset    += $scr_batch_size;
	} while ( 100 === $scr_batch_size );
} else {
	$scr_cleanup_site();
}

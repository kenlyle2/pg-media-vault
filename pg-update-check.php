<?php
/**
 * PostGlider — Force update check
 *
 * Upload to WordPress root (same folder as wp-config.php),
 * visit https://mypostglider.website/pg-update-check.php once.
 * Clears the cached plugin metadata, triggers a fresh update check,
 * redirects to Network Admin → Updates, then self-deletes.
 *
 * Requires: logged in as super admin.
 */

if ( php_sapi_name() === 'cli' ) {
    exit( 'Run from a browser.' );
}

require_once __DIR__ . '/wp-load.php';

if ( ! function_exists( 'is_super_admin' ) || ! is_super_admin() ) {
    wp_die( 'Super admin login required.', 403 );
}

// Bust our metadata cache and WP's own plugin-update transient
delete_site_transient( 'postglider_adapter_metadata' );
delete_site_transient( 'update_plugins' );

// Trigger a synchronous update check — populates the transient fresh
wp_update_plugins();

@unlink( __FILE__ );

wp_redirect( network_admin_url( 'update-core.php' ) );
exit;

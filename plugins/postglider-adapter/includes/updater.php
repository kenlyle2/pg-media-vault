<?php
/**
 * PostGlider Adapter — Auto-updater
 *
 * Hooks into WordPress's native plugin update mechanism.
 * Metadata is cached for 1 hour. Cache is cleared on:
 *   - wp_clean_plugins_cache (plugin activate/deactivate/delete)
 *   - admin_init when force-check=1 is present (the "Check Again" button)
 *   - pg_adapter_flush_update_cache REST action
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'POSTGLIDER_ADAPTER_METADATA_URL',       'https://raw.githubusercontent.com/kenlyle2/pg-media-vault/main/metadata.json' );
define( 'POSTGLIDER_ADAPTER_METADATA_TRANSIENT', 'pg_adapter_metadata' );
define( 'POSTGLIDER_ADAPTER_PLUGIN_FILE',        plugin_basename( POSTGLIDER_ADAPTER_DIR . 'postglider-adapter.php' ) );

// Clear our cache whenever WP flushes plugin data.
add_action( 'wp_clean_plugins_cache', function () {
    delete_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT );
} );

// Also clear on "Check Again" — force-check=1 is added to the URL by the Updates page.
add_action( 'admin_init', function () {
    if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
        delete_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT );
    }
} );

add_filter( 'pre_set_site_transient_update_plugins', 'pg_check_for_update' );

function pg_check_for_update( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;

    $metadata = pg_fetch_metadata();
    if ( ! $metadata || empty( $metadata->version ) ) return $transient;

    if ( version_compare( $metadata->version, POSTGLIDER_ADAPTER_VERSION, '>' ) ) {
        $transient->response[ POSTGLIDER_ADAPTER_PLUGIN_FILE ] = (object) [
            'slug'         => 'postglider-adapter',
            'plugin'       => POSTGLIDER_ADAPTER_PLUGIN_FILE,
            'new_version'  => $metadata->version,
            'url'          => 'https://github.com/kenlyle2/pg-media-vault',
            'package'      => $metadata->download_url,
            'requires'     => $metadata->requires      ?? '6.0',
            'requires_php' => $metadata->requires_php  ?? '8.0',
            'sections'     => (array) ( $metadata->sections ?? new stdClass() ),
        ];
        unset( $transient->no_update[ POSTGLIDER_ADAPTER_PLUGIN_FILE ] );
    }

    return $transient;
}

add_filter( 'plugins_api', 'pg_plugin_info', 10, 3 );

function pg_plugin_info( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== 'postglider-adapter' ) return $result;
    $metadata = pg_fetch_metadata();
    if ( ! $metadata ) return $result;
    return (object) [
        'name'          => $metadata->name         ?? 'PostGlider Gallery Adapter',
        'slug'          => 'postglider-adapter',
        'version'       => $metadata->version,
        'requires'      => $metadata->requires     ?? '6.0',
        'requires_php'  => $metadata->requires_php ?? '8.0',
        'author'        => '<a href="https://postglider.com">PostGlider</a>',
        'download_link' => $metadata->download_url,
        'sections'      => (array) ( $metadata->sections ?? new stdClass() ),
    ];
}

/**
 * Fetch metadata.json from GitHub, caching the result for 1 hour.
 * Failures are NOT cached — they retry on the next update check.
 * Returns null on any failure so callers can no-op gracefully.
 */
function pg_fetch_metadata(): ?stdClass {
    $cached = get_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT );
    if ( $cached !== false ) return $cached ?: null;

    $response = wp_remote_get( POSTGLIDER_ADAPTER_METADATA_URL, [
        'timeout'    => 15,
        'user-agent' => 'PostGlider-Updater/' . POSTGLIDER_ADAPTER_VERSION . '; ' . home_url(),
        'headers'    => [ 'Accept' => 'application/json' ],
        'sslverify'  => true,
    ] );

    if ( is_wp_error( $response ) ) {
        update_site_option( 'pg_adapter_update_error', $response->get_error_message() );
        return null;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        update_site_option( 'pg_adapter_update_error', "HTTP {$code} from metadata URL" );
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! $data ) {
        update_site_option( 'pg_adapter_update_error', 'Invalid JSON in metadata response' );
        return null;
    }

    delete_site_option( 'pg_adapter_update_error' );
    set_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT, $data, HOUR_IN_SECONDS );
    return $data;
}

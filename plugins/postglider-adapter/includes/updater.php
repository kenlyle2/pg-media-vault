<?php
/**
 * PostGlider Adapter — Auto-updater
 *
 * Hooks into WordPress's native plugin update mechanism.
 * Metadata is cached in a site transient (1 hour) to avoid hammering GitHub.
 * "Check Again" in Network Admin → Updates clears the cache and forces a fresh fetch.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'POSTGLIDER_ADAPTER_METADATA_URL',
    'https://raw.githubusercontent.com/kenlyle2/pg-media-vault/main/metadata.json'
);

define( 'POSTGLIDER_ADAPTER_METADATA_TRANSIENT', 'pg_adapter_metadata_cache' );

// Clear our metadata cache whenever WordPress flushes the plugin update cache.
add_action( 'wp_clean_plugins_cache', function () {
    delete_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT );
} );

add_filter( 'pre_set_site_transient_update_plugins', 'pg_check_for_update' );

function pg_check_for_update( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;

    $metadata = pg_fetch_metadata();
    if ( ! $metadata || empty( $metadata->version ) ) return $transient;

    $plugin_file = 'postglider-adapter/postglider-adapter.php';

    if ( version_compare( $metadata->version, POSTGLIDER_ADAPTER_VERSION, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) [
            'slug'         => 'postglider-adapter',
            'plugin'       => $plugin_file,
            'new_version'  => $metadata->version,
            'url'          => 'https://github.com/kenlyle2/pg-media-vault',
            'package'      => $metadata->download_url,
            'requires'     => $metadata->requires      ?? '6.0',
            'requires_php' => $metadata->requires_php  ?? '8.0',
            'sections'     => (array) ( $metadata->sections ?? new stdClass() ),
        ];
        // Remove from no_update so the notification is never suppressed.
        unset( $transient->no_update[ $plugin_file ] );
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

function pg_fetch_metadata(): ?stdClass {
    $cached = get_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT );
    if ( $cached !== false ) return $cached ?: null;

    $response = wp_remote_get( POSTGLIDER_ADAPTER_METADATA_URL, [
        'timeout'    => 10,
        'user-agent' => 'PostGlider/' . POSTGLIDER_ADAPTER_VERSION . '; ' . get_bloginfo( 'url' ),
    ] );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        // Cache the failure briefly so we don't hammer GitHub on every page load.
        set_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT, null, 5 * MINUTE_IN_SECONDS );
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! $data ) {
        set_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT, null, 5 * MINUTE_IN_SECONDS );
        return null;
    }

    set_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT, $data, HOUR_IN_SECONDS );
    return $data;
}

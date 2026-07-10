<?php
/**
 * PostGlider Adapter — SearchIQ install endpoint
 *
 * POST /wp-json/postglider/v1/searchiq-install
 * Auth: X-Gallery-Token header (same check as sync-image)
 *
 * Performs the full SearchIQ engine setup for THIS subsite, in one call, with
 * no wp-admin visit required. Proven live 2026-07-10 against a clean subsite
 * (bobs-wreckers.mypostglider.website) — see decisions.md D-2026-07-10e/f.
 *
 * Must run as a request TO the target subsite itself (not the network admin
 * site with a switch_to_blog() call) — $siq_plugin/$siqAPIClient are
 * per-request singletons scoped to whichever blog WordPress bootstraps for
 * the request URL; switch_to_blog() mid-request does not reliably re-scope
 * an already-constructed plugin instance's cached settings/domain.
 *
 * Steps, each confirmed necessary by live testing (skipping any one of the
 * "missing" steps below reproduces the exact broken-thumbnail bug fixed for
 * Stay True Tattoo):
 *   1. Store the SearchIQ API key (lets the plugin skip its "enter your key"
 *      screen, same as pg_configure_searchiq() already does for other seeds).
 *   2. _siq_postTypesForSearchSelection — the REAL flat option the plugin's
 *      getPluginSettings() reads. pg_configure_searchiq()'s _siq_raw_settings
 *      seed is a write-only UI pre-fill; it does not drive indexing.
 *   3. _siq_image_custom_field — without this, SearchIQ falls back to
 *      wp_get_attachment_url(), which is dead code for pg_gallery_image stub
 *      posts (WP core returns early for any non-'attachment' post type
 *      before that filter ever fires).
 *   4. submit_engine() — registers a new SearchIQ engine and returns a real
 *      engineKey. Needs no input beyond the site's own domain (read
 *      internally from $this->domain). Guarded so a second configure-site
 *      call doesn't re-register.
 *   5. _siq_sync_settings() — pushes the settings above to the now-registered
 *      cloud engine. No-ops while _siq_engine_just_created is set, which
 *      submit_engine() just set — must be cleared first.
 *   6. pg_setup_gallery_page() — switches the Gallery page shortcode from
 *      [pg_gallery] to [searchiq] now that a key is configured.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'postglider/v1', '/searchiq-install', [
        'methods'             => 'POST',
        'callback'            => 'pg_searchiq_install_handler',
        'permission_callback' => 'pg_searchiq_install_auth',
        'args' => [
            'searchiq_api_key' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );
} );

/**
 * Same check as sync.php's pg_sync_image_auth() — the gallery token already
 * issued to this subsite doubles as auth for this endpoint too, rather than
 * plumbing a second secret.
 */
function pg_searchiq_install_auth( WP_REST_Request $request ): bool {
    $provided = $request->get_header( 'X-Gallery-Token' );
    $stored   = pg_get_option( 'gallery_token' );

    if ( ! $provided || ! $stored ) {
        return false;
    }

    return hash_equals( (string) $stored, (string) $provided );
}

/** Known-working value, confirmed live on Stay True Tattoo and bobs-wreckers. */
function pg_siq_image_custom_field_value(): string {
    return 'post:,page:,custom_css:,customize_changeset:,oembed_cache:,user_request:,'
        . 'wp_block:,wp_template:,wp_template_part:,wp_global_styles:,wp_navigation:,'
        . 'wp_font_family:,wp_font_face:,pg_gallery_image:_pg_image_url';
}

function pg_searchiq_install_handler( WP_REST_Request $request ) {
    global $siq_plugin;

    if ( ! isset( $siq_plugin ) || ! class_exists( 'siq_plugin' ) ) {
        return new WP_Error(
            'pg_searchiq_not_active',
            'SearchIQ plugin is not active on this subsite.',
            [ 'status' => 424 ]
        );
    }

    $api_key = (string) $request->get_param( 'searchiq_api_key' );

    // Step 1
    update_option( '_siq_authentication_code', $api_key );

    // Step 2 — the real flat option, not the _siq_raw_settings seed.
    update_option( '_siq_postTypesForSearchSelection', 'post:yes,page:yes,pg_gallery_image:yes' );

    // Step 3 — the fix for the broken-thumbnail bug.
    update_option( '_siq_image_custom_field', pg_siq_image_custom_field_value() );

    // Step 4 — idempotent: don't re-register an already-provisioned engine.
    $engine_code_option = $siq_plugin->pluginOptions['engine_code'];
    $existing_engine     = get_option( $engine_code_option );
    $engine_result       = null;

    if ( empty( $existing_engine ) ) {
        $engine_result = $siq_plugin->submit_engine();

        if ( empty( $engine_result['engineKey'] ) ) {
            // Not 502/504 — Cloudflare intercepts those and replaces the response body with
            // its own branded error page, hiding this message from the caller entirely.
            // Confirmed live 2026-07-10 debugging a false "server crash" that was actually
            // this exact masking, triggered by a legitimate (if edge-case) upstream failure:
            // re-running this endpoint against a domain SearchIQ had already registered.
            return new WP_Error(
                'pg_searchiq_engine_failed',
                'SearchIQ did not return an engineKey. Response: ' . wp_json_encode( $engine_result ),
                [ 'status' => 500 ]
            );
        }

        // Step 5 — _siq_sync_settings() is protected and no-ops while
        // engine_just_created is set (submit_engine() just set it).
        delete_option( $siq_plugin->pluginOptions['engine_just_created'] );
        $sync_method = new ReflectionMethod( $siq_plugin, '_siq_sync_settings' );
        $sync_method->setAccessible( true );
        $sync_method->invoke( $siq_plugin );
    }

    // Step 6 — reuse the existing function as-is.
    pg_setup_gallery_page( get_current_blog_id() );

    return rest_ensure_response( [
        'ok'         => true,
        'engineKey'  => $engine_result['engineKey'] ?? get_option( $engine_code_option ),
        'newEngine'  => ! empty( $engine_result ),
    ] );
}

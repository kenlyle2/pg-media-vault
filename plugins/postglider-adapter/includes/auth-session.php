<?php
/**
 * PostGlider Adapter — SupaWP auth session endpoint
 *
 * GET /wp-json/supawp/v1/auth/session?token=<jwt>&return_to=<url>
 *
 * Called by app.postglider.com after every Supabase sign-in.
 * Validates the Supabase JWT, finds the matching WordPress user by email,
 * sets a WP auth cookie, and redirects to return_to.
 *
 * Used by the "Manage Subscription" flow so users land on
 * postglider.com/my-account already logged in to WooCommerce.
 *
 * Fail-safe: on any validation or lookup failure the redirect still fires
 * (without a cookie), so the user reaches their destination unauthenticated
 * rather than hitting a dead end.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'supawp/v1', '/auth/session', [
        'methods'             => 'GET',
        'callback'            => 'pg_auth_session_handler',
        'permission_callback' => '__return_true',
        'args'                => [
            'token' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'return_to' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_url',
            ],
        ],
    ] );
} );

function pg_auth_session_handler( WP_REST_Request $request ): void {
    $token     = $request->get_param( 'token' );
    $return_to = $request->get_param( 'return_to' ) ?: home_url( '/my-account' );

    // ── Security: restrict redirects to PostGlider-owned domains ────────
    $allowed_hosts = [ 'postglider.com', 'www.postglider.com', 'app.postglider.com' ];
    $parsed        = wp_parse_url( $return_to );
    if ( empty( $parsed['host'] ) || ! in_array( $parsed['host'], $allowed_hosts, true ) ) {
        $return_to = home_url( '/my-account' );
    }

    // ── Validate JWT via Supabase ────────────────────────────────────────
    $supabase_url = get_site_option( 'postglider_supabase_url' );
    $anon_key     = get_site_option( 'postglider_anon_key' );

    if ( ! $supabase_url || ! $anon_key ) {
        pg_session_redirect( $return_to );
    }

    $api_response = wp_remote_get(
        trailingslashit( $supabase_url ) . 'auth/v1/user',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'apikey'        => $anon_key,
            ],
            'timeout' => 10,
        ]
    );

    if ( is_wp_error( $api_response ) || 200 !== wp_remote_retrieve_response_code( $api_response ) ) {
        pg_session_redirect( $return_to );
    }

    $body  = json_decode( wp_remote_retrieve_body( $api_response ), true );
    $email = $body['email'] ?? '';

    if ( ! $email ) {
        pg_session_redirect( $return_to );
    }

    // ── Find WordPress user by email ─────────────────────────────────────
    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        // No WP account for this email — redirect unauthenticated.
        // WooCommerce will show a login prompt on /my-account.
        pg_session_redirect( $return_to );
    }

    // ── Set WP auth cookie and redirect ──────────────────────────────────
    wp_set_auth_cookie( $user->ID, /* remember */ true );
    pg_session_redirect( $return_to );
}

/**
 * Redirect and halt. Never returns.
 */
function pg_session_redirect( string $url ): never {
    wp_redirect( esc_url_raw( $url ) );
    exit;
}

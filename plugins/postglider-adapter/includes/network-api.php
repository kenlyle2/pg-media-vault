<?php
/**
 * PostGlider Adapter — Network configuration REST endpoint
 *
 * POST /wp-json/postglider/v1/configure-site
 * Auth: WordPress Application Password (super admin required)
 * Body: { "blog_id": 4, "supabase_url": "https://...", "gallery_token": "pg_gallery_..." }
 *
 * Called by PostGlider's provisionWpSubsite() immediately after site creation
 * to wire the gallery token to the new subsite without manual admin steps.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
    /**
     * POST /wp-json/postglider/v1/create-site
     * Creates a new subsite on the multisite network.
     * Idempotent — returns the existing blog_id if the domain already exists.
     */
    register_rest_route( 'postglider/v1', '/create-site', [
        'methods'             => 'POST',
        'callback'            => 'pg_create_site_handler',
        'permission_callback' => function () {
            return current_user_can( 'manage_network' );
        },
        'args' => [
            'domain' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'title' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'path' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '/',
            ],
        ],
    ] );

    register_rest_route( 'postglider/v1', '/configure-site', [
        'methods'             => 'POST',
        'callback'            => 'pg_configure_site_handler',
        'permission_callback' => function () {
            return current_user_can( 'manage_network' );
        },
        'args' => [
            'blog_id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'supabase_url' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_url',
            ],
            'gallery_token' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'anon_key' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );
} );

function pg_create_site_handler( WP_REST_Request $request ) {
    $domain = $request->get_param( 'domain' );
    $title  = $request->get_param( 'title' );
    $path   = $request->get_param( 'path' ) ?: '/';

    // Idempotent — return existing blog_id if domain already provisioned
    $existing_id = get_blog_id_from_url( $domain, $path );
    if ( $existing_id ) {
        return rest_ensure_response( [ 'ok' => true, 'blog_id' => (int) $existing_id, 'existing' => true ] );
    }

    $blog_id = wpmu_create_blog( $domain, $path, $title, get_current_user_id(), [ 'public' => 1 ] );

    if ( is_wp_error( $blog_id ) ) {
        return new WP_Error( 'pg_create_site_failed', $blog_id->get_error_message(), [ 'status' => 500 ] );
    }

    return rest_ensure_response( [ 'ok' => true, 'blog_id' => (int) $blog_id ] );
}

function pg_configure_site_handler( WP_REST_Request $request ) {
    $blog_id       = $request->get_param( 'blog_id' );
    $supabase_url  = $request->get_param( 'supabase_url' );
    $gallery_token = $request->get_param( 'gallery_token' );

    if ( ! get_blog_details( $blog_id ) ) {
        return new WP_Error( 'pg_invalid_blog', 'Blog not found.', [ 'status' => 404 ] );
    }

    update_blog_option( $blog_id, 'postglider_supabase_url',  $supabase_url );
    update_blog_option( $blog_id, 'postglider_gallery_token', $gallery_token );

    // anon key + supabase URL are network-wide — stored once, used by all
    // subsites and by the main-site auth-session endpoint.
    $anon_key = $request->get_param( 'anon_key' );
    if ( $anon_key ) {
        update_site_option( 'postglider_anon_key',     $anon_key );
        update_site_option( 'postglider_supabase_url', $supabase_url );
    }

    pg_setup_gallery_page( $blog_id );

    return rest_ensure_response( [
        'ok'      => true,
        'blog_id' => $blog_id,
    ] );
}

/**
 * Creates a published Gallery page with [pg_gallery] shortcode and wires it
 * into the primary nav menu on the given subsite.  Idempotent — skips if the
 * page already exists (identified by _pg_gallery_page meta).
 */
function pg_setup_gallery_page( int $blog_id ): void {
    switch_to_blog( $blog_id );

    $existing = get_posts( [
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'meta_key'       => '_pg_gallery_page',
        'meta_value'     => '1',
        'fields'         => 'ids',
        'posts_per_page' => 1,
    ] );

    if ( ! empty( $existing ) ) {
        restore_current_blog();
        return;
    }

    $page_id = wp_insert_post( [
        'post_type'    => 'page',
        'post_title'   => 'Gallery',
        'post_name'    => 'gallery',
        'post_content' => '[pg_gallery]',
        'post_status'  => 'publish',
    ] );

    if ( ! $page_id || is_wp_error( $page_id ) ) {
        restore_current_blog();
        return;
    }

    update_post_meta( $page_id, '_pg_gallery_page', '1' );

    // Create or reuse a nav menu named "Main Menu"
    $menu_name = 'Main Menu';
    $menu_id   = wp_create_nav_menu( $menu_name );
    if ( is_wp_error( $menu_id ) ) {
        $obj     = wp_get_nav_menu_object( $menu_name );
        $menu_id = $obj ? (int) $obj->term_id : 0;
    }

    if ( $menu_id ) {
        wp_update_nav_menu_item( $menu_id, 0, [
            'menu-item-title'     => 'Gallery',
            'menu-item-object'    => 'page',
            'menu-item-object-id' => $page_id,
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
        ] );

        // Assign to every unoccupied theme nav location
        $locations = get_theme_mod( 'nav_menu_locations', [] );
        foreach ( array_keys( (array) get_registered_nav_menus() ) as $location ) {
            if ( empty( $locations[ $location ] ) ) {
                $locations[ $location ] = $menu_id;
            }
        }
        set_theme_mod( 'nav_menu_locations', $locations );
    }

    restore_current_blog();
}

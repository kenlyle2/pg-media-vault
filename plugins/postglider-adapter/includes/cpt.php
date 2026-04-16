<?php
/**
 * PostGlider Adapter — pg_gallery_image Custom Post Type
 *
 * Each stub represents one AI-tagged image in PostGlider's Media Vault.
 * Structure:
 *   title   = tags joined with " · "
 *   content = <img> block + description paragraph
 *   excerpt = description
 *   tags    = each AI tag as a WP post_tag term (for SearchIQ facets)
 *
 * Images never leave Supabase Storage. SearchIQ indexes these stubs.
 *
 * Featured image faking:
 *   WP media library is not used. Instead we intercept _thumbnail_id at the
 *   metadata layer to return a negative post_id as a stand-in, then map that
 *   stand-in back to the Supabase URL stored in _pg_image_url post meta.
 *   This makes get_the_post_thumbnail_url() and SearchIQ thumbnails work
 *   without uploading anything to WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', function () {

    register_post_type( 'pg_gallery_image', [
        'labels' => [
            'name'          => esc_html__( 'Gallery Images',       'postglider-adapter' ),
            'singular_name' => esc_html__( 'Gallery Image',        'postglider-adapter' ),
            'search_items'  => esc_html__( 'Search Gallery Images', 'postglider-adapter' ),
            'not_found'     => esc_html__( 'No gallery images.',    'postglider-adapter' ),
        ],
        'public'            => true,
        'has_archive'       => false,
        'show_in_rest'      => true,
        'show_in_nav_menus' => false,
        'show_in_admin_bar' => false,
        'show_ui'           => false,
        'supports'          => [ 'title', 'editor', 'excerpt', 'thumbnail' ],
        'taxonomies'        => [ 'post_tag' ],
        'rewrite'           => [ 'slug' => 'gallery-image', 'with_front' => false ],
        'capability_type'   => 'post',
        'map_meta_cap'      => true,
    ] );

}, 10 );

/**
 * Flush rewrite rules once per plugin version so the gallery-image slug
 * resolves immediately after install or update — no manual Permalinks save needed.
 * Runs at priority 99, after register_post_type at priority 10.
 */
add_action( 'init', function () {
    if ( get_option( 'pg_rewrite_version' ) !== POSTGLIDER_ADAPTER_VERSION ) {
        flush_rewrite_rules();
        update_option( 'pg_rewrite_version', POSTGLIDER_ADAPTER_VERSION );
    }
}, 99 );

/**
 * Fake _thumbnail_id for pg_gallery_image posts.
 * Returns -$post_id as a stand-in so has_post_thumbnail() and
 * get_the_post_thumbnail_url() see a truthy value without a real attachment.
 * Only fires when _pg_image_url meta is present (set during sync).
 */
add_filter( 'get_post_metadata', function ( $value, $object_id, $meta_key, $single ) {
    if ( $meta_key !== '_thumbnail_id' ) return $value;
    if ( get_post_type( $object_id ) !== 'pg_gallery_image' ) return $value;
    $image_url = get_post_meta( $object_id, '_pg_image_url', true );
    if ( ! $image_url ) return $value;
    return $single ? -$object_id : [ -$object_id ];
}, 10, 4 );

/**
 * Map the stand-in attachment ID back to the real Supabase URL.
 * Intercepts wp_get_attachment_image_src() for negative IDs we created above.
 */
add_filter( 'wp_get_attachment_image_src', function ( $image, $attachment_id, $size, $icon ) {
    if ( $attachment_id >= 0 ) return $image;
    $post_id = -$attachment_id;
    if ( get_post_type( $post_id ) !== 'pg_gallery_image' ) return $image;
    $url = get_post_meta( $post_id, '_pg_image_url', true );
    if ( ! $url ) return $image;
    return [ esc_url_raw( $url ), 800, 600, false ];
}, 10, 4 );

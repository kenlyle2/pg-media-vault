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

    // pg_artist: flat taxonomy for named artists / stylists. SearchIQ Author-facet equivalent.
    register_taxonomy( 'pg_artist', 'pg_gallery_image', [
        'labels'            => [
            'name'          => esc_html__( 'Artists',  'postglider-adapter' ),
            'singular_name' => esc_html__( 'Artist',   'postglider-adapter' ),
        ],
        'hierarchical'      => false,
        'public'            => true,
        'show_in_rest'      => true,
        'show_ui'           => false,
        'show_tagcloud'     => false,
        'rewrite'           => [ 'slug' => 'artist', 'with_front' => false ],
    ] );

    // pg_content_type: hierarchical taxonomy for content categories.
    // Terms: portrait, workspace, before-after, product-shot, team-culture, promotional, client-spotlight
    register_taxonomy( 'pg_content_type', 'pg_gallery_image', [
        'labels'            => [
            'name'          => esc_html__( 'Content Types', 'postglider-adapter' ),
            'singular_name' => esc_html__( 'Content Type',  'postglider-adapter' ),
        ],
        'hierarchical'      => true,
        'public'            => true,
        'show_in_rest'      => true,
        'show_ui'           => false,
        'show_tagcloud'     => false,
        'rewrite'           => [ 'slug' => 'content-type', 'with_front' => false ],
    ] );

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
        'taxonomies'        => [ 'post_tag', 'pg_artist', 'pg_content_type' ],
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
 * Expose _pg_image_url in the REST API response under every field name that
 * common search plugins (SearchIQ, Relevanssi, Jetpack Search) look for.
 * Also corrects featured_media to 0 so the REST API doesn't try to resolve
 * our fake negative attachment ID as an embed.
 */
add_filter( 'rest_prepare_pg_gallery_image', function ( $response, $post, $request ) {
    $image_url = get_post_meta( $post->ID, '_pg_image_url', true );
    if ( ! $image_url ) return $response;

    $data = $response->get_data();

    // Set featured_media to 0 so WP doesn't try to embed a fake attachment
    $data['featured_media'] = 0;

    // Field names used by various search indexers
    $data['jetpack_featured_media_url'] = $image_url;   // Jetpack + many plugins
    $data['featured_image_url']         = $image_url;   // generic fallback
    $data['thumbnail_url']              = $image_url;   // SearchIQ-specific

    // Inject a minimal wp:featuredmedia embed so ?_embed=1 resolves correctly
    $data['_links']['wp:featuredmedia'] = [];
    if ( isset( $data['_embedded'] ) ) {
        $data['_embedded']['wp:featuredmedia'] = [ [
            'id'           => $post->ID,
            'source_url'   => $image_url,
            'media_details' => [
                'sizes' => [
                    'full'      => [ 'source_url' => $image_url ],
                    'large'     => [ 'source_url' => $image_url ],
                    'medium'    => [ 'source_url' => $image_url ],
                    'thumbnail' => [ 'source_url' => $image_url ],
                ],
            ],
        ] ];
    }

    $response->set_data( $data );
    return $response;
}, 10, 3 );

/**
 * Register _pg_image_url as a publicly readable REST meta field.
 * SearchIQ and other indexers can read this directly as post.meta._pg_image_url.
 */
add_action( 'init', function () {
    register_post_meta( 'pg_gallery_image', '_pg_image_url', [
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
        'auth_callback' => '__return_false',
    ] );
} );

/**
 * Inject og:image into the <head> of individual stub pages.
 * Catches any crawler that reads HTML rather than the REST API.
 */
add_action( 'wp_head', function () {
    if ( ! is_singular( 'pg_gallery_image' ) ) return;
    $image_url = get_post_meta( get_the_ID(), '_pg_image_url', true );
    if ( ! $image_url ) return;
    echo '<meta property="og:image" content="' . esc_url( $image_url ) . '">' . "\n";
    echo '<meta name="thumbnail" content="' . esc_url( $image_url ) . '">' . "\n";
}, 5 );

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

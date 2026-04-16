<?php
/**
 * PostGlider Adapter — pg_gallery_image Custom Post Type
 *
 * Each stub represents one AI-tagged image in PostGlider's Media Vault.
 * Structure:
 *   title   = tags joined with " · "  (e.g. "Landscape Lighting · Water Feature")
 *   content = <img> block + description paragraph
 *   excerpt = description
 *   tags    = each AI tag as a WP post_tag term
 *
 * The actual image files never leave Supabase Storage.
 * SearchIQ indexes these posts for semantic search across the gallery.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', function () {

    register_post_type( 'pg_gallery_image', [
        'labels' => [
            'name'               => esc_html__( 'Gallery Images',       'postglider-adapter' ),
            'singular_name'      => esc_html__( 'Gallery Image',        'postglider-adapter' ),
            'add_new_item'       => esc_html__( 'Add New Gallery Image', 'postglider-adapter' ),
            'edit_item'          => esc_html__( 'Edit Gallery Image',    'postglider-adapter' ),
            'search_items'       => esc_html__( 'Search Gallery Images', 'postglider-adapter' ),
            'not_found'          => esc_html__( 'No gallery images.',    'postglider-adapter' ),
        ],
        'public'            => true,
        'has_archive'       => false,          // no /gallery-image/ archive page
        'show_in_rest'      => true,           // REST API access (SearchIQ, Gutenberg)
        'show_in_nav_menus' => false,
        'show_in_admin_bar' => false,
        'show_ui'           => false,          // hidden from admin menu — managed via sync
        'supports'          => [ 'title', 'editor', 'excerpt' ],
        'taxonomies'        => [ 'post_tag' ], // built-in tags for SearchIQ indexing
        'rewrite'           => [ 'slug' => 'gallery-image', 'with_front' => false ],
        'capability_type'   => 'post',
        'map_meta_cap'      => true,
    ] );

} );

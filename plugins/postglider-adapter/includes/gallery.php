<?php
/**
 * PostGlider Adapter — [pg_gallery] shortcode
 *
 * Renders a responsive image grid from pg_gallery_image CPT posts.
 * Usage: [pg_gallery columns="3" limit="50"]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'pg_gallery', function ( $atts ) {
    $atts = shortcode_atts( [ 'columns' => 3, 'limit' => 50 ], $atts, 'pg_gallery' );

    $posts = get_posts( [
        'post_type'      => 'pg_gallery_image',
        'posts_per_page' => intval( $atts['limit'] ),
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    if ( empty( $posts ) ) {
        return '<p class="pg-gallery-empty">No images yet — check back soon.</p>';
    }

    $cols = max( 1, intval( $atts['columns'] ) );
    $out  = '<div class="pg-gallery" style="display:grid;grid-template-columns:repeat(' . $cols . ',1fr);gap:1rem;">';

    foreach ( $posts as $post ) {
        $img_url = get_post_meta( $post->ID, '_pg_image_url', true );
        if ( ! $img_url ) continue;

        $tags    = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
        $tag_str = $tags ? implode( ' · ', array_map( 'esc_html', $tags ) ) : '';

        $out .= '<figure class="pg-gallery-item" style="margin:0;">';
        $out .= '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $post->post_title ) . '" loading="lazy" style="width:100%;height:220px;object-fit:cover;border-radius:4px;">';
        if ( $tag_str ) {
            $out .= '<figcaption style="font-size:0.75rem;color:#666;margin-top:0.25rem;">' . $tag_str . '</figcaption>';
        }
        $out .= '</figure>';
    }

    $out .= '</div>';
    return $out;
} );

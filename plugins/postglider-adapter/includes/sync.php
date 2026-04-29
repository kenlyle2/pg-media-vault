<?php
/**
 * PostGlider Adapter — Image sync endpoint
 *
 * POST /wp-json/postglider/v1/sync-image
 * Auth: X-Gallery-Token header (must match stored postglider_gallery_token option)
 *
 * Creates or updates a pg_gallery_image stub post for one Media Vault image.
 * Called server-side by PostGlider (tagImageAction) after every successful tag.
 * Idempotent — uses a deterministic post slug derived from image_id.
 *
 * Request body:
 *   image_id    string  PostGlider UUID
 *   public_url  string  Supabase Storage public URL
 *   tags        string[] AI tags (lowercase)
 *   description string  One-sentence description
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'postglider/v1', '/sync-image', [
        'methods'             => 'POST',
        'callback'            => 'pg_sync_image_handler',
        'permission_callback' => 'pg_sync_image_auth',
        'args' => [
            'image_id' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'public_url' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_url',
            ],
            'tags' => [
                'required' => false,
                'type'     => 'array',
                'default'  => [],
                'items'    => [ 'type' => 'string' ],
            ],
            'description' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'artist' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'content_type' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );
} );

/**
 * Verify the request carries a gallery token matching this subsite's stored token.
 * Uses hash_equals() to prevent timing attacks.
 */
function pg_sync_image_auth( WP_REST_Request $request ): bool {
    $provided = $request->get_header( 'X-Gallery-Token' );
    $stored   = pg_get_option( 'gallery_token' );

    if ( ! $provided || ! $stored ) {
        return false;
    }

    return hash_equals( (string) $stored, (string) $provided );
}

function pg_sync_image_handler( WP_REST_Request $request ) {
    $image_id     = $request->get_param( 'image_id' );
    $public_url   = $request->get_param( 'public_url' );
    $tags         = array_values( array_filter( array_map(
        'sanitize_text_field',
        (array) $request->get_param( 'tags' )
    ) ) );
    $description  = (string) $request->get_param( 'description' );
    $artist       = (string) $request->get_param( 'artist' );
    $content_type = (string) $request->get_param( 'content_type' );

    // Title: first 8 tags, title-cased, joined with " · "
    $title_tags = array_map( 'ucwords', array_slice( $tags, 0, 8 ) );
    $title      = $title_tags ? implode( ' · ', $title_tags ) : 'Gallery Image';

    // Content: image block + description
    $img_url = esc_url( $public_url );
    $img_alt = esc_attr( $title );
    $content = "<img src=\"{$img_url}\" alt=\"{$img_alt}\" loading=\"lazy\">";
    if ( $description ) {
        $content .= "\n<p>" . esc_html( $description ) . '</p>';
    }

    // Deterministic slug so upserts work: pg-img-<first-36-chars-of-uuid>
    $post_slug = 'pg-img-' . substr( preg_replace( '/[^a-z0-9]/', '-', strtolower( $image_id ) ), 0, 36 );

    // Check for existing stub to update
    $existing_ids = get_posts( [
        'name'           => $post_slug,
        'post_type'      => 'pg_gallery_image',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );

    $post_data = [
        'post_type'    => 'pg_gallery_image',
        'post_name'    => $post_slug,
        'post_title'   => $title,
        'post_content' => $content,
        'post_excerpt' => $description,
        'post_status'  => 'publish',
    ];

    $is_new = empty( $existing_ids );

    if ( ! $is_new ) {
        $post_data['ID'] = $existing_ids[0];
        $post_id = wp_update_post( $post_data, true );
    } else {
        $post_id = wp_insert_post( $post_data, true );
    }

    if ( is_wp_error( $post_id ) ) {
        return new WP_Error(
            'pg_sync_failed',
            $post_id->get_error_message(),
            [ 'status' => 500 ]
        );
    }

    // Tags → post_tag: SearchIQ Tag facet
    if ( $tags ) {
        wp_set_post_tags( $post_id, $tags, false );
    }

    // Artist → pg_artist: SearchIQ Author-equivalent facet
    if ( $artist ) {
        wp_set_object_terms( $post_id, [ $artist ], 'pg_artist' );
    }

    // Content type → pg_content_type: SearchIQ Category-equivalent facet
    if ( $content_type ) {
        wp_set_object_terms( $post_id, [ $content_type ], 'pg_content_type' );
    }

    // Store Supabase URL in post meta — used by the featured image faking filter in cpt.php
    update_post_meta( $post_id, '_pg_image_url', $public_url );

    // Write _thumbnail_id to the database so SearchIQ can find it even if it
    // bypasses WordPress meta filters and queries wp_postmeta directly.
    // Value is the post's own ID; wp_get_attachment_image_src + wp_get_attachment_url
    // filters in cpt.php map this back to the real Supabase URL.
    update_post_meta( $post_id, '_thumbnail_id', $post_id );

    return rest_ensure_response( [
        'ok'       => true,
        'post_id'  => $post_id,
        'created'  => $is_new,
        'image_id' => $image_id,
    ] );
}

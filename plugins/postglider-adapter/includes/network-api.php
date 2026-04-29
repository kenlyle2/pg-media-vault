<?php
/**
 * PostGlider Adapter — Network configuration REST endpoint
 *
 * POST /wp-json/postglider/v1/configure-site
 * Auth: WordPress Application Password (super admin required)
 * Body: { "blog_id": 4, "supabase_url": "https://...", "gallery_token": "pg_gallery_...",
 *         "searchiq_api_key": "a4a0db93..." }
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
            'searchiq_api_key' => [
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

    // Ensure every super admin can see the new site in their My Sites list.
    foreach ( get_super_admins() as $login ) {
        $user = get_user_by( 'login', $login );
        if ( $user && ! is_user_member_of_blog( $user->ID, $blog_id ) ) {
            add_user_to_blog( $blog_id, $user->ID, 'administrator' );
        }
    }

    return rest_ensure_response( [ 'ok' => true, 'blog_id' => (int) $blog_id ] );
}

function pg_configure_site_handler( WP_REST_Request $request ) {
    $blog_id          = $request->get_param( 'blog_id' );
    $supabase_url     = $request->get_param( 'supabase_url' );
    $gallery_token    = $request->get_param( 'gallery_token' );
    $searchiq_api_key = $request->get_param( 'searchiq_api_key' );

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

    // Wire SearchIQ: write the authentication code and seed initial settings so
    // the plugin activates without requiring the admin to re-enter the key.
    if ( $searchiq_api_key ) {
        pg_configure_searchiq( $blog_id, $searchiq_api_key );
    }

    pg_setup_gallery_page( $blog_id );

    return rest_ensure_response( [
        'ok'              => true,
        'blog_id'         => $blog_id,
        'searchiq_wired'  => ! empty( $searchiq_api_key ),
    ] );
}

/**
 * Writes SearchIQ authentication + seed settings to the given subsite.
 * Calling this multiple times with the same key is safe (idempotent).
 * The SearchIQ plugin reads _siq_authentication_code on load; the seed
 * settings let it skip the "enter your API key" screen on first visit.
 * engineKey / searchEngines_id / siqSid are left at defaults — SearchIQ
 * will overwrite them on first authenticated sync.
 */
function pg_configure_searchiq( int $blog_id, string $api_key ): void {
    $details = get_blog_details( $blog_id );
    $domain  = $details ? $details->domain : '';
    $base    = $domain ? 'https://' . $domain . '/' : '';

    update_blog_option( $blog_id, '_siq_authentication_code', $api_key );

    // Build seed settings matching the SearchIQ plugin's expected stdClass format.
    $s = new stdClass();

    // Engine identity (SearchIQ-assigned on first sync — left at defaults)
    $s->engineKey          = '';
    $s->searchEngines_id   = 0;
    $s->siqSid             = '';

    // Site identity
    $s->domain             = $domain;
    $s->resultPageUrl      = $base . 'search/';
    $s->apiKey             = $api_key;

    // Search behaviour
    $s->postTypesForSearch       = 'post,page,pg_gallery_image';
    $s->autocompleteNumRecords   = 5;
    $s->customSearchNumRecords   = 10;
    $s->showACImages             = true;
    $s->disableAutocomplete      = false;
    $s->searchBoxName            = 's';
    $s->queryParameter           = 'q';
    $s->searchAlgorithm          = 'BROAD_MATCH';
    $s->sortBy                   = 'RELEVANCE';
    $s->resultPageLayout         = 'LIST';
    $s->typoSearchEnabled        = true;
    $s->enableKeywordSuggestions = true;
    $s->mobileEnabled            = true;
    $s->openResultInTab          = false;

    // Licensing
    $s->licensed                 = true;
    $s->hideLogo                 = true;
    $s->allowHideLogo            = true;
    $s->engineInactive           = false;
    $s->isProPack                = false;

    // Feature flags
    $s->enableFacetFeature           = true;
    $s->enablePostTypeFilter         = true;
    $s->enableThumbnailService       = true;
    $s->customSearchThumbnailsEnabled = true;
    $s->showAuthorAndDate            = true;
    $s->showCategory                 = true;
    $s->showTag                      = true;
    $s->thumbnailType                = 'crop';
    $s->autocompleteThumbnailType    = 'crop';
    $s->searchPageThumbnailType      = 'resize';
    $s->defaultNumberOfRecords       = 10;
    $s->autocompleteTextResults      = 'Results';
    $s->autocompleteTextPoweredBy    = 'powered by';
    $s->autocompleteTextMoreLink     = 'Show all # results';
    $s->customSearchBarPlaceholder   = 'Search images…';
    $s->noRecordsFoundText           = 'No records found';
    $s->paginationPrevText           = 'Prev';
    $s->paginationNextText           = 'Next';

    // Default facets: Date, Category, Tag, Author
    $s->facets = pg_siq_default_facets();

    update_blog_option( $blog_id, '_siq_raw_settings', $s );
}

/** Returns the four default SearchIQ facet objects. */
function pg_siq_default_facets(): array {
    $make = static function ( string $label, string $type, int $order, string $field, string $src, string $query ): stdClass {
        $f             = new stdClass();
        $f->label      = $label;
        $f->type       = $type;
        $f->order      = $order;
        $f->field      = $field;
        $f->srcField   = $src;
        $f->queryField = $query;
        $f->postType   = '_siq_all_posts';
        return $f;
    };

    return [
        $make( 'Date',     'DATE',   0, 'timestamp',      'timestamp',  'timestamp' ),
        $make( 'Category', 'STRING', 1, 'genericString1', 'categories', 'categories' ),
        $make( 'Tag',      'STRING', 2, 'genericString2', 'tags',       'tags' ),
        $make( 'Author',   'STRING', 3, 'genericString3', 'author',     'author' ),
    ];
}

/**
 * Creates (or updates) the Gallery page with the appropriate shortcode stack.
 * If SearchIQ is configured, the page content is [searchiq]\n\n[pg_gallery].
 * Calling this multiple times is safe — it always syncs content to current state.
 */
function pg_setup_gallery_page( int $blog_id ): void {
    switch_to_blog( $blog_id );

    $siq_key = get_option( '_siq_authentication_code' );
    $content = $siq_key
        ? "[searchiq]\n\n[pg_gallery]"
        : '[pg_gallery]';

    $existing = get_posts( [
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'meta_key'       => '_pg_gallery_page',
        'meta_value'     => '1',
        'fields'         => 'ids',
        'posts_per_page' => 1,
    ] );

    if ( ! empty( $existing ) ) {
        // Sync content — picks up newly added SearchIQ key on re-configure.
        wp_update_post( [
            'ID'           => $existing[0],
            'post_content' => $content,
        ] );
        restore_current_blog();
        return;
    }

    $page_id = wp_insert_post( [
        'post_type'    => 'page',
        'post_title'   => 'Gallery',
        'post_name'    => 'gallery',
        'post_content' => $content,
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

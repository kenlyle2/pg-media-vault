<?php
/**
 * PostGlider Adapter — Admin settings page
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function pg_admin_menu() {
    add_options_page(
        esc_html__( 'PostGlider Gallery', 'postglider-adapter' ),
        esc_html__( 'PostGlider', 'postglider-adapter' ),
        'manage_options',
        'postglider-settings',
        'pg_settings_page'
    );
}
add_action( 'admin_menu', 'pg_admin_menu' );

function pg_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['pg_save'] ) && check_admin_referer( 'pg_settings' ) ) {
        pg_set_option( 'supabase_url',  sanitize_url( wp_unslash( $_POST['pg_supabase_url'] ?? '' ) ) );
        pg_set_option( 'gallery_token', sanitize_text_field( wp_unslash( $_POST['pg_gallery_token'] ?? '' ) ) );
        echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'postglider-adapter' ) . '</p></div>';
    }

    $url   = esc_attr( pg_get_option( 'supabase_url' ) ?: '' );
    $token = esc_attr( pg_get_option( 'gallery_token' ) ?: '' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'PostGlider Gallery Settings', 'postglider-adapter' ); ?></h1>
        <form method="post">
            <?php wp_nonce_field( 'pg_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="pg_supabase_url"><?php esc_html_e( 'Supabase Project URL', 'postglider-adapter' ); ?></label></th>
                    <td>
                        <input name="pg_supabase_url" id="pg_supabase_url" type="url"
                               class="regular-text" value="<?php echo $url; ?>"
                               placeholder="https://xxxx.supabase.co" />
                    </td>
                </tr>
                <tr>
                    <th><label for="pg_gallery_token"><?php esc_html_e( 'Gallery Token', 'postglider-adapter' ); ?></label></th>
                    <td>
                        <input name="pg_gallery_token" id="pg_gallery_token" type="password"
                               class="large-text" value="<?php echo $token; ?>" />
                        <p class="description"><?php esc_html_e( 'Gallery token from PostGlider Settings. Scopes search to this account\'s Media Vault.', 'postglider-adapter' ); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <strong><?php esc_html_e( 'Search endpoint:', 'postglider-adapter' ); ?></strong><br>
                <code><?php echo esc_url( rest_url( 'postglider/v1/search' ) ); ?></code>
            </p>
            <?php submit_button( esc_html__( 'Save Settings', 'postglider-adapter' ), 'primary', 'pg_save' ); ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Update Check', 'postglider-adapter' ); ?></h2>
        <?php
        if ( isset( $_GET['pg_check_now'] ) && current_user_can( 'manage_network' ) ) {
            delete_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT );
            $meta = pg_fetch_metadata();
            if ( $meta ) {
                echo '<div class="updated"><p>';
                printf(
                    esc_html__( 'Metadata fetched successfully. Latest version: %s. Installed: %s.', 'postglider-adapter' ),
                    '<strong>' . esc_html( $meta->version ) . '</strong>',
                    '<strong>' . esc_html( POSTGLIDER_ADAPTER_VERSION ) . '</strong>'
                );
                echo '</p></div>';
            } else {
                $err = get_site_option( 'pg_adapter_update_error', 'Unknown error' );
                echo '<div class="error"><p>';
                printf( esc_html__( 'Metadata fetch FAILED: %s', 'postglider-adapter' ), '<strong>' . esc_html( $err ) . '</strong>' );
                echo '</p></div>';
            }
        }

        $last_error = get_site_option( 'pg_adapter_update_error' );
        if ( $last_error ) {
            echo '<div class="error"><p>';
            printf( esc_html__( 'Last update check error: %s', 'postglider-adapter' ), '<strong>' . esc_html( $last_error ) . '</strong>' );
            echo '</p></div>';
        }

        $check_url = add_query_arg( 'pg_check_now', '1', admin_url( 'options-general.php?page=postglider-settings' ) );
        echo '<p>';
        printf(
            '<a href="%s" class="button">%s</a> &nbsp; <strong>%s</strong> %s',
            esc_url( $check_url ),
            esc_html__( 'Test Metadata Fetch', 'postglider-adapter' ),
            esc_html__( 'Plugin file:', 'postglider-adapter' ),
            '<code>' . esc_html( POSTGLIDER_ADAPTER_PLUGIN_FILE ) . '</code>'
        );
        echo '</p>';
        ?>
    </div>
    <?php
}

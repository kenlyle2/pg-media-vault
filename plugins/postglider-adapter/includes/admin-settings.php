<?php
/**
 * PostGlider Adapter — Admin settings page
 *
 * Appears under Settings on individual subsites AND under Network Admin → Settings,
 * so super admins can trigger update checks without leaving the network admin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Register under individual subsite Settings menu
add_action( 'admin_menu', function () {
    add_options_page(
        esc_html__( 'PostGlider Gallery', 'postglider-adapter' ),
        esc_html__( 'PostGlider', 'postglider-adapter' ),
        'manage_options',
        'postglider-settings',
        'pg_settings_page'
    );
} );

// Also register under Network Admin → Settings so update checks work from there
add_action( 'network_admin_menu', function () {
    add_submenu_page(
        'settings.php',
        esc_html__( 'PostGlider Gallery', 'postglider-adapter' ),
        esc_html__( 'PostGlider', 'postglider-adapter' ),
        'manage_network_options',
        'postglider-settings',
        'pg_settings_page'
    );
} );

function pg_settings_page() {
    $is_network = is_network_admin();
    $can_manage = $is_network ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
    if ( ! $can_manage ) return;

    // Save subsite credentials (subsite admin only)
    if ( ! $is_network && isset( $_POST['pg_save'] ) && check_admin_referer( 'pg_settings' ) ) {
        pg_set_option( 'supabase_url',  sanitize_url( wp_unslash( $_POST['pg_supabase_url'] ?? '' ) ) );
        pg_set_option( 'gallery_token', sanitize_text_field( wp_unslash( $_POST['pg_gallery_token'] ?? '' ) ) );
        echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'postglider-adapter' ) . '</p></div>';
    }

    // "Check for Updates" button — clears both our metadata transient and WP's update_plugins transient
    if ( isset( $_GET['pg_check_now'] ) && check_admin_referer( 'pg_check_now' ) ) {
        delete_site_transient( POSTGLIDER_ADAPTER_METADATA_TRANSIENT );
        delete_site_transient( 'update_plugins' ); // forces WP to re-run the entire update check
        $meta = pg_fetch_metadata();
        if ( $meta ) {
            echo '<div class="updated"><p>';
            printf(
                /* translators: 1: latest version, 2: installed version */
                esc_html__( 'Metadata fetched. Latest: %1$s — Installed: %2$s.', 'postglider-adapter' ),
                '<strong>' . esc_html( $meta->version ) . '</strong>',
                '<strong>' . esc_html( POSTGLIDER_ADAPTER_VERSION ) . '</strong>'
            );
            if ( version_compare( $meta->version, POSTGLIDER_ADAPTER_VERSION, '>' ) ) {
                $updates_url = $is_network
                    ? network_admin_url( 'update-core.php' )
                    : admin_url( 'update-core.php' );
                echo ' <a href="' . esc_url( $updates_url ) . '">' . esc_html__( 'Go to Updates →', 'postglider-adapter' ) . '</a>';
            }
            echo '</p></div>';
        } else {
            $err = get_site_option( 'pg_adapter_update_error', 'Unknown error' );
            echo '<div class="error"><p>';
            printf(
                esc_html__( 'Metadata fetch failed: %s', 'postglider-adapter' ),
                '<strong>' . esc_html( $err ) . '</strong>'
            );
            echo '</p></div>';
        }
    }

    $url   = esc_attr( pg_get_option( 'supabase_url' ) ?: '' );
    $token = esc_attr( pg_get_option( 'gallery_token' ) ?: '' );

    $settings_url = $is_network
        ? network_admin_url( 'settings.php?page=postglider-settings' )
        : admin_url( 'options-general.php?page=postglider-settings' );
    $check_url = wp_nonce_url( add_query_arg( 'pg_check_now', '1', $settings_url ), 'pg_check_now' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'PostGlider Gallery Settings', 'postglider-adapter' ); ?></h1>

        <?php if ( ! $is_network ) : ?>
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
                        <p class="description"><?php esc_html_e( 'Gallery token from PostGlider Settings.', 'postglider-adapter' ); ?></p>
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
        <?php endif; ?>

        <h2><?php esc_html_e( 'Plugin Updates', 'postglider-adapter' ); ?></h2>
        <p>
            <?php
            $last_error = get_site_option( 'pg_adapter_update_error' );
            if ( $last_error ) {
                echo '<div class="notice notice-error inline"><p>';
                printf( esc_html__( 'Last update check error: %s', 'postglider-adapter' ), '<strong>' . esc_html( $last_error ) . '</strong>' );
                echo '</p></div>';
            }
            ?>
        </p>
        <p>
            <strong><?php esc_html_e( 'Installed version:', 'postglider-adapter' ); ?></strong>
            <code><?php echo esc_html( POSTGLIDER_ADAPTER_VERSION ); ?></code>
            &nbsp;&nbsp;
            <strong><?php esc_html_e( 'Plugin file:', 'postglider-adapter' ); ?></strong>
            <code><?php echo esc_html( POSTGLIDER_ADAPTER_PLUGIN_FILE ); ?></code>
        </p>
        <p>
            <a href="<?php echo esc_url( $check_url ); ?>" class="button button-primary">
                <?php esc_html_e( 'Check for Updates Now', 'postglider-adapter' ); ?>
            </a>
        </p>
    </div>
    <?php
}

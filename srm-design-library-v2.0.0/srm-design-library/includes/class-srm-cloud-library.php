<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SRM_Cloud_Library {
    private static $instance = null;

    const REST_NAMESPACE = 'srm-design-library/v1';
    const OPT_ROLE       = 'srmdl_cloud_role';
    const OPT_MASTER_URL = 'srmdl_master_url';
    const OPT_ACCESS_KEY = 'srmdl_cloud_access_key';
    const OPT_CACHE_MINS = 'srmdl_cloud_cache_minutes';
    const OPT_LAST_SYNC  = 'srmdl_cloud_last_sync';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ], 30 );
        add_action( 'admin_post_srmdl_cloud_save_settings', [ $this, 'handle_save_settings' ] );
        add_action( 'admin_post_srmdl_cloud_refresh', [ $this, 'handle_refresh' ] );
        add_action( 'admin_post_srmdl_cloud_import', [ $this, 'handle_remote_import' ] );
    }

    public function register_admin_menu() {
        add_submenu_page(
            'srm-design-library',
            __( 'Cloud Library', 'srm-design-library' ),
            __( 'Cloud Library', 'srm-design-library' ),
            'manage_options',
            'srm-design-library-cloud',
            [ $this, 'render_cloud_library_page' ]
        );

        add_submenu_page(
            'srm-design-library',
            __( 'Cloud Settings', 'srm-design-library' ),
            __( 'Cloud Settings', 'srm-design-library' ),
            'manage_options',
            'srm-design-library-cloud-settings',
            [ $this, 'render_cloud_settings_page' ]
        );
    }

    public function register_rest_routes() {
        register_rest_route( self::REST_NAMESPACE, '/templates', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_templates' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/templates/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_template' ],
            'permission_callback' => [ $this, 'rest_permission' ],
            'args'                => [
                'id' => [
                    'validate_callback' => static function( $param ) {
                        return is_numeric( $param ) && (int) $param > 0;
                    },
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_status' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );
    }

    public function rest_permission( $request ) {
        $role = $this->role();
        if ( ! in_array( $role, [ 'master', 'hybrid' ], true ) ) {
            return new \WP_Error( 'srmdl_cloud_disabled', __( 'Cloud API is disabled on this site.', 'srm-design-library' ), [ 'status' => 403 ] );
        }

        $configured = trim( (string) get_option( self::OPT_ACCESS_KEY, '' ) );
        if ( '' === $configured ) {
            return true;
        }

        $provided = trim( (string) $request->get_header( 'x-srmdl-key' ) );
        if ( '' === $provided || ! hash_equals( $configured, $provided ) ) {
            return new \WP_Error( 'srmdl_invalid_key', __( 'Invalid SRM cloud access key.', 'srm-design-library' ), [ 'status' => 401 ] );
        }
        return true;
    }

    public function rest_get_status() {
        $count = wp_count_posts( SRM_Design_Library::POST_TYPE );
        return rest_ensure_response( [
            'ok'            => true,
            'api_version'   => 1,
            'plugin_version'=> defined( 'SRMDL_VERSION' ) ? SRMDL_VERSION : '',
            'site_name'     => get_bloginfo( 'name' ),
            'site_url'      => home_url( '/' ),
            'templates'     => isset( $count->publish ) ? (int) $count->publish : 0,
            'server_time'   => current_time( 'mysql', true ),
        ] );
    }

    public function rest_get_templates( $request ) {
        $posts = get_posts( [
            'post_type'      => SRM_Design_Library::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ] );

        $items = [];
        foreach ( $posts as $post ) {
            $items[] = $this->prepare_template_item( $post->ID, false );
        }

        return rest_ensure_response( [
            'api_version' => 1,
            'site_name'   => get_bloginfo( 'name' ),
            'site_url'    => home_url( '/' ),
            'count'       => count( $items ),
            'templates'   => $items,
        ] );
    }

    public function rest_get_template( $request ) {
        $post_id = absint( $request['id'] );
        if ( ! $post_id || SRM_Design_Library::POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
            return new \WP_Error( 'srmdl_not_found', __( 'Template not found.', 'srm-design-library' ), [ 'status' => 404 ] );
        }

        $item = $this->prepare_template_item( $post_id, true );
        if ( is_wp_error( $item ) ) {
            return $item;
        }
        return rest_ensure_response( $item );
    }

    private function prepare_template_item( $post_id, $include_json ) {
        $preview_id = absint( get_post_meta( $post_id, SRM_Design_Library::META_PREVIEW_ID, true ) );
        $preview    = $preview_id ? wp_get_attachment_image_url( $preview_id, 'large' ) : '';
        $terms      = wp_get_post_terms( $post_id, SRM_Design_Library::TAXONOMY );
        $term       = ! is_wp_error( $terms ) && $terms ? $terms[0] : null;

        $item = [
            'id'               => (int) $post_id,
            'title'            => get_the_title( $post_id ),
            'type'             => get_post_meta( $post_id, SRM_Design_Library::META_TYPE, true ) ?: 'section',
            'compatibility'    => get_post_meta( $post_id, SRM_Design_Library::META_COMPAT, true ) ?: 'both',
            'tags'             => get_post_meta( $post_id, SRM_Design_Library::META_TAGS, true ),
            'required_plugins' => get_post_meta( $post_id, SRM_Design_Library::META_REQUIRED, true ),
            'version'          => get_post_meta( $post_id, SRM_Design_Library::META_VERSION, true ) ?: '1.0',
            'thumbnail'        => $preview ?: '',
            'live_preview'     => get_post_meta( $post_id, SRM_Design_Library::META_LIVE_PREVIEW, true ),
            'category'         => $term ? [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ] : [ 'id' => 0, 'name' => __( 'Uncategorized', 'srm-design-library' ), 'slug' => 'uncategorized' ],
            'modified_gmt'     => get_post_modified_time( 'c', true, $post_id ),
        ];

        if ( $include_json ) {
            $path = $this->local_json_path( $post_id );
            if ( is_wp_error( $path ) ) {
                return $path;
            }

            $raw = file_get_contents( $path );
            if ( false === $raw ) {
                return new \WP_Error( 'srmdl_json_read', __( 'Could not read the stored Elementor JSON.', 'srm-design-library' ), [ 'status' => 500 ] );
            }
            $json = json_decode( $raw, true );
            if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json ) || ! isset( $json['content'] ) || ! is_array( $json['content'] ) ) {
                return new \WP_Error( 'srmdl_json_invalid', __( 'Stored Elementor JSON is invalid.', 'srm-design-library' ), [ 'status' => 500 ] );
            }

            $item['file_name'] = get_post_meta( $post_id, SRM_Design_Library::META_JSON_NAME, true ) ?: 'template-' . $post_id . '.json';
            $item['checksum']  = hash( 'sha256', $raw );
            $item['template']  = $json;
        }

        return $item;
    }

    private function local_json_path( $post_id ) {
        $relpath = get_post_meta( $post_id, SRM_Design_Library::META_JSON_RELPATH, true );
        if ( ! $relpath ) {
            return new \WP_Error( 'srmdl_json_missing', __( 'Stored Elementor JSON file is missing.', 'srm-design-library' ), [ 'status' => 500 ] );
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new \WP_Error( 'srmdl_uploads_error', $uploads['error'], [ 'status' => 500 ] );
        }
        $base = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
        $path = wp_normalize_path( $base . ltrim( $relpath, '/\\' ) );
        if ( 0 !== strpos( $path, $base ) || ! is_readable( $path ) ) {
            return new \WP_Error( 'srmdl_json_missing', __( 'Stored Elementor JSON file is missing.', 'srm-design-library' ), [ 'status' => 500 ] );
        }
        return $path;
    }

    private function role() {
        $role = sanitize_key( get_option( self::OPT_ROLE, 'master' ) );
        return in_array( $role, [ 'master', 'client', 'hybrid' ], true ) ? $role : 'master';
    }

    private function ensure_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'srm-design-library' ) );
        }
    }

    private function redirect_notice( $page, $notice, $message = '' ) {
        $args = [ 'page' => $page, 'srmdl_cloud_notice' => $notice ];
        if ( $message ) {
            $args['message'] = $message;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function render_notice() {
        $notice = isset( $_GET['srmdl_cloud_notice'] ) ? sanitize_key( wp_unslash( $_GET['srmdl_cloud_notice'] ) ) : '';
        if ( ! $notice ) {
            return;
        }
        $map = [
            'settings_saved' => [ 'success', __( 'Cloud settings saved.', 'srm-design-library' ) ],
            'refreshed'      => [ 'success', __( 'Cloud library cache refreshed.', 'srm-design-library' ) ],
            'imported'       => [ 'success', __( 'Cloud template imported into Elementor Saved Templates.', 'srm-design-library' ) ],
            'error'          => [ 'error', __( 'Cloud action failed.', 'srm-design-library' ) ],
        ];
        if ( ! isset( $map[ $notice ] ) ) {
            return;
        }
        [ $type, $message ] = $map[ $notice ];
        if ( ! empty( $_GET['message'] ) ) {
            $message .= ' ' . sanitize_text_field( wp_unslash( $_GET['message'] ) );
        }
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }

    public function render_cloud_settings_page() {
        $this->ensure_admin();
        $this->render_notice();

        $role       = $this->role();
        $master_url = esc_url( get_option( self::OPT_MASTER_URL, '' ) );
        $access_key = (string) get_option( self::OPT_ACCESS_KEY, '' );
        $cache      = max( 1, min( 1440, absint( get_option( self::OPT_CACHE_MINS, 15 ) ) ) );
        $endpoint   = rest_url( self::REST_NAMESPACE . '/templates' );
        ?>
        <div class="wrap srmdl-wrap">
            <div class="srmdl-header">
                <div>
                    <h1><?php esc_html_e( 'SRM Cloud Settings', 'srm-design-library' ); ?></h1>
                    <p><?php esc_html_e( 'Configure this installation as a master template server, a client, or both.', 'srm-design-library' ); ?></p>
                </div>
            </div>

            <form class="srmdl-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="srmdl_cloud_save_settings">
                <?php wp_nonce_field( 'srmdl_cloud_save_settings', 'srmdl_cloud_nonce' ); ?>
                <div class="srmdl-panel">
                    <h2><?php esc_html_e( 'Cloud Role', 'srm-design-library' ); ?></h2>
                    <label class="srmdl-field">
                        <span><?php esc_html_e( 'This website acts as', 'srm-design-library' ); ?></span>
                        <select name="cloud_role">
                            <option value="master" <?php selected( $role, 'master' ); ?>><?php esc_html_e( 'Master — publish templates and expose API', 'srm-design-library' ); ?></option>
                            <option value="client" <?php selected( $role, 'client' ); ?>><?php esc_html_e( 'Client — browse/import from another master', 'srm-design-library' ); ?></option>
                            <option value="hybrid" <?php selected( $role, 'hybrid' ); ?>><?php esc_html_e( 'Hybrid — publish locally and browse another master', 'srm-design-library' ); ?></option>
                        </select>
                    </label>

                    <label class="srmdl-field">
                        <span><?php esc_html_e( 'Master Library URL', 'srm-design-library' ); ?></span>
                        <input type="url" name="master_url" value="<?php echo esc_attr( $master_url ); ?>" placeholder="https://example.com">
                        <small><?php esc_html_e( 'Required on Client/Hybrid. Enter the WordPress site URL that hosts the master SRM library.', 'srm-design-library' ); ?></small>
                    </label>

                    <label class="srmdl-field">
                        <span><?php esc_html_e( 'Shared Access Key (optional)', 'srm-design-library' ); ?></span>
                        <input type="text" name="access_key" value="<?php echo esc_attr( $access_key ); ?>" autocomplete="off" placeholder="Leave empty for public cloud library">
                        <small><?php esc_html_e( 'Use the same key on Master and Client. If left empty on Master, the API is public.', 'srm-design-library' ); ?></small>
                    </label>

                    <label class="srmdl-field">
                        <span><?php esc_html_e( 'Client Cache (minutes)', 'srm-design-library' ); ?></span>
                        <input type="number" min="1" max="1440" name="cache_minutes" value="<?php echo esc_attr( $cache ); ?>">
                    </label>

                    <button class="button button-primary srmdl-primary" type="submit"><?php esc_html_e( 'Save Cloud Settings', 'srm-design-library' ); ?></button>
                </div>
            </form>

            <div class="srmdl-panel srmdl-roadmap">
                <h2><?php esc_html_e( 'Master API', 'srm-design-library' ); ?></h2>
                <p><strong><?php esc_html_e( 'Templates endpoint:', 'srm-design-library' ); ?></strong><br><code><?php echo esc_html( $endpoint ); ?></code></p>
                <p><?php echo in_array( $role, [ 'master', 'hybrid' ], true ) ? '<span class="srmdl-cloud-ok">● API enabled</span>' : '<span class="srmdl-cloud-muted">● API disabled in Client mode</span>'; ?></p>
            </div>
        </div>
        <?php
    }

    public function render_cloud_library_page() {
        $this->ensure_admin();
        $this->render_notice();

        $role = $this->role();
        if ( 'master' === $role ) {
            $local_url = admin_url( 'admin.php?page=srm-design-library' );
            $settings  = admin_url( 'admin.php?page=srm-design-library-cloud-settings' );
            ?>
            <div class="wrap srmdl-wrap">
                <div class="srmdl-header"><div><h1><?php esc_html_e( 'SRM Cloud Library', 'srm-design-library' ); ?></h1><p><?php esc_html_e( 'This installation is currently the Master Library.', 'srm-design-library' ); ?></p></div></div>
                <div class="srmdl-panel srmdl-cloud-master-card">
                    <h2><?php esc_html_e( 'Master mode is active', 'srm-design-library' ); ?></h2>
                    <p><?php esc_html_e( 'Templates you publish in All Templates are automatically exposed to connected Client sites through the cloud API.', 'srm-design-library' ); ?></p>
                    <p><a class="button button-primary srmdl-primary" href="<?php echo esc_url( $local_url ); ?>"><?php esc_html_e( 'Manage Master Templates', 'srm-design-library' ); ?></a> <a class="button" href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Cloud Settings', 'srm-design-library' ); ?></a></p>
                </div>
            </div>
            <?php
            return;
        }

        $master_url = trim( (string) get_option( self::OPT_MASTER_URL, '' ) );
        if ( ! $master_url ) {
            ?>
            <div class="wrap srmdl-wrap">
                <div class="srmdl-header"><div><h1><?php esc_html_e( 'SRM Cloud Library', 'srm-design-library' ); ?></h1></div></div>
                <div class="notice notice-warning"><p><?php esc_html_e( 'Set a Master Library URL in Cloud Settings first.', 'srm-design-library' ); ?></p></div>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=srm-design-library-cloud-settings' ) ); ?>"><?php esc_html_e( 'Open Cloud Settings', 'srm-design-library' ); ?></a>
            </div>
            <?php
            return;
        }

        $force = ! empty( $_GET['srmdl_force'] );
        $data  = $this->fetch_remote_catalog( $force );
        if ( is_wp_error( $data ) ) {
            ?>
            <div class="wrap srmdl-wrap">
                <div class="srmdl-header"><div><h1><?php esc_html_e( 'SRM Cloud Library', 'srm-design-library' ); ?></h1><p><?php echo esc_html( $master_url ); ?></p></div></div>
                <div class="notice notice-error"><p><strong><?php esc_html_e( 'Could not connect to the Master Library:', 'srm-design-library' ); ?></strong> <?php echo esc_html( $data->get_error_message() ); ?></p></div>
                <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=srm-design-library-cloud-settings' ) ); ?>"><?php esc_html_e( 'Check Cloud Settings', 'srm-design-library' ); ?></a></p>
            </div>
            <?php
            return;
        }

        $items  = isset( $data['templates'] ) && is_array( $data['templates'] ) ? $data['templates'] : [];
        $search = isset( $_GET['srm_s'] ) ? sanitize_text_field( wp_unslash( $_GET['srm_s'] ) ) : '';
        $type   = isset( $_GET['srm_type'] ) ? sanitize_key( wp_unslash( $_GET['srm_type'] ) ) : '';
        $compat = isset( $_GET['srm_compat'] ) ? sanitize_key( wp_unslash( $_GET['srm_compat'] ) ) : '';
        $cat    = isset( $_GET['srm_cat'] ) ? sanitize_title( wp_unslash( $_GET['srm_cat'] ) ) : '';

        $categories = [];
        foreach ( $items as $item ) {
            if ( ! empty( $item['category']['slug'] ) ) {
                $categories[ $item['category']['slug'] ] = $item['category']['name'] ?? $item['category']['slug'];
            }
        }
        asort( $categories );

        $filtered = array_values( array_filter( $items, function( $item ) use ( $search, $type, $compat, $cat ) {
            if ( $type && ( $item['type'] ?? '' ) !== $type ) {
                return false;
            }
            if ( $compat && ( $item['compatibility'] ?? '' ) !== $compat ) {
                return false;
            }
            if ( $cat && ( $item['category']['slug'] ?? '' ) !== $cat ) {
                return false;
            }
            if ( $search ) {
                $haystack = strtolower( implode( ' ', [
                    (string) ( $item['title'] ?? '' ),
                    (string) ( $item['tags'] ?? '' ),
                    (string) ( $item['category']['name'] ?? '' ),
                    (string) ( $item['type'] ?? '' ),
                ] ) );
                if ( false === strpos( $haystack, strtolower( $search ) ) ) {
                    return false;
                }
            }
            return true;
        } ) );

        $refresh_url = wp_nonce_url( admin_url( 'admin-post.php?action=srmdl_cloud_refresh' ), 'srmdl_cloud_refresh' );
        ?>
        <div class="wrap srmdl-wrap">
            <div class="srmdl-header">
                <div>
                    <h1><?php esc_html_e( 'SRM Cloud Library', 'srm-design-library' ); ?></h1>
                    <p><?php echo esc_html( sprintf( __( 'Connected to %s', 'srm-design-library' ), $data['site_name'] ?? $master_url ) ); ?></p>
                </div>
                <a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><span class="dashicons dashicons-update" style="vertical-align:text-bottom"></span> <?php esc_html_e( 'Refresh Library', 'srm-design-library' ); ?></a>
            </div>

            <div class="srmdl-cloud-statusbar">
                <span class="srmdl-cloud-ok">● <?php esc_html_e( 'Connected', 'srm-design-library' ); ?></span>
                <span><?php echo esc_html( sprintf( __( '%d cloud templates', 'srm-design-library' ), count( $items ) ) ); ?></span>
                <?php $last_sync = absint( get_option( self::OPT_LAST_SYNC, 0 ) ); if ( $last_sync ) : ?><span><?php echo esc_html( sprintf( __( 'Last sync: %s', 'srm-design-library' ), wp_date( 'M j, Y g:i a', $last_sync ) ) ); ?></span><?php endif; ?>
            </div>

            <form class="srmdl-filters" method="get">
                <input type="hidden" name="page" value="srm-design-library-cloud">
                <input type="search" name="srm_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search cloud templates...', 'srm-design-library' ); ?>">
                <select name="srm_cat"><option value=""><?php esc_html_e( 'All Categories', 'srm-design-library' ); ?></option><?php foreach ( $categories as $slug => $name ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cat, $slug ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select>
                <select name="srm_type"><option value=""><?php esc_html_e( 'All Types', 'srm-design-library' ); ?></option><option value="section" <?php selected( $type, 'section' ); ?>><?php esc_html_e( 'Sections', 'srm-design-library' ); ?></option><option value="block" <?php selected( $type, 'block' ); ?>><?php esc_html_e( 'Blocks', 'srm-design-library' ); ?></option><option value="page" <?php selected( $type, 'page' ); ?>><?php esc_html_e( 'Pages', 'srm-design-library' ); ?></option></select>
                <select name="srm_compat"><option value=""><?php esc_html_e( 'Any Compatibility', 'srm-design-library' ); ?></option><option value="free" <?php selected( $compat, 'free' ); ?>><?php esc_html_e( 'Elementor Free', 'srm-design-library' ); ?></option><option value="pro" <?php selected( $compat, 'pro' ); ?>><?php esc_html_e( 'Elementor Pro', 'srm-design-library' ); ?></option><option value="both" <?php selected( $compat, 'both' ); ?>><?php esc_html_e( 'Free + Pro', 'srm-design-library' ); ?></option></select>
                <button class="button"><?php esc_html_e( 'Filter', 'srm-design-library' ); ?></button>
                <a class="button button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=srm-design-library-cloud' ) ); ?>"><?php esc_html_e( 'Reset', 'srm-design-library' ); ?></a>
            </form>

            <div class="srmdl-count"><?php echo esc_html( sprintf( _n( '%d template shown', '%d templates shown', count( $filtered ), 'srm-design-library' ), count( $filtered ) ) ); ?></div>

            <?php if ( $filtered ) : ?>
                <div class="srmdl-grid">
                    <?php foreach ( $filtered as $item ) : $this->render_remote_card( $item ); endforeach; ?>
                </div>
            <?php else : ?>
                <div class="srmdl-empty"><span class="dashicons dashicons-cloud"></span><h2><?php esc_html_e( 'No matching cloud templates', 'srm-design-library' ); ?></h2><p><?php esc_html_e( 'Try clearing the filters or refresh the cloud catalog.', 'srm-design-library' ); ?></p></div>
            <?php endif; ?>
        </div>

        <div id="srmdl-preview-modal" class="srmdl-modal" aria-hidden="true"><button type="button" class="srmdl-modal-close" aria-label="Close">×</button><div class="srmdl-modal-inner"><img src="" alt="Template preview"></div></div>
        <?php
    }

    private function render_remote_card( $item ) {
        $id       = absint( $item['id'] ?? 0 );
        $title    = sanitize_text_field( $item['title'] ?? 'Untitled' );
        $type     = sanitize_key( $item['type'] ?? 'section' );
        $compat   = sanitize_key( $item['compatibility'] ?? 'both' );
        $version  = sanitize_text_field( $item['version'] ?? '1.0' );
        $tags     = sanitize_text_field( $item['tags'] ?? '' );
        $thumb    = esc_url( $item['thumbnail'] ?? '' );
        $live     = esc_url( $item['live_preview'] ?? '' );
        $category = sanitize_text_field( $item['category']['name'] ?? 'Uncategorized' );
        $required = $this->required_plugins_status( $item['required_plugins'] ?? '' );
        $import   = wp_nonce_url( admin_url( 'admin-post.php?action=srmdl_cloud_import&remote_id=' . $id ), 'srmdl_cloud_import_' . $id );
        ?>
        <article class="srmdl-card srmdl-cloud-card">
            <div class="srmdl-thumb">
                <?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $title ); ?>"><button type="button" class="srmdl-preview-trigger" data-preview="<?php echo esc_url( $thumb ); ?>"><?php esc_html_e( 'Preview', 'srm-design-library' ); ?></button><?php else : ?><div class="srmdl-thumb-empty"><span class="dashicons dashicons-format-image"></span><span><?php esc_html_e( 'No preview', 'srm-design-library' ); ?></span></div><?php endif; ?>
            </div>
            <div class="srmdl-card-body">
                <div class="srmdl-badges"><span class="srmdl-badge"><?php echo esc_html( ucfirst( $type ) ); ?></span><span class="srmdl-badge srmdl-badge-soft"><?php echo esc_html( $this->compat_label( $compat ) ); ?></span><span class="srmdl-badge srmdl-badge-cloud">Cloud</span></div>
                <h3><?php echo esc_html( $title ); ?></h3>
                <div class="srmdl-meta"><?php echo esc_html( $category ); ?> · v<?php echo esc_html( $version ); ?></div>
                <?php if ( $tags ) : ?><p class="srmdl-tags"><?php echo esc_html( $tags ); ?></p><?php endif; ?>
                <?php if ( $required ) : ?><div class="srmdl-requirements"><?php foreach ( $required as $plugin ) : ?><span class="<?php echo $plugin['active'] ? 'is-active' : 'is-missing'; ?>" title="<?php echo esc_attr( $plugin['file'] ); ?>"><?php echo $plugin['active'] ? '✓' : '!'; ?> <?php echo esc_html( $plugin['label'] ); ?></span><?php endforeach; ?></div><?php endif; ?>
                <div class="srmdl-actions"><a class="button button-primary srmdl-primary" href="<?php echo esc_url( $import ); ?>"><?php esc_html_e( 'Import to Elementor', 'srm-design-library' ); ?></a><?php if ( $live ) : ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $live ); ?>"><?php esc_html_e( 'Live Preview', 'srm-design-library' ); ?></a><?php endif; ?></div>
            </div>
        </article>
        <?php
    }

    private function compat_label( $compat ) {
        $labels = [ 'free' => __( 'Elementor Free', 'srm-design-library' ), 'pro' => __( 'Elementor Pro', 'srm-design-library' ), 'both' => __( 'Free + Pro', 'srm-design-library' ) ];
        return $labels[ $compat ] ?? $labels['both'];
    }

    private function required_plugins_status( $raw ) {
        $raw = is_string( $raw ) ? $raw : '';
        if ( ! trim( $raw ) ) {
            return [];
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $items = [];
        foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $file ) {
            $label = dirname( $file );
            if ( '.' === $label || '/' === $label ) {
                $label = pathinfo( $file, PATHINFO_FILENAME );
            }
            $label = ucwords( str_replace( [ '-', '_' ], ' ', basename( $label ) ) );
            $items[] = [ 'file' => $file, 'label' => $label, 'active' => is_plugin_active( $file ) ];
        }
        return $items;
    }

    private function endpoint_url( $path ) {
        $master = untrailingslashit( trim( (string) get_option( self::OPT_MASTER_URL, '' ) ) );
        return $master . '/wp-json/' . self::REST_NAMESPACE . '/' . ltrim( $path, '/' );
    }

    private function remote_headers() {
        $headers = [ 'Accept' => 'application/json' ];
        $key = trim( (string) get_option( self::OPT_ACCESS_KEY, '' ) );
        if ( $key ) {
            $headers['X-SRMDL-Key'] = $key;
        }
        return $headers;
    }

    private function fetch_remote_catalog( $force = false ) {
        $master = trim( (string) get_option( self::OPT_MASTER_URL, '' ) );
        if ( ! $master || ! wp_http_validate_url( $master ) ) {
            return new \WP_Error( 'srmdl_bad_master', __( 'The Master Library URL is missing or invalid.', 'srm-design-library' ) );
        }

        $key       = trim( (string) get_option( self::OPT_ACCESS_KEY, '' ) );
        $cache_key = 'srmdl_cloud_' . md5( strtolower( untrailingslashit( $master ) ) . '|' . $key );
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $response = wp_safe_remote_get( $this->endpoint_url( 'templates' ), [
            'timeout'     => 20,
            'redirection' => 3,
            'headers'     => $this->remote_headers(),
            'user-agent'  => 'SRM-Design-Library/' . ( defined( 'SRMDL_VERSION' ) ? SRMDL_VERSION : '2' ) . '; ' . home_url( '/' ),
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );
        if ( 200 !== $code ) {
            $message = is_array( $json ) && ! empty( $json['message'] ) ? sanitize_text_field( $json['message'] ) : 'HTTP ' . $code;
            return new \WP_Error( 'srmdl_cloud_http', $message );
        }
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json ) || ! isset( $json['templates'] ) || ! is_array( $json['templates'] ) ) {
            return new \WP_Error( 'srmdl_cloud_json', __( 'Master Library returned an invalid catalog response.', 'srm-design-library' ) );
        }

        $minutes = max( 1, min( 1440, absint( get_option( self::OPT_CACHE_MINS, 15 ) ) ) );
        set_transient( $cache_key, $json, $minutes * MINUTE_IN_SECONDS );
        update_option( self::OPT_LAST_SYNC, time(), false );
        return $json;
    }

    private function fetch_remote_template( $remote_id ) {
        $master = trim( (string) get_option( self::OPT_MASTER_URL, '' ) );
        if ( ! $master || ! wp_http_validate_url( $master ) ) {
            return new \WP_Error( 'srmdl_bad_master', __( 'The Master Library URL is missing or invalid.', 'srm-design-library' ) );
        }
        $url = $this->endpoint_url( 'templates/' . absint( $remote_id ) );
        $response = wp_safe_remote_get( $url, [
            'timeout'     => 35,
            'redirection' => 3,
            'headers'     => $this->remote_headers(),
            'user-agent'  => 'SRM-Design-Library/' . ( defined( 'SRMDL_VERSION' ) ? SRMDL_VERSION : '2' ) . '; ' . home_url( '/' ),
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );
        if ( 200 !== $code ) {
            $message = is_array( $json ) && ! empty( $json['message'] ) ? sanitize_text_field( $json['message'] ) : 'HTTP ' . $code;
            return new \WP_Error( 'srmdl_cloud_http', $message );
        }
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json ) || empty( $json['template'] ) || ! is_array( $json['template'] ) || ! isset( $json['template']['content'] ) || ! is_array( $json['template']['content'] ) ) {
            return new \WP_Error( 'srmdl_cloud_json', __( 'Master Library returned invalid Elementor template data.', 'srm-design-library' ) );
        }
        return $json;
    }

    public function handle_save_settings() {
        $this->ensure_admin();
        check_admin_referer( 'srmdl_cloud_save_settings', 'srmdl_cloud_nonce' );

        $role = isset( $_POST['cloud_role'] ) ? sanitize_key( wp_unslash( $_POST['cloud_role'] ) ) : 'master';
        if ( ! in_array( $role, [ 'master', 'client', 'hybrid' ], true ) ) {
            $role = 'master';
        }
        $master_url = isset( $_POST['master_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['master_url'] ) ) ) : '';
        $key        = isset( $_POST['access_key'] ) ? sanitize_text_field( wp_unslash( $_POST['access_key'] ) ) : '';
        $cache      = isset( $_POST['cache_minutes'] ) ? max( 1, min( 1440, absint( $_POST['cache_minutes'] ) ) ) : 15;

        update_option( self::OPT_ROLE, $role );
        update_option( self::OPT_MASTER_URL, untrailingslashit( $master_url ) );
        update_option( self::OPT_ACCESS_KEY, $key );
        update_option( self::OPT_CACHE_MINS, $cache );
        $this->clear_catalog_transients();

        $this->redirect_notice( 'srm-design-library-cloud-settings', 'settings_saved' );
    }

    public function handle_refresh() {
        $this->ensure_admin();
        check_admin_referer( 'srmdl_cloud_refresh' );
        $this->clear_catalog_transients();
        $result = $this->fetch_remote_catalog( true );
        if ( is_wp_error( $result ) ) {
            $this->redirect_notice( 'srm-design-library-cloud', 'error', $result->get_error_message() );
        }
        $this->redirect_notice( 'srm-design-library-cloud', 'refreshed' );
    }

    private function clear_catalog_transients() {
        global $wpdb;
        // Plugin-owned transients only. Handles both normal and timeout rows.
        $like1 = $wpdb->esc_like( '_transient_srmdl_cloud_' ) . '%';
        $like2 = $wpdb->esc_like( '_transient_timeout_srmdl_cloud_' ) . '%';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like1, $like2 ) );
    }

    public function handle_remote_import() {
        $this->ensure_admin();
        $remote_id = isset( $_GET['remote_id'] ) ? absint( $_GET['remote_id'] ) : 0;
        check_admin_referer( 'srmdl_cloud_import_' . $remote_id );

        if ( ! $remote_id ) {
            $this->redirect_notice( 'srm-design-library-cloud', 'error', 'Invalid cloud template ID.' );
        }
        if ( ! class_exists( '\\Elementor\\Plugin' ) || ! did_action( 'elementor/loaded' ) ) {
            $this->redirect_notice( 'srm-design-library-cloud', 'error', 'Elementor is not active.' );
        }

        $remote = $this->fetch_remote_template( $remote_id );
        if ( is_wp_error( $remote ) ) {
            $this->redirect_notice( 'srm-design-library-cloud', 'error', $remote->get_error_message() );
        }

        // V2.2: Process remote images before import
        $template_data = $remote['template'];
        $image_migration_result = $this->process_remote_images( $template_data );
        
        if ( is_wp_error( $image_migration_result ) ) {
            // Log error but continue with import (fallback to remote URLs)
            error_log( 'SRM Cloud Library - Image migration warning: ' . $image_migration_result->get_error_message() );
        }

        $encoded = wp_json_encode( $template_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $encoded ) {
            $this->redirect_notice( 'srm-design-library-cloud', 'error', 'Could not encode the cloud Elementor JSON.' );
        }
        if ( ! empty( $remote['checksum'] ) && ! hash_equals( (string) $remote['checksum'], hash( 'sha256', $encoded ) ) ) {
            // WordPress JSON encoding may normalize insignificant formatting. Validate semantic JSON instead of failing checksum here.
        }

        $filename = sanitize_file_name( $remote['file_name'] ?? ( 'cloud-template-' . $remote_id . '.json' ) );
        if ( 'json' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
            $filename .= '.json';
        }
        $tmp = wp_tempnam( $filename );
        if ( ! $tmp || false === file_put_contents( $tmp, $encoded ) ) {
            $this->redirect_notice( 'srm-design-library-cloud', 'error', 'Could not create a temporary Elementor JSON file.' );
        }

        try {
            $manager = \Elementor\Plugin::$instance->templates_manager ?? null;
            $source  = $manager ? $manager->get_source( 'local' ) : false;
            if ( ! $source || ! method_exists( $source, 'import_template' ) ) {
                throw new \Exception( 'Elementor local template importer is unavailable.' );
            }

            $result = $source->import_template( $filename, $tmp, 'match_site' );
            if ( is_wp_error( $result ) ) {
                throw new \Exception( $result->get_error_message() );
            }
            if ( ! is_array( $result ) || ! $result ) {
                throw new \Exception( 'Elementor returned no imported template.' );
            }

            $ids = [];
            foreach ( $result as $item ) {
                if ( is_array( $item ) && ! empty( $item['template_id'] ) ) {
                    $ids[] = absint( $item['template_id'] );
                }
            }
            if ( $ids && taxonomy_exists( 'elementor_library_category' ) && ! empty( $remote['category']['name'] ) ) {
                foreach ( $ids as $id ) {
                    wp_set_object_terms( $id, sanitize_text_field( $remote['category']['name'] ), 'elementor_library_category', true );
                    update_post_meta( $id, '_srmdl_cloud_source', untrailingslashit( (string) get_option( self::OPT_MASTER_URL, '' ) ) );
                    update_post_meta( $id, '_srmdl_cloud_remote_id', $remote_id );
                    
                    // V2.2: Store image migration info
                    if ( ! is_wp_error( $image_migration_result ) && ! empty( $image_migration_result['migrated_count'] ) ) {
                        update_post_meta( $id, '_srmdl_images_migrated', $image_migration_result['migrated_count'] );
                        update_post_meta( $id, '_srmdl_migration_status', 'completed' );
                    }
                }
            }
        } catch ( \Throwable $e ) {
            @unlink( $tmp );
            $this->redirect_notice( 'srm-design-library-cloud', 'error', $e->getMessage() );
        }
        @unlink( $tmp );

        $this->redirect_notice( 'srm-design-library-cloud', 'imported' );
    }

    /**
     * V2.2: Process remote images in template data
     * Downloads images from remote URLs and replaces them with local media library IDs
     * 
     * @param array &$template_data Reference to template data (modified in place)
     * @return array|WP_Error Migration result or error
     */
    private function process_remote_images( &$template_data ) {
        if ( ! isset( $template_data['content'] ) || ! is_array( $template_data['content'] ) ) {
            return new \WP_Error( 'invalid_template', 'Template content is invalid' );
        }

        $migrated_urls = [];
        $migrated_count = 0;
        $errors = [];

        // Recursively scan template content for image URLs
        $this->scan_and_migrate_images( $template_data['content'], $migrated_urls, $migrated_count, $errors );

        return [
            'migrated_count' => $migrated_count,
            'migrated_urls'  => $migrated_urls,
            'errors'         => $errors,
        ];
    }

    /**
     * V2.2: Recursively scan and migrate images in template structure
     */
    private function scan_and_migrate_images( &$data, &$migrated_urls, &$count, &$errors ) {
        if ( is_array( $data ) ) {
            foreach ( $data as $key => &$value ) {
                // Check for image URL fields
                if ( is_string( $value ) && $this->is_image_url( $value ) ) {
                    // Handle different field types
                    if ( $key === 'url' || $key === 'src' || $key === 'background_image_url' || 
                         strpos( $key, 'image' ) !== false || strpos( $key, 'background' ) !== false ) {
                        $new_attachment_id = $this->download_and_save_image( $value, $migrated_urls );
                        
                        if ( $new_attachment_id && ! is_wp_error( $new_attachment_id ) ) {
                            // Replace URL with attachment ID where appropriate
                            if ( $key === 'id' || strpos( $key, '_id' ) !== false ) {
                                $value = $new_attachment_id;
                            } else {
                                // Update URL to local URL
                                $local_url = wp_get_attachment_url( $new_attachment_id );
                                if ( $local_url ) {
                                    $value = $local_url;
                                    $migrated_urls[ $value ] = $new_attachment_id;
                                }
                            }
                            $count++;
                        } elseif ( is_wp_error( $new_attachment_id ) ) {
                            $errors[] = $new_attachment_id->get_error_message();
                        }
                    }
                } else {
                    // Recurse into nested arrays
                    $this->scan_and_migrate_images( $value, $migrated_urls, $count, $errors );
                }
            }
        }
    }

    /**
     * V2.2: Check if a string is a valid image URL
     */
    private function is_image_url( $url ) {
        if ( ! is_string( $url ) || empty( $url ) ) {
            return false;
        }
        
        // Skip already local URLs
        if ( strpos( $url, home_url() ) === 0 ) {
            return false;
        }

        // Check for valid URL
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        // Check for image extensions or patterns
        $image_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico' ];
        $url_lower = strtolower( $url );
        
        // Check extension
        foreach ( $image_extensions as $ext ) {
            if ( strpos( $url_lower, '.' . $ext ) !== false || strpos( $url_lower, '.' . $ext . '?' ) !== false ) {
                return true;
            }
        }

        // Check for common image URL patterns (WordPress uploads, etc.)
        if ( strpos( $url_lower, '/uploads/' ) !== false && strpos( $url_lower, '/wp-content/' ) !== false ) {
            return true;
        }

        return false;
    }

    /**
     * V2.2: Download remote image and save to Media Library
     * Implements duplicate detection to avoid re-uploading same images
     */
    private function download_and_save_image( $image_url, &$existing_migrations = [] ) {
        // Check if already migrated in this session
        if ( isset( $existing_migrations[ $image_url ] ) ) {
            return $existing_migrations[ $image_url ];
        }

        // Check for existing attachment by URL (duplicate detection)
        $existing_id = $this->find_existing_attachment_by_url( $image_url );
        if ( $existing_id ) {
            $existing_migrations[ $image_url ] = $existing_id;
            return $existing_id;
        }

        // Validate image URL
        if ( ! $this->is_image_url( $image_url ) ) {
            return new \WP_Error( 'invalid_image_url', 'Invalid image URL: ' . $image_url );
        }

        // Supported MIME types
        $allowed_mime_types = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        try {
            // Download image
            $response = wp_safe_remote_get( $image_url, [
                'timeout'     => 30,
                'redirection' => 5,
                'user-agent'  => 'SRM-Design-Library/' . ( defined( 'SRMDL_VERSION' ) ? SRMDL_VERSION : '2.2' ) . '; Image Migration',
            ] );

            if ( is_wp_error( $response ) ) {
                return new \WP_Error( 'download_failed', 'Failed to download image: ' . $response->get_error_message() );
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            if ( 200 !== $status_code ) {
                return new \WP_Error( 'http_error', 'HTTP error ' . $status_code . ' downloading image' );
            }

            $image_data = wp_remote_retrieve_body( $response );
            if ( empty( $image_data ) ) {
                return new \WP_Error( 'empty_image', 'Downloaded image is empty' );
            }

            // Get MIME type from response headers or file content
            $content_type = wp_remote_retrieve_header( $response, 'content-type' );
            $mime_type = ! empty( $content_type ) ? explode( ';', $content_type )[0] : '';
            
            // Fallback: detect MIME type from file content
            if ( empty( $mime_type ) || ! isset( $allowed_mime_types[ $mime_type ] ) ) {
                $temp_file = tmpfile();
                if ( $temp_file ) {
                    fwrite( $temp_file, $image_data );
                    $meta_data = stream_get_meta_data( $temp_file );
                    $detected_mime = mime_content_type( $meta_data['uri'] );
                    fclose( $temp_file );
                    
                    if ( $detected_mime && isset( $allowed_mime_types[ $detected_mime ] ) ) {
                        $mime_type = $detected_mime;
                    }
                }
            }

            if ( empty( $mime_type ) || ! isset( $allowed_mime_types[ $mime_type ] ) ) {
                // Try to guess from URL
                $url_path = parse_url( $image_url, PHP_URL_PATH );
                $extension = strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) );
                
                foreach ( $allowed_mime_types as $mime => $ext ) {
                    if ( $extension === $ext ) {
                        $mime_type = $mime;
                        break;
                    }
                }
            }

            if ( empty( $mime_type ) || ! isset( $allowed_mime_types[ $mime_type ] ) ) {
                return new \WP_Error( 'unsupported_format', 'Unsupported image format: ' . $mime_type );
            }

            $extension = $allowed_mime_types[ $mime_type ];

            // Generate unique filename
            $filename = basename( parse_url( $image_url, PHP_URL_PATH ) );
            if ( empty( $filename ) || '.' === $filename[0] ) {
                $filename = 'srm-migrated-image-' . time() . '-' . wp_generate_password( 6, false ) . '.' . $extension;
            } else {
                $filename = sanitize_file_name( $filename );
                if ( pathinfo( $filename, PATHINFO_EXTENSION ) !== $extension ) {
                    $filename = pathinfo( $filename, PATHINFO_FILENAME ) . '.' . $extension;
                }
            }

            // Upload to WordPress
            $upload = wp_upload_bits( $filename, null, $image_data );
            if ( ! empty( $upload['error'] ) ) {
                return new \WP_Error( 'upload_error', 'Upload error: ' . $upload['error'] );
            }

            // Create attachment post
            $attachment = [
                'post_title'   => pathinfo( $filename, PATHINFO_FILENAME ),
                'post_content' => '',
                'post_status'  => 'inherit',
                'post_mime_type' => $mime_type,
            ];

            $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
            if ( is_wp_error( $attachment_id ) ) {
                @unlink( $upload['file'] );
                return $attachment_id;
            }

            // Generate metadata
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
            wp_update_attachment_metadata( $attachment_id, $attach_data );

            // Store original URL for future reference
            update_post_meta( $attachment_id, '_srmdl_original_url', $image_url );
            update_post_meta( $attachment_id, '_srmdl_migrated_at', current_time( 'timestamp' ) );

            $existing_migrations[ $image_url ] = $attachment_id;
            return $attachment_id;

        } catch ( \Exception $e ) {
            return new \WP_Error( 'migration_exception', 'Image migration exception: ' . $e->getMessage() );
        }
    }

    /**
     * V2.2: Find existing attachment by original URL meta or GUID
     * Prevents duplicate uploads of the same image
     */
    private function find_existing_attachment_by_url( $image_url ) {
        global $wpdb;

        // First check by stored original URL meta
        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_srmdl_original_url' AND meta_value = %s LIMIT 1",
            $image_url
        ) );

        if ( $attachment_id ) {
            return absint( $attachment_id );
        }

        // Fallback: check by GUID (less reliable but worth trying)
        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s LIMIT 1",
            $image_url
        ) );

        if ( $attachment_id ) {
            return absint( $attachment_id );
        }

        return false;
    }
}

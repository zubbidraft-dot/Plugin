<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SRM_Design_Library {
    private static $instance = null;

    const POST_TYPE = 'srm_library_item';
    const TAXONOMY  = 'srm_template_category';

    const META_TYPE          = '_srmdl_type';
    const META_COMPAT        = '_srmdl_compatibility';
    const META_TAGS          = '_srmdl_tags';
    const META_REQUIRED      = '_srmdl_required_plugins';
    const META_VERSION       = '_srmdl_template_version';
    const META_JSON_RELPATH  = '_srmdl_json_relpath';
    const META_JSON_NAME     = '_srmdl_json_name';
    const META_PREVIEW_ID    = '_srmdl_preview_id';
    const META_LIVE_PREVIEW  = '_srmdl_live_preview';
    const META_LAST_IMPORTED = '_srmdl_last_imported_ids';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_content_types' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_notices', [ $this, 'elementor_notice' ] );

        add_action( 'admin_post_srmdl_save_template', [ $this, 'handle_save_template' ] );
        add_action( 'admin_post_srmdl_delete_template', [ $this, 'handle_delete_template' ] );
        add_action( 'admin_post_srmdl_import_template', [ $this, 'handle_import_template' ] );
        add_action( 'admin_post_srmdl_add_category', [ $this, 'handle_add_category' ] );
        add_action( 'admin_post_srmdl_delete_category', [ $this, 'handle_delete_category' ] );
        add_action( 'admin_post_srmdl_save_settings', [ $this, 'handle_save_settings' ] );
    }

    public function register_content_types() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          => __( 'SRM Templates', 'srm-design-library' ),
                'singular_name' => __( 'SRM Template', 'srm-design-library' ),
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'exclude_from_search' => true,
            'rewrite'             => false,
        ] );

        register_taxonomy( self::TAXONOMY, [ self::POST_TYPE ], [
            'labels' => [
                'name'          => __( 'Template Categories', 'srm-design-library' ),
                'singular_name' => __( 'Template Category', 'srm-design-library' ),
            ],
            'public'            => false,
            'show_ui'           => false,
            'show_admin_column' => false,
            'hierarchical'      => true,
            'rewrite'           => false,
            'show_in_rest'      => false,
        ] );
    }

    public function register_admin_menu() {
        $name = $this->library_name();

        add_menu_page(
            $name,
            __( 'SRM Library', 'srm-design-library' ),
            'manage_options',
            'srm-design-library',
            [ $this, 'render_library_page' ],
            'dashicons-layout',
            58
        );

        add_submenu_page(
            'srm-design-library',
            __( 'All Templates', 'srm-design-library' ),
            __( 'All Templates', 'srm-design-library' ),
            'manage_options',
            'srm-design-library',
            [ $this, 'render_library_page' ]
        );

        add_submenu_page(
            'srm-design-library',
            __( 'Add New Template', 'srm-design-library' ),
            __( 'Add New Template', 'srm-design-library' ),
            'manage_options',
            'srm-design-library-add',
            [ $this, 'render_add_page' ]
        );

        add_submenu_page(
            'srm-design-library',
            __( 'Categories', 'srm-design-library' ),
            __( 'Categories', 'srm-design-library' ),
            'manage_options',
            'srm-design-library-categories',
            [ $this, 'render_categories_page' ]
        );

        add_submenu_page(
            'srm-design-library',
            __( 'Settings', 'srm-design-library' ),
            __( 'Settings', 'srm-design-library' ),
            'manage_options',
            'srm-design-library-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( $hook, 'srm-design-library' ) ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style( 'srmdl-admin', SRMDL_URL . 'assets/admin.css', [], SRMDL_VERSION );
        wp_enqueue_script( 'srmdl-admin', SRMDL_URL . 'assets/admin.js', [ 'jquery' ], SRMDL_VERSION, true );

        $accent = sanitize_hex_color( get_option( 'srmdl_accent_color', '#5b5bd6' ) );
        if ( ! $accent ) {
            $accent = '#5b5bd6';
        }
        wp_add_inline_style( 'srmdl-admin', ':root{--srmdl-accent:' . esc_attr( $accent ) . ';}' );
    }

    public function elementor_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || false === strpos( $screen->id, 'srm-design-library' ) ) {
            return;
        }

        if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\\Elementor\\Plugin' ) ) {
            echo '<div class="notice notice-warning"><p><strong>SRM Design Library:</strong> Elementor is not active. You can still manage the library, but importing into Elementor is disabled until Elementor is active.</p></div>';
        }
    }

    private function library_name() {
        $name = sanitize_text_field( get_option( 'srmdl_library_name', 'SRM Design Library' ) );
        return $name ? $name : 'SRM Design Library';
    }

    private function ensure_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'srm-design-library' ) );
        }
    }

    private function redirect_with_notice( $page, $notice, $extra = [] ) {
        $args = array_merge( [ 'page' => $page, 'srmdl_notice' => $notice ], $extra );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function render_notice_from_query() {
        $notice = isset( $_GET['srmdl_notice'] ) ? sanitize_key( wp_unslash( $_GET['srmdl_notice'] ) ) : '';
        $messages = [
            'saved'            => [ 'success', __( 'Template saved to the SRM library.', 'srm-design-library' ) ],
            'deleted'          => [ 'success', __( 'Template deleted.', 'srm-design-library' ) ],
            'imported'         => [ 'success', __( 'Template imported into Elementor Saved Templates.', 'srm-design-library' ) ],
            'category_added'   => [ 'success', __( 'Category added.', 'srm-design-library' ) ],
            'category_deleted' => [ 'success', __( 'Category deleted.', 'srm-design-library' ) ],
            'settings_saved'   => [ 'success', __( 'Settings saved.', 'srm-design-library' ) ],
            'error'            => [ 'error', __( 'Something went wrong. Check the message below.', 'srm-design-library' ) ],
        ];

        if ( ! isset( $messages[ $notice ] ) ) {
            return;
        }

        [ $type, $message ] = $messages[ $notice ];
        if ( isset( $_GET['message'] ) ) {
            $detail = sanitize_text_field( wp_unslash( $_GET['message'] ) );
            if ( $detail ) {
                $message .= ' ' . $detail;
            }
        }

        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }

    public function render_library_page() {
        $this->ensure_admin();
        $this->render_notice_from_query();

        $search = isset( $_GET['srm_s'] ) ? sanitize_text_field( wp_unslash( $_GET['srm_s'] ) ) : '';
        $cat    = isset( $_GET['srm_cat'] ) ? absint( $_GET['srm_cat'] ) : 0;
        $type   = isset( $_GET['srm_type'] ) ? sanitize_key( wp_unslash( $_GET['srm_type'] ) ) : '';
        $compat = isset( $_GET['srm_compat'] ) ? sanitize_key( wp_unslash( $_GET['srm_compat'] ) ) : '';

        $args = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
            's'              => $search,
        ];

        if ( $cat ) {
            $args['tax_query'] = [[
                'taxonomy' => self::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => [ $cat ],
            ]];
        }

        $meta_query = [];
        if ( in_array( $type, [ 'section', 'block', 'page' ], true ) ) {
            $meta_query[] = [ 'key' => self::META_TYPE, 'value' => $type ];
        }
        if ( in_array( $compat, [ 'free', 'pro', 'both' ], true ) ) {
            $meta_query[] = [ 'key' => self::META_COMPAT, 'value' => $compat ];
        }
        if ( $meta_query ) {
            $args['meta_query'] = $meta_query;
        }

        $query      = new WP_Query( $args );
        $categories = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
        ?>
        <div class="wrap srmdl-wrap">
            <div class="srmdl-header">
                <div>
                    <h1><?php echo esc_html( $this->library_name() ); ?></h1>
                    <p><?php esc_html_e( 'Your private Elementor pages, sections and blocks.', 'srm-design-library' ); ?></p>
                </div>
                <a class="button button-primary srmdl-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=srm-design-library-add' ) ); ?>"><?php esc_html_e( 'Add New Template', 'srm-design-library' ); ?></a>
            </div>

            <form class="srmdl-filters" method="get">
                <input type="hidden" name="page" value="srm-design-library">
                <input type="search" name="srm_s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search templates...">
                <select name="srm_cat">
                    <option value="0"><?php esc_html_e( 'All Categories', 'srm-design-library' ); ?></option>
                    <?php foreach ( $categories as $category ) : ?>
                        <option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $cat, $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="srm_type">
                    <option value=""><?php esc_html_e( 'All Types', 'srm-design-library' ); ?></option>
                    <option value="section" <?php selected( $type, 'section' ); ?>><?php esc_html_e( 'Sections', 'srm-design-library' ); ?></option>
                    <option value="block" <?php selected( $type, 'block' ); ?>><?php esc_html_e( 'Blocks', 'srm-design-library' ); ?></option>
                    <option value="page" <?php selected( $type, 'page' ); ?>><?php esc_html_e( 'Pages', 'srm-design-library' ); ?></option>
                </select>
                <select name="srm_compat">
                    <option value=""><?php esc_html_e( 'Any Compatibility', 'srm-design-library' ); ?></option>
                    <option value="free" <?php selected( $compat, 'free' ); ?>><?php esc_html_e( 'Elementor Free', 'srm-design-library' ); ?></option>
                    <option value="pro" <?php selected( $compat, 'pro' ); ?>><?php esc_html_e( 'Elementor Pro', 'srm-design-library' ); ?></option>
                    <option value="both" <?php selected( $compat, 'both' ); ?>><?php esc_html_e( 'Free + Pro', 'srm-design-library' ); ?></option>
                </select>
                <button class="button"><?php esc_html_e( 'Filter', 'srm-design-library' ); ?></button>
                <a class="button button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=srm-design-library' ) ); ?>"><?php esc_html_e( 'Reset', 'srm-design-library' ); ?></a>
            </form>

            <div class="srmdl-count"><?php echo esc_html( sprintf( _n( '%d template', '%d templates', $query->found_posts, 'srm-design-library' ), $query->found_posts ) ); ?></div>

            <?php if ( $query->have_posts() ) : ?>
                <div class="srmdl-grid">
                    <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                        <?php $this->render_template_card( get_the_ID() ); ?>
                    <?php endwhile; wp_reset_postdata(); ?>
                </div>
            <?php else : ?>
                <div class="srmdl-empty">
                    <span class="dashicons dashicons-layout"></span>
                    <h2><?php esc_html_e( 'No templates yet', 'srm-design-library' ); ?></h2>
                    <p><?php esc_html_e( 'Create your first library item by uploading an Elementor template JSON file and a preview image.', 'srm-design-library' ); ?></p>
                    <a class="button button-primary srmdl-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=srm-design-library-add' ) ); ?>"><?php esc_html_e( 'Add First Template', 'srm-design-library' ); ?></a>
                </div>
            <?php endif; ?>
        </div>

        <div id="srmdl-preview-modal" class="srmdl-modal" aria-hidden="true">
            <button type="button" class="srmdl-modal-close" aria-label="Close">×</button>
            <div class="srmdl-modal-inner"><img src="" alt="Template preview"></div>
        </div>
        <?php
    }

    private function render_template_card( $post_id ) {
        $type       = get_post_meta( $post_id, self::META_TYPE, true ) ?: 'section';
        $compat     = get_post_meta( $post_id, self::META_COMPAT, true ) ?: 'both';
        $tags       = get_post_meta( $post_id, self::META_TAGS, true );
        $version    = get_post_meta( $post_id, self::META_VERSION, true ) ?: '1.0';
        $preview_id = absint( get_post_meta( $post_id, self::META_PREVIEW_ID, true ) );
        $preview    = $preview_id ? wp_get_attachment_image_url( $preview_id, 'large' ) : '';
        $live       = esc_url( get_post_meta( $post_id, self::META_LIVE_PREVIEW, true ) );
        $terms      = wp_get_post_terms( $post_id, self::TAXONOMY );
        $category   = ! is_wp_error( $terms ) && $terms ? $terms[0]->name : __( 'Uncategorized', 'srm-design-library' );
        $required   = $this->required_plugins_status( get_post_meta( $post_id, self::META_REQUIRED, true ) );
        $import_url = wp_nonce_url( admin_url( 'admin-post.php?action=srmdl_import_template&template_id=' . $post_id ), 'srmdl_import_template_' . $post_id );
        $delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=srmdl_delete_template&template_id=' . $post_id ), 'srmdl_delete_template_' . $post_id );
        $edit_url   = admin_url( 'admin.php?page=srm-design-library-add&template_id=' . $post_id );
        ?>
        <article class="srmdl-card">
            <div class="srmdl-thumb">
                <?php if ( $preview ) : ?>
                    <img src="<?php echo esc_url( $preview ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>">
                    <button type="button" class="srmdl-preview-trigger" data-preview="<?php echo esc_url( $preview ); ?>"><?php esc_html_e( 'Preview', 'srm-design-library' ); ?></button>
                <?php else : ?>
                    <div class="srmdl-thumb-empty"><span class="dashicons dashicons-format-image"></span><span><?php esc_html_e( 'No preview', 'srm-design-library' ); ?></span></div>
                <?php endif; ?>
            </div>
            <div class="srmdl-card-body">
                <div class="srmdl-badges">
                    <span class="srmdl-badge"><?php echo esc_html( ucfirst( $type ) ); ?></span>
                    <span class="srmdl-badge srmdl-badge-soft"><?php echo esc_html( $this->compat_label( $compat ) ); ?></span>
                </div>
                <h3><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
                <div class="srmdl-meta"><?php echo esc_html( $category ); ?> · v<?php echo esc_html( $version ); ?></div>
                <?php if ( $tags ) : ?><p class="srmdl-tags"><?php echo esc_html( $tags ); ?></p><?php endif; ?>
                <?php if ( $required ) : ?>
                    <div class="srmdl-requirements">
                        <?php foreach ( $required as $plugin ) : ?>
                            <span class="<?php echo $plugin['active'] ? 'is-active' : 'is-missing'; ?>" title="<?php echo esc_attr( $plugin['file'] ); ?>"><?php echo $plugin['active'] ? '✓' : '!' ; ?> <?php echo esc_html( $plugin['label'] ); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="srmdl-actions">
                    <a class="button button-primary srmdl-primary" href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Import to Elementor', 'srm-design-library' ); ?></a>
                    <?php if ( $live ) : ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $live ); ?>"><?php esc_html_e( 'Live Preview', 'srm-design-library' ); ?></a><?php endif; ?>
                    <a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'srm-design-library' ); ?></a>
                    <a class="button button-link-delete srmdl-delete" href="<?php echo esc_url( $delete_url ); ?>" data-confirm="Delete this library template?"><?php esc_html_e( 'Delete', 'srm-design-library' ); ?></a>
                </div>
            </div>
        </article>
        <?php
    }

    private function compat_label( $compat ) {
        $labels = [
            'free' => __( 'Elementor Free', 'srm-design-library' ),
            'pro'  => __( 'Elementor Pro', 'srm-design-library' ),
            'both' => __( 'Free + Pro', 'srm-design-library' ),
        ];
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
            $items[] = [
                'file'   => $file,
                'label'  => $label,
                'active' => is_plugin_active( $file ),
            ];
        }
        return $items;
    }

    public function render_add_page() {
        $this->ensure_admin();
        $this->render_notice_from_query();

        $post_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
        $post    = $post_id ? get_post( $post_id ) : null;
        if ( $post_id && ( ! $post || self::POST_TYPE !== $post->post_type ) ) {
            wp_die( esc_html__( 'Template not found.', 'srm-design-library' ) );
        }

        $title      = $post ? $post->post_title : '';
        $type       = $post ? ( get_post_meta( $post_id, self::META_TYPE, true ) ?: 'section' ) : 'section';
        $compat     = $post ? ( get_post_meta( $post_id, self::META_COMPAT, true ) ?: 'both' ) : 'both';
        $tags       = $post ? get_post_meta( $post_id, self::META_TAGS, true ) : '';
        $required   = $post ? get_post_meta( $post_id, self::META_REQUIRED, true ) : '';
        $version    = $post ? ( get_post_meta( $post_id, self::META_VERSION, true ) ?: '1.0' ) : '1.0';
        $json_name  = $post ? get_post_meta( $post_id, self::META_JSON_NAME, true ) : '';
        $preview_id = $post ? absint( get_post_meta( $post_id, self::META_PREVIEW_ID, true ) ) : 0;
        $preview    = $preview_id ? wp_get_attachment_image_url( $preview_id, 'medium_large' ) : '';
        $live       = $post ? get_post_meta( $post_id, self::META_LIVE_PREVIEW, true ) : '';
        $terms      = $post ? wp_get_post_terms( $post_id, self::TAXONOMY, [ 'fields' => 'ids' ] ) : [];
        $term_id    = ! is_wp_error( $terms ) && $terms ? absint( $terms[0] ) : 0;
        $categories = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
        ?>
        <div class="wrap srmdl-wrap srmdl-form-wrap">
            <div class="srmdl-header">
                <div>
                    <h1><?php echo $post ? esc_html__( 'Edit Template', 'srm-design-library' ) : esc_html__( 'Add New Template', 'srm-design-library' ); ?></h1>
                    <p><?php esc_html_e( 'Upload an Elementor-exported JSON file plus the metadata used by your private library.', 'srm-design-library' ); ?></p>
                </div>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=srm-design-library' ) ); ?>">← <?php esc_html_e( 'Back to Library', 'srm-design-library' ); ?></a>
            </div>

            <form class="srmdl-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="srmdl_save_template">
                <input type="hidden" name="template_id" value="<?php echo esc_attr( $post_id ); ?>">
                <?php wp_nonce_field( 'srmdl_save_template', 'srmdl_nonce' ); ?>

                <div class="srmdl-form-main">
                    <section class="srmdl-panel">
                        <h2><?php esc_html_e( 'Template Details', 'srm-design-library' ); ?></h2>
                        <label class="srmdl-field">
                            <span><?php esc_html_e( 'Template Name', 'srm-design-library' ); ?> *</span>
                            <input type="text" name="template_title" value="<?php echo esc_attr( $title ); ?>" required placeholder="Premium AI Hero 01">
                        </label>

                        <div class="srmdl-field-row">
                            <label class="srmdl-field">
                                <span><?php esc_html_e( 'Library Type', 'srm-design-library' ); ?></span>
                                <select name="template_type">
                                    <option value="section" <?php selected( $type, 'section' ); ?>><?php esc_html_e( 'Section', 'srm-design-library' ); ?></option>
                                    <option value="block" <?php selected( $type, 'block' ); ?>><?php esc_html_e( 'Block', 'srm-design-library' ); ?></option>
                                    <option value="page" <?php selected( $type, 'page' ); ?>><?php esc_html_e( 'Full Page', 'srm-design-library' ); ?></option>
                                </select>
                            </label>
                            <label class="srmdl-field">
                                <span><?php esc_html_e( 'Category', 'srm-design-library' ); ?></span>
                                <select name="category_id">
                                    <option value="0"><?php esc_html_e( 'Uncategorized', 'srm-design-library' ); ?></option>
                                    <?php foreach ( $categories as $category ) : ?>
                                        <option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $term_id, $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="srmdl-field-row">
                            <label class="srmdl-field">
                                <span><?php esc_html_e( 'Elementor Compatibility', 'srm-design-library' ); ?></span>
                                <select name="compatibility">
                                    <option value="free" <?php selected( $compat, 'free' ); ?>><?php esc_html_e( 'Elementor Free', 'srm-design-library' ); ?></option>
                                    <option value="pro" <?php selected( $compat, 'pro' ); ?>><?php esc_html_e( 'Elementor Pro', 'srm-design-library' ); ?></option>
                                    <option value="both" <?php selected( $compat, 'both' ); ?>><?php esc_html_e( 'Free + Pro', 'srm-design-library' ); ?></option>
                                </select>
                            </label>
                            <label class="srmdl-field">
                                <span><?php esc_html_e( 'Template Version', 'srm-design-library' ); ?></span>
                                <input type="text" name="template_version" value="<?php echo esc_attr( $version ); ?>" placeholder="1.0">
                            </label>
                        </div>

                        <label class="srmdl-field">
                            <span><?php esc_html_e( 'Tags', 'srm-design-library' ); ?></span>
                            <input type="text" name="template_tags" value="<?php echo esc_attr( $tags ); ?>" placeholder="AI, restaurant, dark, hero">
                        </label>

                        <label class="srmdl-field">
                            <span><?php esc_html_e( 'Required Plugins', 'srm-design-library' ); ?></span>
                            <input type="text" name="required_plugins" value="<?php echo esc_attr( $required ); ?>" placeholder="elementor-pro/elementor-pro.php, elementskit-lite/elementskit-lite.php">
                            <small><?php esc_html_e( 'Comma-separated plugin files. Leave empty for Elementor-only templates.', 'srm-design-library' ); ?></small>
                        </label>

                        <label class="srmdl-field">
                            <span><?php esc_html_e( 'Live Preview URL', 'srm-design-library' ); ?></span>
                            <input type="url" name="live_preview" value="<?php echo esc_attr( $live ); ?>" placeholder="https://example.com/demo/hero-01/">
                        </label>
                    </section>

                    <section class="srmdl-panel">
                        <h2><?php esc_html_e( 'Elementor JSON', 'srm-design-library' ); ?></h2>
                        <?php if ( $json_name ) : ?>
                            <div class="srmdl-current-file"><span class="dashicons dashicons-media-code"></span><strong><?php echo esc_html( $json_name ); ?></strong></div>
                        <?php endif; ?>
                        <label class="srmdl-upload-box">
                            <span class="dashicons dashicons-upload"></span>
                            <strong><?php echo $json_name ? esc_html__( 'Replace JSON file', 'srm-design-library' ) : esc_html__( 'Upload Elementor JSON', 'srm-design-library' ); ?></strong>
                            <small><?php esc_html_e( 'Export the design from Elementor Saved Templates and upload the .json file here.', 'srm-design-library' ); ?></small>
                            <input type="file" name="template_json" accept=".json,application/json" <?php echo $json_name ? '' : 'required'; ?>>
                        </label>
                    </section>
                </div>

                <aside class="srmdl-form-side">
                    <section class="srmdl-panel">
                        <h2><?php esc_html_e( 'Preview Image', 'srm-design-library' ); ?></h2>
                        <?php if ( $preview ) : ?><img class="srmdl-existing-preview" src="<?php echo esc_url( $preview ); ?>" alt="Preview"><?php endif; ?>
                        <label class="srmdl-upload-box srmdl-upload-small">
                            <span class="dashicons dashicons-format-image"></span>
                            <strong><?php echo $preview ? esc_html__( 'Replace preview image', 'srm-design-library' ) : esc_html__( 'Upload preview image', 'srm-design-library' ); ?></strong>
                            <small><?php esc_html_e( 'Recommended: 1200×700 WebP/JPG/PNG.', 'srm-design-library' ); ?></small>
                            <input type="file" name="preview_image" accept="image/jpeg,image/png,image/webp">
                        </label>
                    </section>

                    <section class="srmdl-panel srmdl-publish-panel">
                        <h2><?php esc_html_e( 'Publish', 'srm-design-library' ); ?></h2>
                        <p><?php esc_html_e( 'This template stays in your private local library until you later connect the cloud library in Phase 2.', 'srm-design-library' ); ?></p>
                        <button type="submit" class="button button-primary button-hero srmdl-primary"><?php echo $post ? esc_html__( 'Update Template', 'srm-design-library' ) : esc_html__( 'Publish Template', 'srm-design-library' ); ?></button>
                    </section>
                </aside>
            </form>
        </div>
        <?php
    }

    public function render_categories_page() {
        $this->ensure_admin();
        $this->render_notice_from_query();
        $categories = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
        ?>
        <div class="wrap srmdl-wrap">
            <div class="srmdl-header"><div><h1><?php esc_html_e( 'Template Categories', 'srm-design-library' ); ?></h1><p><?php esc_html_e( 'Use categories such as Hero, About, Services, FAQ and CTA.', 'srm-design-library' ); ?></p></div></div>
            <div class="srmdl-two-col">
                <section class="srmdl-panel">
                    <h2><?php esc_html_e( 'Add Category', 'srm-design-library' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="srmdl_add_category">
                        <?php wp_nonce_field( 'srmdl_add_category', 'srmdl_nonce' ); ?>
                        <label class="srmdl-field"><span><?php esc_html_e( 'Name', 'srm-design-library' ); ?></span><input type="text" name="category_name" required placeholder="Hero"></label>
                        <label class="srmdl-field"><span><?php esc_html_e( 'Slug (optional)', 'srm-design-library' ); ?></span><input type="text" name="category_slug" placeholder="hero"></label>
                        <button class="button button-primary srmdl-primary"><?php esc_html_e( 'Add Category', 'srm-design-library' ); ?></button>
                    </form>
                </section>
                <section class="srmdl-panel">
                    <h2><?php esc_html_e( 'Existing Categories', 'srm-design-library' ); ?></h2>
                    <?php if ( $categories ) : ?>
                        <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'srm-design-library' ); ?></th><th><?php esc_html_e( 'Templates', 'srm-design-library' ); ?></th><th></th></tr></thead><tbody>
                        <?php foreach ( $categories as $category ) :
                            $delete = wp_nonce_url( admin_url( 'admin-post.php?action=srmdl_delete_category&term_id=' . $category->term_id ), 'srmdl_delete_category_' . $category->term_id ); ?>
                            <tr><td><strong><?php echo esc_html( $category->name ); ?></strong><br><code><?php echo esc_html( $category->slug ); ?></code></td><td><?php echo esc_html( $category->count ); ?></td><td><a class="button-link-delete srmdl-delete" data-confirm="Delete this category? Templates will not be deleted." href="<?php echo esc_url( $delete ); ?>"><?php esc_html_e( 'Delete', 'srm-design-library' ); ?></a></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php else : ?><p><?php esc_html_e( 'No categories yet.', 'srm-design-library' ); ?></p><?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        $this->ensure_admin();
        $this->render_notice_from_query();
        $name   = $this->library_name();
        $accent = sanitize_hex_color( get_option( 'srmdl_accent_color', '#5b5bd6' ) ) ?: '#5b5bd6';
        ?>
        <div class="wrap srmdl-wrap">
            <div class="srmdl-header"><div><h1><?php esc_html_e( 'Library Settings', 'srm-design-library' ); ?></h1><p><?php esc_html_e( 'Phase 1 local-library settings.', 'srm-design-library' ); ?></p></div></div>
            <form class="srmdl-panel srmdl-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="srmdl_save_settings">
                <?php wp_nonce_field( 'srmdl_save_settings', 'srmdl_nonce' ); ?>
                <label class="srmdl-field"><span><?php esc_html_e( 'Library Name', 'srm-design-library' ); ?></span><input type="text" name="library_name" value="<?php echo esc_attr( $name ); ?>"></label>
                <label class="srmdl-field"><span><?php esc_html_e( 'Accent Color', 'srm-design-library' ); ?></span><input type="color" name="accent_color" value="<?php echo esc_attr( $accent ); ?>"></label>
                <button class="button button-primary srmdl-primary"><?php esc_html_e( 'Save Settings', 'srm-design-library' ); ?></button>
            </form>
            <div class="srmdl-panel srmdl-roadmap">
                <h2><?php esc_html_e( 'Build Status', 'srm-design-library' ); ?></h2>
                <ul>
                    <li>✓ Local template database</li>
                    <li>✓ Pages / sections / blocks metadata</li>
                    <li>✓ JSON + preview uploads</li>
                    <li>✓ Search and filters</li>
                    <li>✓ Elementor Saved Templates import</li>
                    <li>✓ Phase 2: Master/cloud library API</li>
                    <li>○ Phase 3: Direct insert inside Elementor editor</li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function handle_save_template() {
        $this->ensure_admin();
        check_admin_referer( 'srmdl_save_template', 'srmdl_nonce' );

        $post_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        if ( $post_id && self::POST_TYPE !== get_post_type( $post_id ) ) {
            $this->redirect_with_notice( 'srm-design-library-add', 'error', [ 'message' => 'Invalid template.' ] );
        }

        $title = isset( $_POST['template_title'] ) ? sanitize_text_field( wp_unslash( $_POST['template_title'] ) ) : '';
        if ( ! $title ) {
            $this->redirect_with_notice( 'srm-design-library-add', 'error', [ 'message' => 'Template name is required.' ] );
        }

        $type   = isset( $_POST['template_type'] ) ? sanitize_key( wp_unslash( $_POST['template_type'] ) ) : 'section';
        $compat = isset( $_POST['compatibility'] ) ? sanitize_key( wp_unslash( $_POST['compatibility'] ) ) : 'both';
        if ( ! in_array( $type, [ 'section', 'block', 'page' ], true ) ) {
            $type = 'section';
        }
        if ( ! in_array( $compat, [ 'free', 'pro', 'both' ], true ) ) {
            $compat = 'both';
        }

        $new_json = null;
        if ( isset( $_FILES['template_json'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['template_json']['error'] ) {
            $new_json = $this->store_json_upload( $_FILES['template_json'] );
            if ( is_wp_error( $new_json ) ) {
                $this->redirect_with_notice( 'srm-design-library-add', 'error', [
                    'template_id' => $post_id,
                    'message'     => $new_json->get_error_message(),
                ] );
            }
        } elseif ( ! $post_id ) {
            $this->redirect_with_notice( 'srm-design-library-add', 'error', [ 'message' => 'An Elementor JSON file is required.' ] );
        }

        if ( $post_id ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title, 'post_status' => 'publish' ] );
        } else {
            $post_id = wp_insert_post( [
                'post_type'   => self::POST_TYPE,
                'post_title'  => $title,
                'post_status' => 'publish',
            ], true );
            if ( is_wp_error( $post_id ) ) {
                $this->redirect_with_notice( 'srm-design-library-add', 'error', [ 'message' => $post_id->get_error_message() ] );
            }
        }

        update_post_meta( $post_id, self::META_TYPE, $type );
        update_post_meta( $post_id, self::META_COMPAT, $compat );
        update_post_meta( $post_id, self::META_TAGS, isset( $_POST['template_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['template_tags'] ) ) : '' );
        update_post_meta( $post_id, self::META_REQUIRED, isset( $_POST['required_plugins'] ) ? sanitize_text_field( wp_unslash( $_POST['required_plugins'] ) ) : '' );
        update_post_meta( $post_id, self::META_VERSION, isset( $_POST['template_version'] ) ? sanitize_text_field( wp_unslash( $_POST['template_version'] ) ) : '1.0' );
        update_post_meta( $post_id, self::META_LIVE_PREVIEW, isset( $_POST['live_preview'] ) ? esc_url_raw( wp_unslash( $_POST['live_preview'] ) ) : '' );

        if ( $new_json ) {
            update_post_meta( $post_id, self::META_JSON_RELPATH, $new_json['relpath'] );
            update_post_meta( $post_id, self::META_JSON_NAME, $new_json['name'] );
        }

        $category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
        wp_set_object_terms( $post_id, $category_id ? [ $category_id ] : [], self::TAXONOMY, false );

        if ( isset( $_FILES['preview_image'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['preview_image']['error'] ) {
            $image_id = $this->store_preview_image( $post_id );
            if ( is_wp_error( $image_id ) ) {
                $this->redirect_with_notice( 'srm-design-library-add', 'error', [
                    'template_id' => $post_id,
                    'message'     => $image_id->get_error_message(),
                ] );
            }
            update_post_meta( $post_id, self::META_PREVIEW_ID, $image_id );
        }

        $this->redirect_with_notice( 'srm-design-library', 'saved' );
    }

    private function store_json_upload( $file ) {
        if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
            return new WP_Error( 'upload_error', 'JSON upload failed.' );
        }
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'upload_error', 'Invalid uploaded JSON file.' );
        }
        if ( ! empty( $file['size'] ) && (int) $file['size'] > 10 * MB_IN_BYTES ) {
            return new WP_Error( 'upload_error', 'JSON file is larger than 10 MB.' );
        }

        $name = sanitize_file_name( $file['name'] ?? 'template.json' );
        if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
            return new WP_Error( 'upload_error', 'Only .json Elementor template files are allowed in Phase 1.' );
        }

        $raw  = file_get_contents( $file['tmp_name'] );
        $json = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json ) ) {
            return new WP_Error( 'upload_error', 'The uploaded file is not valid JSON.' );
        }
        if ( ! array_key_exists( 'content', $json ) || ! is_array( $json['content'] ) ) {
            return new WP_Error( 'upload_error', 'This does not look like an Elementor template export: the content array is missing.' );
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'upload_error', $uploads['error'] );
        }
        $dir = trailingslashit( $uploads['basedir'] ) . 'srm-design-library/templates';
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'upload_error', 'Could not create the SRM template upload directory.' );
        }

        $protect = trailingslashit( dirname( $dir ) ) . '.htaccess';
        if ( ! file_exists( $protect ) ) {
            @file_put_contents( $protect, "Options -Indexes\n<FilesMatch \\\"\\.(php|phtml|phar)$\\\">\nRequire all denied\n</FilesMatch>\n" );
        }

        $unique      = wp_unique_filename( $dir, $name );
        $destination = trailingslashit( $dir ) . $unique;
        if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
            return new WP_Error( 'upload_error', 'Could not store the JSON file.' );
        }

        $relative = ltrim( str_replace( trailingslashit( wp_normalize_path( $uploads['basedir'] ) ), '', wp_normalize_path( $destination ) ), '/' );
        return [ 'relpath' => $relative, 'name' => $unique ];
    }

    private function store_preview_image( $post_id ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $allowed = [ 'image/jpeg', 'image/png', 'image/webp' ];
        $type    = isset( $_FILES['preview_image']['type'] ) ? sanitize_mime_type( $_FILES['preview_image']['type'] ) : '';
        if ( $type && ! in_array( $type, $allowed, true ) ) {
            return new WP_Error( 'image_error', 'Preview must be JPG, PNG or WebP.' );
        }

        $attachment_id = media_handle_upload( 'preview_image', $post_id );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }
        return (int) $attachment_id;
    }

    public function handle_delete_template() {
        $this->ensure_admin();
        $post_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
        check_admin_referer( 'srmdl_delete_template_' . $post_id );
        if ( $post_id && self::POST_TYPE === get_post_type( $post_id ) ) {
            wp_delete_post( $post_id, true );
        }
        $this->redirect_with_notice( 'srm-design-library', 'deleted' );
    }

    public function handle_import_template() {
        $this->ensure_admin();
        $post_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
        check_admin_referer( 'srmdl_import_template_' . $post_id );

        if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) ) {
            $this->redirect_with_notice( 'srm-design-library', 'error', [ 'message' => 'Template not found.' ] );
        }

        if ( ! class_exists( '\\Elementor\\Plugin' ) || ! did_action( 'elementor/loaded' ) ) {
            $this->redirect_with_notice( 'srm-design-library', 'error', [ 'message' => 'Elementor is not active.' ] );
        }

        $relpath = get_post_meta( $post_id, self::META_JSON_RELPATH, true );
        $name    = get_post_meta( $post_id, self::META_JSON_NAME, true );
        $uploads = wp_upload_dir();
        $path    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( $relpath, '/\\' ) );
        $base    = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

        if ( ! $relpath || 0 !== strpos( $path, $base ) || ! is_readable( $path ) ) {
            $this->redirect_with_notice( 'srm-design-library', 'error', [ 'message' => 'Stored Elementor JSON file is missing.' ] );
        }

        try {
            $manager = \Elementor\Plugin::$instance->templates_manager ?? null;
            $source  = $manager ? $manager->get_source( 'local' ) : false;
            if ( ! $source || ! method_exists( $source, 'import_template' ) ) {
                throw new Exception( 'Elementor local template importer is unavailable.' );
            }

            $result = $source->import_template( $name ?: basename( $path ), $path, 'match_site' );
            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }
            if ( ! is_array( $result ) || ! $result ) {
                throw new Exception( 'Elementor returned no imported template.' );
            }

            $ids = [];
            foreach ( $result as $item ) {
                if ( is_array( $item ) && ! empty( $item['template_id'] ) ) {
                    $ids[] = absint( $item['template_id'] );
                }
            }
            update_post_meta( $post_id, self::META_LAST_IMPORTED, $ids );

            if ( $ids && taxonomy_exists( 'elementor_library_category' ) ) {
                $terms = wp_get_post_terms( $post_id, self::TAXONOMY );
                if ( ! is_wp_error( $terms ) && $terms ) {
                    foreach ( $ids as $id ) {
                        wp_set_object_terms( $id, $terms[0]->name, 'elementor_library_category', true );
                    }
                }
            }
        } catch ( Throwable $e ) {
            $this->redirect_with_notice( 'srm-design-library', 'error', [ 'message' => $e->getMessage() ] );
        }

        $this->redirect_with_notice( 'srm-design-library', 'imported' );
    }

    public function handle_add_category() {
        $this->ensure_admin();
        check_admin_referer( 'srmdl_add_category', 'srmdl_nonce' );
        $name = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '';
        $slug = isset( $_POST['category_slug'] ) ? sanitize_title( wp_unslash( $_POST['category_slug'] ) ) : '';
        if ( ! $name ) {
            $this->redirect_with_notice( 'srm-design-library-categories', 'error', [ 'message' => 'Category name is required.' ] );
        }
        $result = wp_insert_term( $name, self::TAXONOMY, $slug ? [ 'slug' => $slug ] : [] );
        if ( is_wp_error( $result ) ) {
            $this->redirect_with_notice( 'srm-design-library-categories', 'error', [ 'message' => $result->get_error_message() ] );
        }
        $this->redirect_with_notice( 'srm-design-library-categories', 'category_added' );
    }

    public function handle_delete_category() {
        $this->ensure_admin();
        $term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
        check_admin_referer( 'srmdl_delete_category_' . $term_id );
        if ( $term_id ) {
            wp_delete_term( $term_id, self::TAXONOMY );
        }
        $this->redirect_with_notice( 'srm-design-library-categories', 'category_deleted' );
    }

    public function handle_save_settings() {
        $this->ensure_admin();
        check_admin_referer( 'srmdl_save_settings', 'srmdl_nonce' );
        $name   = isset( $_POST['library_name'] ) ? sanitize_text_field( wp_unslash( $_POST['library_name'] ) ) : 'SRM Design Library';
        $accent = isset( $_POST['accent_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['accent_color'] ) ) : '#5b5bd6';
        update_option( 'srmdl_library_name', $name ?: 'SRM Design Library' );
        update_option( 'srmdl_accent_color', $accent ?: '#5b5bd6' );
        $this->redirect_with_notice( 'srm-design-library-settings', 'settings_saved' );
    }
}

<?php
/**
 * Plugin Name: Portfolio Canvas
 * Description: Infinite-pan portfolio canvas. Add items via Portfolio → Add New in the admin, then set any Page's template to "Portfolio Canvas".
 * Version:     1.1.0
 * License:     GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

define( 'PORTFOLIO_CANVAS_VERSION', '1.1.0' );
define( 'PORTFOLIO_CANVAS_GITHUB_REPO', 'lieuwe89/portfolio-canvas-plugin' );

/* ── Auto-updater via GitHub Releases ───────────────── */

add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_slug = plugin_basename( __FILE__ );
    $response    = wp_remote_get(
        'https://api.github.com/repos/' . PORTFOLIO_CANVAS_GITHUB_REPO . '/releases/latest',
        [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
            'timeout' => 10,
        ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return $transient;
    }

    $release      = json_decode( wp_remote_retrieve_body( $response ) );
    $latest       = ltrim( $release->tag_name ?? '', 'v' );
    $download_url = $release->zipball_url ?? '';

    if ( $latest && version_compare( $latest, PORTFOLIO_CANVAS_VERSION, '>' ) ) {
        $transient->response[ $plugin_slug ] = (object) [
            'slug'        => dirname( $plugin_slug ),
            'plugin'      => $plugin_slug,
            'new_version' => $latest,
            'url'         => 'https://github.com/' . PORTFOLIO_CANVAS_GITHUB_REPO,
            'package'     => $download_url,
        ];
    }

    return $transient;
} );

// Zorg dat de plugin-map na een update de goede naam heeft
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader ) {
    if ( isset( $upgrader->skin->plugin ) &&
         $upgrader->skin->plugin === plugin_basename( __FILE__ ) ) {
        $corrected = trailingslashit( $remote_source ) . 'portfolio-canvas-plugin/';
        if ( $source !== $corrected ) {
            global $wp_filesystem;
            $wp_filesystem->move( $source, $corrected );
            return $corrected;
        }
    }
    return $source;
}, 10, 3 );

/* ── 1. Custom Post Type ─────────────────────────── */

add_action( 'init', function () {

    register_post_type( 'portfolio_item', [
        'labels'        => [
            'name'               => 'Portfolio',
            'singular_name'      => 'Portfolio Item',
            'menu_name'          => 'Portfolio',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Item',
            'edit_item'          => 'Edit Item',
            'new_item'           => 'New Item',
            'view_item'          => 'View Item',
            'search_items'       => 'Search Items',
            'not_found'          => 'No items found.',
            'not_found_in_trash' => 'No items in trash.',
        ],
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'menu_icon'     => 'dashicons-images-alt2',
        'menu_position' => 20,
        'supports'      => [ 'title', 'excerpt', 'thumbnail', 'page-attributes' ],
        'show_in_rest'  => true,  // Gutenberg editor support
    ] );

    register_taxonomy( 'portfolio_cat', 'portfolio_item', [
        'labels'       => [
            'name'          => 'Categories',
            'singular_name' => 'Category',
            'add_new_item'  => 'Add New Category',
            'edit_item'     => 'Edit Category',
            'search_items'  => 'Search Categories',
        ],
        'hierarchical'  => false,
        'show_ui'       => true,
        'show_in_rest'  => true,
        'rewrite'       => false,
    ] );

} );

/* ── 2. Year meta field ──────────────────────────── */

add_action( 'init', function () {
    register_post_meta( 'portfolio_item', 'portfolio_year', [
        'show_in_rest'      => true,
        'single'            => true,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => function () {
            return current_user_can( 'edit_posts' );
        },
    ] );
} );

/* ── 3. Classic-editor meta box ──────────────────── */

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'portfolio_details',
        'Portfolio Details',
        function ( $post ) {
            wp_nonce_field( 'portfolio_save', '_portfolio_nonce' );
            $year = get_post_meta( $post->ID, 'portfolio_year', true );
            ?>
            <table class="form-table" style="margin:0">
                <tr>
                    <th style="padding:8px 0;width:80px">
                        <label for="portfolio_year">Year</label>
                    </th>
                    <td style="padding:4px 0">
                        <input type="text"
                               id="portfolio_year"
                               name="portfolio_year"
                               value="<?php echo esc_attr( $year ); ?>"
                               placeholder="<?php echo esc_attr( gmdate( 'Y' ) ); ?>"
                               style="width:100px">
                    </td>
                </tr>
            </table>
            <p class="description" style="margin-top:10px">
                <strong>Featured Image</strong> → card image<br>
                <strong>Excerpt</strong> → description shown in overlay<br>
                <strong>Title</strong> → project name<br>
                <strong>Year</strong> → shown on card &amp; in overlay
            </p>
            <?php
        },
        'portfolio_item',
        'side',
        'default'
    );
} );

add_action( 'save_post_portfolio_item', function ( $post_id ) {
    if ( ! isset( $_POST['_portfolio_nonce'] ) ) return;
    if ( ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['_portfolio_nonce'] ) ),
        'portfolio_save'
    ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( isset( $_POST['portfolio_year'] ) ) {
        update_post_meta(
            $post_id,
            'portfolio_year',
            sanitize_text_field( wp_unslash( $_POST['portfolio_year'] ) )
        );
    }
} );

/* ── 4. Admin list columns ───────────────────────── */

add_filter( 'manage_portfolio_item_posts_columns', function ( $cols ) {
    $new = [];
    foreach ( $cols as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'title' ) {
            $new['portfolio_cat']  = 'Category';
            $new['portfolio_year'] = 'Year';
        }
    }
    return $new;
} );

add_action( 'manage_portfolio_item_posts_custom_column', function ( $col, $post_id ) {
    if ( $col === 'portfolio_year' ) {
        echo esc_html( get_post_meta( $post_id, 'portfolio_year', true ) ?: '—' );
    }
    if ( $col === 'portfolio_cat' ) {
        $terms = get_the_terms( $post_id, 'portfolio_cat' );
        echo ( $terms && ! is_wp_error( $terms ) )
            ? esc_html( $terms[0]->name )
            : '—';
    }
}, 10, 2 );

/* ── 5. Page template ────────────────────────────── */

add_filter( 'theme_page_templates', function ( $templates ) {
    $templates['portfolio-canvas'] = 'Portfolio Canvas';
    return $templates;
} );

add_filter( 'template_include', function ( $template ) {
    if ( is_singular( 'page' ) && get_page_template_slug() === 'portfolio-canvas' ) {
        $tpl = plugin_dir_path( __FILE__ ) . 'canvas-page-template.php';
        if ( file_exists( $tpl ) ) {
            return $tpl;
        }
    }
    return $template;
} );

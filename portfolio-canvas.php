<?php
/**
 * Plugin Name: Portfolio Canvas
 * Description: Infinite-pan portfolio canvas. Add items via Portfolio → Add New in the admin, then set any Page's template to "Portfolio Canvas".
 * Version:     1.5.0
 * License:     GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

define( 'PORTFOLIO_CANVAS_VERSION', '1.5.0' );
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

/* ── Thumbnail ophalen uit video-URL ─────────────── */

function portfolio_canvas_video_thumbnail( $url ) {
    if ( ! $url ) return '';

    // YouTube — hqdefault bestaat altijd (480×360)
    if ( preg_match(
        '/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
        $url, $m
    ) ) {
        return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
    }

    // Vimeo — oEmbed API, resultaat wordt een week gecached per video
    if ( preg_match( '/vimeo\.com\/(\d+)/', $url, $m ) ) {
        $cache_key = 'pc_vimeo_thumb_' . $m[1];
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $response = wp_remote_get(
            'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $m[1] ),
            [ 'timeout' => 8 ]
        );

        $thumb = '';
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $data  = json_decode( wp_remote_retrieve_body( $response ) );
            $thumb = $data->thumbnail_url ?? '';
        }

        set_transient( $cache_key, $thumb, WEEK_IN_SECONDS );
        return $thumb;
    }

    // Instagram — vereist een Facebook App Token (zie Portfolio → Instellingen)
    if ( preg_match( '/instagram\.com\/(?:p|reel|tv)\/([a-zA-Z0-9_-]+)/', $url, $m ) ) {
        $token = get_option( 'portfolio_canvas_fb_token', '' );
        if ( ! $token ) return '';

        $cache_key = 'pc_ig_thumb_' . $m[1];
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $api_url  = add_query_arg( [
            'url'          => 'https://www.instagram.com/p/' . $m[1] . '/',
            'access_token' => $token,
        ], 'https://graph.facebook.com/v19.0/instagram_oembed' );

        $response = wp_remote_get( $api_url, [ 'timeout' => 8 ] );

        $thumb = '';
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $data  = json_decode( wp_remote_retrieve_body( $response ) );
            $thumb = $data->thumbnail_url ?? '';
        }

        set_transient( $cache_key, $thumb, WEEK_IN_SECONDS );
        return $thumb;
    }

    return ''; // directe videobestanden: geen auto-thumbnail
}

/* ── Instellingenpagina ──────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=portfolio_item',
        'Portfolio Canvas — Instellingen',
        'Instellingen',
        'manage_options',
        'portfolio-canvas-settings',
        'portfolio_canvas_settings_page'
    );
} );

add_action( 'admin_init', function () {
    register_setting( 'portfolio_canvas_settings', 'portfolio_canvas_fb_token', [
        'sanitize_callback' => 'sanitize_text_field',
    ] );
} );

// AJAX: token testen
add_action( 'wp_ajax_portfolio_canvas_test_token', function () {
    check_ajax_referer( 'portfolio_canvas_test', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
    if ( ! $token ) {
        wp_send_json_error( 'Geen token ingevuld.' );
    }

    // Test met een eenvoudige Graph API-aanroep
    $response = wp_remote_get(
        add_query_arg( [ 'access_token' => $token ], 'https://graph.facebook.com/v19.0/app' ),
        [ 'timeout' => 8 ]
    );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Verbindingsfout: ' . $response->get_error_message() );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = wp_remote_retrieve_response_code( $response );

    if ( 200 === $code && ! empty( $body['id'] ) ) {
        wp_send_json_success( 'Token werkt ✓ (App: ' . esc_html( $body['name'] ?? $body['id'] ) . ')' );
    } else {
        $msg = $body['error']['message'] ?? 'Onbekende fout.';
        wp_send_json_error( 'Token ongeldig: ' . esc_html( $msg ) );
    }
} );

function portfolio_canvas_settings_page() {
    $token = get_option( 'portfolio_canvas_fb_token', '' );
    ?>
    <div class="wrap">
        <h1>Portfolio Canvas — Instellingen</h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'portfolio_canvas_settings' ); ?>

            <h2 style="margin-top:24px">Instagram-thumbnails</h2>
            <p style="max-width:680px;color:#555">
                Voor automatische thumbnails van Instagram-posts heb je een
                <strong>Facebook App Token</strong> nodig. Dit token heeft de vorm
                <code>APP_ID|APP_SECRET</code> en verloopt nooit.
            </p>

            <details style="max-width:680px;margin:12px 0 20px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:12px 16px">
                <summary style="cursor:pointer;font-weight:600">Hoe maak je een App Token aan?</summary>
                <ol style="margin:12px 0 0 16px;line-height:1.9">
                    <li>Ga naar <a href="https://developers.facebook.com/apps/" target="_blank">developers.facebook.com/apps</a> en log in met je Facebook-account.</li>
                    <li>Klik op <strong>App maken</strong> → kies type <em>Business</em> → vul een naam in (bijv. <em>Portfolio</em>) → maak aan.</li>
                    <li>Ga in je app naar <strong>Instellingen → Basis</strong>.</li>
                    <li>Kopieer je <strong>App ID</strong> en je <strong>App-geheim</strong> (klik op "Tonen").</li>
                    <li>Combineer ze als: <code>APP_ID|APP_SECRET</code> (met een verticale streep ertussen).</li>
                    <li>Plak dit in het veld hieronder en sla op.</li>
                </ol>
                <p style="margin:10px 0 0;color:#777;font-size:13px">
                    ℹ️ Het App-geheim staat zichtbaar in het veld — deel dit nooit publiek.
                    Dit token geeft alleen leestoegang tot openbare Instagram-posts.
                </p>
            </details>

            <table class="form-table" style="max-width:680px">
                <tr>
                    <th style="width:160px"><label for="portfolio_canvas_fb_token">App Token</label></th>
                    <td>
                        <input type="text"
                               id="portfolio_canvas_fb_token"
                               name="portfolio_canvas_fb_token"
                               value="<?php echo esc_attr( $token ); ?>"
                               placeholder="123456789|abcdefghijklmnopqrstuvwxyz"
                               style="width:100%;max-width:500px;font-family:monospace">
                        <p style="margin-top:8px">
                            <button type="button" id="pc-test-token" class="button">
                                Token testen
                            </button>
                            <span id="pc-test-result" style="margin-left:10px;font-size:13px"></span>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Instellingen opslaan' ); ?>
        </form>
    </div>

    <script>
    document.getElementById('pc-test-token').addEventListener('click', function () {
        const btn    = this;
        const result = document.getElementById('pc-test-result');
        const token  = document.getElementById('portfolio_canvas_fb_token').value.trim();

        if ( ! token ) {
            result.style.color = '#b32d2e';
            result.textContent = 'Vul eerst een token in.';
            return;
        }

        btn.disabled    = true;
        btn.textContent = 'Bezig…';
        result.textContent = '';

        const data = new FormData();
        data.append('action', 'portfolio_canvas_test_token');
        data.append('nonce',  '<?php echo esc_js( wp_create_nonce( 'portfolio_canvas_test' ) ); ?>');
        data.append('token',  token);

        fetch(ajaxurl, { method: 'POST', body: data })
            .then( r => r.json() )
            .then( r => {
                result.style.color = r.success ? '#1a7f37' : '#b32d2e';
                result.textContent = r.data;
            } )
            .catch( () => {
                result.style.color = '#b32d2e';
                result.textContent = 'Verbindingsfout.';
            } )
            .finally( () => {
                btn.disabled    = false;
                btn.textContent = 'Token testen';
            } );
    });
    </script>
    <?php
}

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

/* ── 2. Meta fields (year + video) ───────────────── */

add_action( 'init', function () {
    foreach ( [ 'portfolio_year', 'portfolio_video' ] as $key ) {
        register_post_meta( 'portfolio_item', $key, [
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'string',
            'sanitize_callback' => $key === 'portfolio_video' ? 'esc_url_raw' : 'sanitize_text_field',
            'auth_callback'     => function () {
                return current_user_can( 'edit_posts' );
            },
        ] );
    }
} );

/* ── 3. Classic-editor meta box ──────────────────── */

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'portfolio_details',
        'Portfolio Details',
        function ( $post ) {
            wp_nonce_field( 'portfolio_save', '_portfolio_nonce' );
            $year  = get_post_meta( $post->ID, 'portfolio_year',  true );
            $video = get_post_meta( $post->ID, 'portfolio_video', true );
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
                <tr>
                    <th style="padding:8px 0">
                        <label for="portfolio_video">Video URL</label>
                    </th>
                    <td style="padding:4px 0">
                        <input type="url"
                               id="portfolio_video"
                               name="portfolio_video"
                               value="<?php echo esc_attr( $video ); ?>"
                               placeholder="https://youtube.com/watch?v=… of Vimeo-link"
                               style="width:100%">
                        <p class="description" style="margin-top:4px">
                            YouTube, Vimeo of directe .mp4-link. Gebruik de Featured Image als thumbnail.
                        </p>
                    </td>
                </tr>
            </table>
            <p class="description" style="margin-top:10px">
                <strong>Featured Image</strong> → kaartafbeelding / thumbnail<br>
                <strong>Excerpt</strong> → beschrijving in overlay<br>
                <strong>Title</strong> → projectnaam<br>
                <strong>Year</strong> → zichtbaar op kaart &amp; in overlay<br>
                <strong>Video URL</strong> → speelt af bij klikken
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
    if ( isset( $_POST['portfolio_video'] ) ) {
        update_post_meta(
            $post_id,
            'portfolio_video',
            esc_url_raw( wp_unslash( $_POST['portfolio_video'] ) )
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

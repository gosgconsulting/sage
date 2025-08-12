<?php
/**
 * Plugin Name:       Sparti Importer
 * Description:       One-click demo content importer (pages, posts, menus, images) for Sage-based themes.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Sparti
 * Text Domain:       sparti-importer
 */

if (! defined('ABSPATH')) {
    exit;
}

// Capability used for running imports from the admin.
const SPARTI_IMPORT_CAP = 'manage_options';
const SPARTI_DEMO_META_KEY = '_sparti_demo';
const SPARTI_DEMO_MENU_OPTION = 'sparti_demo_menu_id';

/**
 * Register admin page under Tools.
 */
add_action('admin_menu', function (): void {
    add_management_page(
        __('Sparti Importer', 'sparti-importer'),
        __('Sparti Importer', 'sparti-importer'),
        SPARTI_IMPORT_CAP,
        'sparti-importer',
        'sparti_importer_render_admin_page'
    );
});

/**
 * Render the importer admin page.
 */
function sparti_importer_render_admin_page(): void
{
    if (! current_user_can(SPARTI_IMPORT_CAP)) {
        wp_die(esc_html__('You do not have permission to access this page.', 'sparti-importer'));
    }

    $did_action = false;
    $message = '';
    $is_error = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sparti_importer_action'])) {
        check_admin_referer('sparti_importer_nonce_action', 'sparti_importer_nonce');
        $action = sanitize_text_field(wp_unslash($_POST['sparti_importer_action']));

        if ($action === 'import') {
            $result = sparti_importer_run_import();
            $did_action = true;
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $is_error = true;
            } else {
                $summary = sprintf(
                    /* translators: 1: pages created, 2: posts created, 3: images imported */
                    __('Imported: %1$d pages, %2$d posts, %3$d images. Menu assigned.', 'sparti-importer'),
                    intval($result['pages_created'] ?? 0),
                    intval($result['posts_created'] ?? 0),
                    intval($result['images_imported'] ?? 0)
                );
                $message = $summary;
                $is_error = false;
            }
        } elseif ($action === 'remove') {
            $removed = sparti_importer_remove_demo_content();
            $did_action = true;
            $message = sprintf(
                /* translators: 1: items removed */
                __('Removed %d demo items (pages, posts, attachments, and menu if created).', 'sparti-importer'),
                intval($removed)
            );
        }
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Sparti Importer', 'sparti-importer') . '</h1>';
    echo '<p>' . esc_html__('Create demo pages, posts, menus, and images for local development. Safe to run multiple times; it will not duplicate content.', 'sparti-importer') . '</p>';

    if ($did_action) {
        printf(
            '<div class="notice %1$s"><p>%2$s</p></div>',
            $is_error ? 'notice-error' : 'notice-success',
            wp_kses_post($message)
        );
    }

    echo '<form method="post" style="margin-top:16px;">';
    wp_nonce_field('sparti_importer_nonce_action', 'sparti_importer_nonce');
    echo '<input type="hidden" name="sparti_importer_action" value="import" />';
    submit_button(esc_html__('Run Import', 'sparti-importer'));
    echo '</form>';

    echo '<form method="post" style="margin-top:12px;">';
    wp_nonce_field('sparti_importer_nonce_action', 'sparti_importer_nonce');
    echo '<input type="hidden" name="sparti_importer_action" value="remove" />';
    submit_button(esc_html__('Remove Demo Content', 'sparti-importer'), 'delete');
    echo '</form>';

    echo '</div>';
}

/**
 * Import demo content: images, pages, posts, menu, reading settings.
 *
 * @return array|WP_Error
 */
function sparti_importer_run_import()
{
    if (! current_user_can(SPARTI_IMPORT_CAP)) {
        return new WP_Error('sparti_no_cap', __('Insufficient permissions.', 'sparti-importer'));
    }

    sparti_importer_ensure_media_functions_loaded();

    $result = [
        'images_imported' => 0,
        'pages_created'   => 0,
        'posts_created'   => 0,
    ];

    // 1) Import images (remote placeholders), tag with meta for cleanup.
    $image_specs = [
        [ 'url' => 'https://picsum.photos/seed/sparti-1/1600/900', 'title' => 'Sparti Demo Image 1' ],
        [ 'url' => 'https://picsum.photos/seed/sparti-2/1600/900', 'title' => 'Sparti Demo Image 2' ],
        [ 'url' => 'https://picsum.photos/seed/sparti-3/1600/900', 'title' => 'Sparti Demo Image 3' ],
        [ 'url' => 'https://picsum.photos/seed/sparti-4/1600/900', 'title' => 'Sparti Demo Image 4' ],
        [ 'url' => 'https://picsum.photos/seed/sparti-5/1600/900', 'title' => 'Sparti Demo Image 5' ],
        [ 'url' => 'https://picsum.photos/seed/sparti-6/1600/900', 'title' => 'Sparti Demo Image 6' ],
    ];

    $imported_images = [];
    foreach ($image_specs as $spec) {
        $attachment_id = sparti_importer_import_image($spec['url'], $spec['title']);
        if (is_wp_error($attachment_id)) {
            // Continue; non-fatal if any one image fails.
            continue;
        }
        $url = wp_get_attachment_image_url($attachment_id, 'full');
        if ($url) {
            $imported_images[] = [ 'id' => $attachment_id, 'url' => $url ];
            $result['images_imported']++;
        }
    }

    if (empty($imported_images)) {
        // Ensure at least an empty placeholder to avoid undefined offset.
        $imported_images[] = [ 'id' => 0, 'url' => '' ];
    }

    // 2) Create or ensure pages.
    $pages = [
        [ 'title' => 'Home',    'slug' => 'home',   'content' => '' ],
        [ 'title' => 'About',   'slug' => 'about',  'content' => '' ],
        [ 'title' => 'Services','slug' => 'services','content' => '' ],
        [ 'title' => 'Contact', 'slug' => 'contact','content' => '' ],
        [ 'title' => 'Blog',    'slug' => 'blog',   'content' => '' ],
    ];

    $page_ids = [];

    foreach ($pages as $index => $page) {
        $existing = get_page_by_path($page['slug'], OBJECT, 'page');
        if ($existing instanceof WP_Post) {
            $page_ids[$page['slug']] = (int) $existing->ID;
            continue;
        }

        $img = $imported_images[$index % count($imported_images)];
        // Build a full one-page landing for Home; simple section for others
        if ($page['slug'] === 'home') {
            $content = sparti_importer_build_homepage_content($imported_images);
        } else {
            $content = sparti_importer_build_page_block_content($page['title'], $img['id'], $img['url']);
        }

        $id = wp_insert_post([
            'post_title'   => $page['title'],
            'post_name'    => $page['slug'],
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => $content,
        ], true);

        if (! is_wp_error($id) && $id) {
            $page_ids[$page['slug']] = (int) $id;
            update_post_meta($id, SPARTI_DEMO_META_KEY, '1');
            $result['pages_created']++;
        }
    }

    // 3) Set reading settings if we created the pages.
    if (! empty($page_ids['home'])) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', (int) $page_ids['home']);
    }
    if (! empty($page_ids['blog'])) {
        update_option('page_for_posts', (int) $page_ids['blog']);
    }

    // 4) Ensure a couple of demo categories exist.
    $category_slugs = ['news' => 'News', 'updates' => 'Updates'];
    $category_ids = [];
    foreach ($category_slugs as $slug => $name) {
        $term = get_term_by('slug', $slug, 'category');
        if ($term && ! is_wp_error($term)) {
            $category_ids[] = (int) $term->term_id;
            continue;
        }
        $created = wp_insert_term($name, 'category', ['slug' => $slug]);
        if (! is_wp_error($created) && isset($created['term_id'])) {
            $category_ids[] = (int) $created['term_id'];
        }
    }

    // 5) Create demo posts with featured images and simple block content.
    $posts_to_create = 5;
    $posts_created = 0;
    for ($i = 1; $i <= $posts_to_create; $i++) {
        $title = sprintf(__('Demo Post %d', 'sparti-importer'), $i);

        // Avoid duplicates on re-run by checking existing by title and our meta.
        $existing = get_posts([
            'post_type'      => 'post',
            'title'          => $title,
            'meta_key'       => SPARTI_DEMO_META_KEY,
            'meta_value'     => '1',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        if (! empty($existing)) {
            continue;
        }

        $img = $imported_images[$i % count($imported_images)];
        $content = sparti_importer_build_post_block_content($title, $img['id'], $img['url']);

        $post_id = wp_insert_post([
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $content,
        ], true);

        if (! is_wp_error($post_id) && $post_id) {
            if (! empty($img['id'])) {
                set_post_thumbnail($post_id, (int) $img['id']);
            }
            update_post_meta($post_id, SPARTI_DEMO_META_KEY, '1');
            if (! empty($category_ids)) {
                wp_set_post_terms($post_id, $category_ids, 'category', false);
            }
            $posts_created++;
        }
    }
    $result['posts_created'] = $posts_created;

    // 6) Create and assign Primary menu with imported pages.
    $menu_id = sparti_importer_ensure_menu('Primary');
    if ($menu_id) {
        $menu_items_slugs = ['home', 'about', 'services', 'contact', 'blog'];
        foreach ($menu_items_slugs as $slug) {
            if (empty($page_ids[$slug])) {
                continue;
            }
            sparti_importer_ensure_menu_item($menu_id, (int) $page_ids[$slug]);
        }

        sparti_importer_assign_menu_location($menu_id);
        if (! get_option(SPARTI_DEMO_MENU_OPTION)) {
            update_option(SPARTI_DEMO_MENU_OPTION, (int) $menu_id);
        }
    }

    return $result;
}

/**
 * Import a remote image into the Media Library.
 */
function sparti_importer_import_image(string $url, string $title)
{
    if (empty($url)) {
        return new WP_Error('sparti_no_url', __('Image URL is empty.', 'sparti-importer'));
    }

    // Avoid re-import by checking existing attachment with same source URL stored in meta.
    $existing = get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'meta_key'       => '_sparti_source_url',
        'meta_value'     => $url,
        'fields'         => 'ids',
    ]);
    if (! empty($existing)) {
        return (int) $existing[0];
    }

    $attachment_id = media_sideload_image($url, 0, $title, 'id');
    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }
    update_post_meta($attachment_id, SPARTI_DEMO_META_KEY, '1');
    update_post_meta($attachment_id, '_sparti_source_url', esc_url_raw($url));
    return (int) $attachment_id;
}

/**
 * Build Gutenberg block content for a demo page.
 */
function sparti_importer_build_page_block_content(string $title, int $image_id, string $image_url): string
{
    $image_block = '';
    if ($image_id && $image_url) {
        $image_block = sprintf(
            '<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} -->'
            . '<figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure>'
            . '<!-- /wp:image -->',
            $image_id,
            esc_url($image_url)
        );
    }

    $content = sprintf(
        '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:heading {"textAlign":"center","level":1} -->'
        . '<h1 class="has-text-align-center">%1$s</h1>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:paragraph {"align":"center"} -->'
        . '<p class="has-text-align-center">%2$s</p>'
        . '<!-- /wp:paragraph -->'
        . '%3$s'
        . '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->'
        . '<div class="wp-block-buttons">'
        . '<!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Get Started</a></div>'
        . '<!-- /wp:button -->'
        . '</div>'
        . '<!-- /wp:buttons -->'
        . '</div>'
        . '<!-- /wp:group -->',
        esc_html($title),
        esc_html__('This is demo content created by Sparti Importer. Replace with your own content.', 'sparti-importer'),
        $image_block
    );

    return $content;
}

/**
 * Build a one-page landing homepage using core Gutenberg blocks.
 */
function sparti_importer_build_homepage_content(array $imported_images): string
{
    // Helper to pick an image url/id
    $pick = function (int $i) use ($imported_images) {
        $img = $imported_images[$i % max(1, count($imported_images))];
        return [
            'id'  => (int) ($img['id'] ?? 0),
            'url' => (string) ($img['url'] ?? ''),
        ];
    };

    $hero = $pick(0);
    $feat1 = $pick(1);
    $feat2 = $pick(2);
    $feat3 = $pick(3);
    $testi = $pick(4);

    // Hero section with Cover
    $hero_block = sprintf(
        '<!-- wp:cover {"url":"%1$s","dimRatio":40,"overlayColor":"black","minHeight":60,"minHeightUnit":"vh","contentPosition":"center center"} -->'
        . '<div class="wp-block-cover is-light" style="min-height:60vh"><span aria-hidden="true" class="wp-block-cover__background has-black-background-color has-background-dim-40 has-background-dim"></span><img class="wp-block-cover__image-background" alt="" src="%1$s" data-object-fit="cover"/>'
        . '<div class="wp-block-cover__inner-container">'
        . '<!-- wp:group {"layout":{"type":"constrained","contentSize":"1100px"}} -->'
        . '<div class="wp-block-group"><!-- wp:heading {"textAlign":"center","level":1} -->'
        . '<h1 class="has-text-align-center">Build with Sparti + Sage</h1><!-- /wp:heading --><!-- wp:paragraph {"align":"center","fontSize":"large"} -->'
        . '<p class="has-text-align-center has-large-font-size">Modern WordPress theme with Blade, Tailwind, and Vite.</p><!-- /wp:paragraph --><!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->'
        . '<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-fill"} -->'
        . '<div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button" href="#features">Explore Features</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} -->'
        . '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#contact">Contact</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->'
        . '</div></div><!-- /wp:cover -->',
        esc_url($hero['url'])
    );

    // Features section
    $features_block = sprintf(
        '<!-- wp:group {"tagName":"section","layout":{"type":"constrained","contentSize":"1100px"},"style":{"spacing":{"padding":{"top":"4rem","bottom":"4rem"}}},"anchor":"features"} -->'
        . '<section id="features" class="wp-block-group" style="padding-top:4rem;padding-bottom:4rem"><!-- wp:heading {"textAlign":"center","level":2} -->'
        . '<h2 class="has-text-align-center">Why Sparti</h2><!-- /wp:heading --><!-- wp:columns -->'
        . '<div class="wp-block-columns"><!-- wp:column -->'
        . '<div class="wp-block-column"><!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} -->'
        . '<figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image --><!-- wp:heading {"level":3} -->'
        . '<h3>Blade Templates</h3><!-- /wp:heading --><!-- wp:paragraph -->'
        . '<p>Clean, reusable UI with Laravel Blade inside WordPress.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column -->'
        . '<div class="wp-block-column"><!-- wp:image {"id":%3$d,"sizeSlug":"large","linkDestination":"none"} -->'
        . '<figure class="wp-block-image size-large"><img src="%4$s" alt="" class="wp-image-%3$d"/></figure><!-- /wp:image --><!-- wp:heading {"level":3} -->'
        . '<h3>Tailwind CSS</h3><!-- /wp:heading --><!-- wp:paragraph -->'
        . '<p>Utility-first styling with fast iteration and consistency.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column -->'
        . '<div class="wp-block-column"><!-- wp:image {"id":%5$d,"sizeSlug":"large","linkDestination":"none"} -->'
        . '<figure class="wp-block-image size-large"><img src="%6$s" alt="" class="wp-image-%5$d"/></figure><!-- /wp:image --><!-- wp:heading {"level":3} -->'
        . '<h3>Vite + HMR</h3><!-- /wp:heading --><!-- wp:paragraph -->'
        . '<p>Modern asset pipeline with instant reloads and builds.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --></section><!-- /wp:group -->',
        $feat1['id'], esc_url($feat1['url']), $feat2['id'], esc_url($feat2['url']), $feat3['id'], esc_url($feat3['url'])
    );

    // Testimonials section
    $testimonials_block = sprintf(
        '<!-- wp:group {"tagName":"section","layout":{"type":"constrained","contentSize":"900px"},"style":{"spacing":{"padding":{"top":"3rem","bottom":"3rem"}}}} -->'
        . '<section class="wp-block-group" style="padding-top:3rem;padding-bottom:3rem"><!-- wp:heading {"textAlign":"center","level":2} -->'
        . '<h2 class="has-text-align-center">What people say</h2><!-- /wp:heading --><!-- wp:columns -->'
        . '<div class="wp-block-columns"><!-- wp:column -->'
        . '<div class="wp-block-column"><!-- wp:quote -->'
        . '<blockquote class="wp-block-quote"><p>“Sage supercharged our WordPress development.”</p><cite>Dev Lead</cite></blockquote><!-- /wp:quote --></div><!-- /wp:column --><!-- wp:column -->'
        . '<div class="wp-block-column"><!-- wp:quote -->'
        . '<blockquote class="wp-block-quote"><p>“Blade + Tailwind made our UI work a breeze.”</p><cite>Product Designer</cite></blockquote><!-- /wp:quote --></div><!-- /wp:column --></div><!-- /wp:columns --></section><!-- /wp:group -->'
    );

    // CTA section
    $cta_block = sprintf(
        '<!-- wp:group {"tagName":"section","layout":{"type":"constrained","contentSize":"900px"},"style":{"color":{"background":"#0f172a"},"spacing":{"padding":{"top":"3rem","bottom":"3rem","left":"2rem","right":"2rem"}}},"textColor":"white","anchor":"contact"} -->'
        . '<section id="contact" class="wp-block-group has-white-color has-text-color" style="background-color:#0f172a;padding-top:3rem;padding-right:2rem;padding-bottom:3rem;padding-left:2rem">'
        . '<!-- wp:heading {"textAlign":"center","level":2} -->'
        . '<h2 class="has-text-align-center">Ready to build?</h2><!-- /wp:heading --><!-- wp:paragraph {"align":"center"} -->'
        . '<p class="has-text-align-center">Start with this theme, then customize blocks and patterns.</p><!-- /wp:paragraph --><!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->'
        . '<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"white","textColor":"black"} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link has-white-background-color has-black-color has-text-color has-background wp-element-button" href="#">Get Started</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
        . '</section><!-- /wp:group -->'
    );

    return $hero_block . $features_block . $testimonials_block . $cta_block;
}

/**
 * Build Gutenberg block content for a demo post.
 */
function sparti_importer_build_post_block_content(string $title, int $image_id, string $image_url): string
{
    $image_block = '';
    if ($image_id && $image_url) {
        $image_block = sprintf(
            '<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} -->'
            . '<figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure>'
            . '<!-- /wp:image -->',
            $image_id,
            esc_url($image_url)
        );
    }

    $content = sprintf(
        '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:heading -->'
        . '<h2>%1$s</h2>'
        . '<!-- /wp:heading -->'
        . '<!-- wp:paragraph -->'
        . '<p>%2$s</p>'
        . '<!-- /wp:paragraph -->'
        . '%3$s'
        . '<!-- wp:paragraph -->'
        . '<p>%4$s</p>'
        . '<!-- /wp:paragraph -->'
        . '</div>'
        . '<!-- /wp:group -->',
        esc_html($title),
        esc_html__('This is a sample post body. Edit this in the block editor.', 'sparti-importer'),
        $image_block,
        esc_html__('Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer nec odio. Praesent libero. Sed cursus ante dapibus diam.', 'sparti-importer')
    );

    return $content;
}

/**
 * Ensure WordPress media helper functions are loaded.
 */
function sparti_importer_ensure_media_functions_loaded(): void
{
    if (! function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
}

/**
 * Ensure a nav menu exists, returning its term ID.
 */
function sparti_importer_ensure_menu(string $menu_name): int
{
    $menu = wp_get_nav_menu_object($menu_name);
    if ($menu && isset($menu->term_id)) {
        return (int) $menu->term_id;
    }
    $menu_id = wp_create_nav_menu($menu_name);
    return (int) $menu_id;
}

/**
 * Ensure a nav menu has a menu item for the given object ID.
 */
function sparti_importer_ensure_menu_item(int $menu_id, int $object_id): void
{
    $items = wp_get_nav_menu_items($menu_id);
    $exists = false;
    if ($items) {
        foreach ($items as $item) {
            if ((int) $item->object_id === $object_id) {
                $exists = true;
                break;
            }
        }
    }
    if ($exists) {
        return;
    }
    wp_update_nav_menu_item($menu_id, 0, [
        'menu-item-object-id' => $object_id,
        'menu-item-object'    => 'page',
        'menu-item-type'      => 'post_type',
        'menu-item-status'    => 'publish',
    ]);
}

/**
 * Assign menu to a sensible location. Prefer Sage's primary_navigation.
 */
function sparti_importer_assign_menu_location(int $menu_id): void
{
    $registered = get_registered_nav_menus();
    if (! is_array($registered) || empty($registered)) {
        return;
    }

    $preferred = null;
    if (array_key_exists('primary_navigation', $registered)) {
        $preferred = 'primary_navigation';
    } elseif (array_key_exists('primary', $registered)) {
        $preferred = 'primary';
    } else {
        // Fallback to the first registered location.
        $keys = array_keys($registered);
        $preferred = $keys[0];
    }

    $locations = get_theme_mod('nav_menu_locations');
    if (! is_array($locations)) {
        $locations = [];
    }
    $locations[$preferred] = $menu_id;
    set_theme_mod('nav_menu_locations', $locations);
}

/**
 * Remove all demo content created by this plugin.
 *
 * @return int Number of items deleted
 */
function sparti_importer_remove_demo_content(): int
{
    if (! current_user_can(SPARTI_IMPORT_CAP)) {
        return 0;
    }

    $deleted = 0;

    // Delete pages and posts marked with our meta.
    $posts = get_posts([
        'post_type'      => ['page', 'post'],
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'meta_key'       => SPARTI_DEMO_META_KEY,
        'meta_value'     => '1',
        'fields'         => 'ids',
    ]);
    foreach ($posts as $post_id) {
        $res = wp_delete_post((int) $post_id, true);
        if ($res) {
            $deleted++;
        }
    }

    // Delete attachments marked with our meta.
    $attachments = get_posts([
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'meta_key'       => SPARTI_DEMO_META_KEY,
        'meta_value'     => '1',
        'fields'         => 'ids',
    ]);
    foreach ($attachments as $att_id) {
        $res = wp_delete_attachment((int) $att_id, true);
        if ($res) {
            $deleted++;
        }
    }

    // Delete the demo menu if we created one.
    $menu_id = (int) get_option(SPARTI_DEMO_MENU_OPTION, 0);
    if ($menu_id > 0) {
        $menu = wp_get_nav_menu_object($menu_id);
        if ($menu) {
            wp_delete_nav_menu($menu);
            $deleted++;
        }
        delete_option(SPARTI_DEMO_MENU_OPTION);
    }

    return $deleted;
}



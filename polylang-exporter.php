<?php
/**
 * Plugin Name: Polylang WXR Exporter
 * Description: Exports Polylang posts in WordPress Importer-compatible WXR format.
 * Version: 0.0.2
 * Author: Femi
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// 1️⃣ Create Custom Export Page in Admin
function ple_add_custom_export_page() {
    add_management_page(
        'Polylang Export', 
        'Polylang Export', 
        'manage_options', 
        'ple-polylang-export', 
        'ple_render_export_page'
    );
}
add_action('admin_menu', 'ple_add_custom_export_page');

// 2️⃣ Render the Custom Export Page
function ple_render_export_page() {
    $current_language = function_exists('pll_current_language') ? pll_current_language('slug') : '';
    $woocommerce_active = class_exists('WooCommerce');

    ?>
    <div class="wrap">
        <h1>Polylang WXR Export</h1>
        <p><strong>Current Language:</strong> <?php echo strtoupper($current_language); ?></p>
        <form method="POST">
            <?php wp_nonce_field('ple_export_nonce', 'ple_export_nonce'); ?>
            <input type="hidden" name="export_language" value="<?php echo esc_attr($current_language); ?>">
            
            <label><strong>Select Post Types:</strong></label><br>
            <input type="checkbox" name="export_types[]" value="post" checked> Posts <br>
            <input type="checkbox" name="export_types[]" value="page" checked> Pages <br>
            <?php if ($woocommerce_active): ?>
                <input type="checkbox" name="export_types[]" value="product"> Products <br>
            <?php endif; ?>

            <br>
            <input type="submit" name="ple_export_submit" class="button button-primary" value="Download WXR Export">
        </form>
    </div>
    <?php
}

// 3️⃣ Handle Export as WXR
function ple_handle_export() {
    if (isset($_POST['ple_export_submit'])) {
        if (!isset($_POST['ple_export_nonce']) || !wp_verify_nonce($_POST['ple_export_nonce'], 'ple_export_nonce')) {
            die("Security check failed");
        }

        $selected_language = function_exists('pll_current_language') ? pll_current_language('slug') : '';
        $selected_types = isset($_POST['export_types']) ? array_map('sanitize_text_field', $_POST['export_types']) : [];

        if (empty($selected_language) || empty($selected_types)) {
            wp_die("Error: No language or post types selected.");
        }

        global $wpdb;
        $post_ids = $wpdb->get_col($wpdb->prepare("
            SELECT object_id FROM {$wpdb->prefix}term_relationships
            WHERE term_taxonomy_id IN (
                SELECT term_taxonomy_id FROM {$wpdb->prefix}term_taxonomy
                WHERE taxonomy = 'language' AND term_id = (
                    SELECT term_id FROM {$wpdb->prefix}terms WHERE slug = %s
                )
            )
        ", $selected_language));

        $posts = get_posts([
            'post__in'   => $post_ids,
            'post_type'  => $selected_types,
            'post_status'=> 'publish',
            'numberposts'=> -1
        ]);

        if (empty($posts)) {
            wp_die("No posts found for the current language ($selected_language) and selected post types.");
        }

        // Start WXR Output
        header('Content-Type: application/rss+xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="polylang-export-' . $selected_language . '.wxr"');

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<!-- This is a WordPress eXtended RSS (WXR) file for importing into WordPress -->';
        echo '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">';

        echo '<channel>';
        echo '<title>' . get_bloginfo('name') . ' - ' . strtoupper($selected_language) . '</title>';
        echo '<link>' . esc_url(get_bloginfo('url')) . '</link>';
        echo '<language>' . esc_attr($selected_language) . '</language>';
        echo '<wp:wxr_version>1.2</wp:wxr_version>';

        // Export Authors (At least one is required)
        echo '<wp:author>';
        echo '<wp:author_id>1</wp:author_id>';
        echo '<wp:author_login><![CDATA[admin]]></wp:author_login>';
        echo '<wp:author_email><![CDATA[admin@example.com]]></wp:author_email>';
        echo '<wp:author_display_name><![CDATA[Admin]]></wp:author_display_name>';
        echo '</wp:author>';

        foreach ($posts as $post) {
            echo '<item>';
            echo '<title>' . esc_xml($post->post_title) . '</title>';
            echo '<link>' . esc_url(get_permalink($post->ID)) . '</link>';
            echo '<wp:post_id>' . esc_xml($post->ID) . '</wp:post_id>';
            echo '<wp:post_date>' . esc_xml($post->post_date) . '</wp:post_date>';
            echo '<wp:post_type>' . esc_xml($post->post_type) . '</wp:post_type>';
            echo '<wp:status>' . esc_xml($post->post_status) . '</wp:status>';
            echo '<content:encoded><![CDATA[' . $post->post_content . ']]></content:encoded>';

            // Export Categories and Tags
            $categories = get_the_category($post->ID);
            foreach ($categories as $category) {
                echo '<category domain="category"><![CDATA[' . esc_xml($category->name) . ']]></category>';
            }
            $tags = get_the_tags($post->ID);
            if ($tags) {
                foreach ($tags as $tag) {
                    echo '<category domain="post_tag"><![CDATA[' . esc_xml($tag->name) . ']]></category>';
                }
            }

            // Export Polylang Language Taxonomy
            echo '<category domain="language"><![CDATA[' . esc_xml($selected_language) . ']]></category>';

            // Export Post Meta
            $meta_data = get_post_meta($post->ID);
            foreach ($meta_data as $meta_key => $meta_values) {
                foreach ($meta_values as $meta_value) {
                    echo '<wp:postmeta>';
                    echo '<wp:meta_key>' . esc_xml($meta_key) . '</wp:meta_key>';
                    echo '<wp:meta_value><![CDATA[' . maybe_serialize($meta_value) . ']]></wp:meta_value>';
                    echo '</wp:postmeta>';
                }
            }

            echo '</item>';
        }

        echo '</channel>';
        echo '</rss>';
        exit;
    }
}
add_action('admin_init', 'ple_handle_export');

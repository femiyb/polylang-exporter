<?php
/**
 * Plugin Name: Polylang Custom Export
 * Description: Allows exporting posts, pages, and products in the current Polylang language as an XML file.
 * Version: 1.2
 * Author: Your Name
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

// 2️⃣ Get the Current Admin Language
function ple_get_current_admin_language() {
    if (function_exists('pll_current_language')) {
        return pll_current_language('slug'); // Get the language slug
    }
    return ''; // Default to empty if Polylang is not active
}

// 3️⃣ Render the Custom Export Page with Checkboxes
function ple_render_export_page() {
    $current_language = ple_get_current_admin_language();
    if (!$current_language) {
        echo '<div class="error"><p>Polylang is not active or the language could not be detected.</p></div>';
        return;
    }

    // Check if WooCommerce is active
    $woocommerce_active = class_exists('WooCommerce');

    ?>

    <div class="wrap">
        <h1>Polylang Custom Export</h1>
        <p><strong>Current Language:</strong> <?php echo strtoupper($current_language); ?></p>
        <form method="POST" action="">
            <?php wp_nonce_field('ple_export_nonce', 'ple_export_nonce'); ?>
            <input type="hidden" name="export_language" value="<?php echo esc_attr($current_language); ?>">

            <label><strong>Select Post Types:</strong></label><br>
            <input type="checkbox" name="export_types[]" value="post" checked> Posts <br>
            <input type="checkbox" name="export_types[]" value="page" checked> Pages <br>
            <?php if ($woocommerce_active): ?>
                <input type="checkbox" name="export_types[]" value="product"> Products <br>
            <?php endif; ?>

            <br>
            <input type="submit" name="ple_export_submit" class="button button-primary" value="Download Export for <?php echo strtoupper($current_language); ?>">
        </form>
    </div>

    <?php
}

// 4️⃣ Handle Export on Form Submission
function ple_handle_export() {
    if (isset($_POST['ple_export_submit'])) {
        if (!isset($_POST['ple_export_nonce']) || !wp_verify_nonce($_POST['ple_export_nonce'], 'ple_export_nonce')) {
            die("Security check failed");
        }

        $selected_language = isset($_POST['export_language']) ? sanitize_text_field($_POST['export_language']) : '';
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
            'post_type'  => $selected_types, // Use selected post types
            'post_status'=> 'publish',
            'numberposts'=> -1
        ]);

        if (empty($posts)) {
            wp_die("No posts found for the current language ($selected_language) and selected post types.");
        }

        // Generate XML
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="polylang-export-' . $selected_language . '.xml"');

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<posts>';

        foreach ($posts as $post) {
            echo '<post>';
            echo '<id>' . esc_xml($post->ID) . '</id>';
            echo '<title>' . esc_xml($post->post_title) . '</title>';
            echo '<content><![CDATA[' . $post->post_content . ']]></content>';
            echo '<type>' . esc_xml($post->post_type) . '</type>';
            echo '</post>';
        }

        echo '</posts>';
        exit;
    }
}
add_action('admin_init', 'ple_handle_export');

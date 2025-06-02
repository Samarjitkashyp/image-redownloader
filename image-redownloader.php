<?php
/**
 * Plugin Name: Image Re-Downloader
 * Description: Downloads all images from the media library and re-uploads them as new media, with grid layout, duplicate delete option, filters, sorting, and CSV export.
 * Version: 1.7.0
 * Author: Samarjit Kashyp
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'ird_add_admin_menu');

function ird_add_admin_menu() {
    add_menu_page('Image Re-Downloader', 'Image Re-Downloader', 'manage_options', 'image-redownloader', 'ird_plugin_page');
    add_submenu_page('image-redownloader', 'Re-Upload Log', 'Re-Upload Log', 'manage_options', 'ird-reupload-log', 'ird_log_page');
    add_submenu_page('image-redownloader', 'Author', 'Author', 'manage_options', 'ird-author', 'ird_author_page');
}

function ird_plugin_page() {
    ?>
    <div class="wrap">
        <h1>Image Re-Downloader</h1>

        <form method="post">
            <?php wp_nonce_field('ird_reupload_images', 'ird_nonce'); ?>
            <label style="font-weight: bold; font-size: 15px; display: block; margin-bottom: 8px;">
                <input type="checkbox" name="ird_delete_duplicates" value="1" style="margin-right:6px;" <?php checked(get_option('ird_delete_duplicates', false), true); ?>>
                Delete Duplicate Images
            </label>
            <?php submit_button('Download and Re-Upload All Images'); ?>
        </form>

        <form method="get" style="margin-top: 20px;">
            <h2>Filter & Sort Images</h2>

            <label>Status:
                <select name="ird_filter_status">
                    <option value="">-- All --</option>
                    <option value="Re-uploaded" <?php selected($_GET['ird_filter_status'] ?? '', 'Re-uploaded'); ?>>Re-uploaded</option>
                    <option value="Re-uploaded & Deleted Duplicate" <?php selected($_GET['ird_filter_status'] ?? '', 'Re-uploaded & Deleted Duplicate'); ?>>Re-uploaded & Deleted Duplicate</option>
                    <option value="Upload Error" <?php selected($_GET['ird_filter_status'] ?? '', 'Upload Error'); ?>>Upload Error</option>
                    <option value="Download Error" <?php selected($_GET['ird_filter_status'] ?? '', 'Download Error'); ?>>Download Error</option>
                </select>
            </label>

            <label style="margin-left: 20px;">
                Alt Text:
                <select name="ird_filter_alt">
                    <option value="">-- All --</option>
                    <option value="has" <?php selected($_GET['ird_filter_alt'] ?? '', 'has'); ?>>Has Alt Text</option>
                    <option value="missing" <?php selected($_GET['ird_filter_alt'] ?? '', 'missing'); ?>>Missing Alt Text</option>
                </select>
            </label>

            <label style="margin-left: 20px;">
                Sort by:
                <select name="ird_sort_by">
                    <option value="date_desc" <?php selected($_GET['ird_sort_by'] ?? '', 'date_desc'); ?>>Upload Date (Newest)</option>
                    <option value="date_asc" <?php selected($_GET['ird_sort_by'] ?? '', 'date_asc'); ?>>Upload Date (Oldest)</option>
                    <option value="title_asc" <?php selected($_GET['ird_sort_by'] ?? '', 'title_asc'); ?>>Title (A-Z)</option>
                    <option value="title_desc" <?php selected($_GET['ird_sort_by'] ?? '', 'title_desc'); ?>>Title (Z-A)</option>
                </select>
            </label>

            <input type="submit" class="button button-primary" value="Apply Filters" />
            <a href="<?php echo esc_url(remove_query_arg(['ird_filter_status','ird_filter_alt','ird_sort_by'])); ?>" class="button">Reset</a>
        </form>

        <form method="post" style="margin-top:10px;">
            <?php submit_button('Preview Demo Layout', 'secondary', 'ird_demo_preview'); ?>
        </form>

        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="ird_export_csv" value="1" />
            <?php submit_button('Export Image Metadata to CSV'); ?>
        </form>
    </div>
    <?php

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && current_user_can('manage_options')) {
        if (isset($_POST['ird_nonce']) && wp_verify_nonce($_POST['ird_nonce'], 'ird_reupload_images')) {
            update_option('ird_delete_duplicates', isset($_POST['ird_delete_duplicates']) ? true : false);

            $delete_dupes = get_option('ird_delete_duplicates', false);
            ird_process_images($delete_dupes);

        } elseif (isset($_POST['ird_demo_preview'])) {
            ird_demo_grid_preview();

        } elseif (isset($_POST['ird_export_csv'])) {
            ird_export_csv();
        }
    } else {
        // Show grid with filters and sorting
        $log = get_option('ird_reupload_log', []);
        if (!empty($log)) {
            $filtered_log = ird_apply_filters_and_sort($log);
            ird_render_grid($filtered_log);
        }
    }
}

function ird_apply_filters_and_sort($log) {
    $filter_status = $_GET['ird_filter_status'] ?? '';
    $filter_alt = $_GET['ird_filter_alt'] ?? '';
    $sort_by = $_GET['ird_sort_by'] ?? 'date_desc';

    // Filter by status
    if ($filter_status) {
        $log = array_filter($log, function($item) use ($filter_status) {
            return stripos($item['status'], $filter_status) !== false;
        });
    }

    // Filter by alt text presence
    if ($filter_alt) {
        $log = array_filter($log, function($item) use ($filter_alt) {
            $file_name = $item['image'];
            $attachments = get_posts([
                'post_type' => 'attachment',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_wp_attached_file',
                        'value' => $file_name,
                        'compare' => 'LIKE',
                    ]
                ],
            ]);
            if (empty($attachments)) return false;
            $alt = get_post_meta($attachments[0]->ID, '_wp_attachment_image_alt', true);
            if ($filter_alt === 'has') {
                return !empty($alt);
            } else {
                return empty($alt);
            }
        });
    }

    // Sort the array
    usort($log, function($a, $b) use ($sort_by) {
        $a_name = $a['image'];
        $b_name = $b['image'];

        $a_attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => [
                ['key' => '_wp_attached_file', 'value' => $a_name, 'compare' => 'LIKE'],
            ],
        ]);
        $b_attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => [
                ['key' => '_wp_attached_file', 'value' => $b_name, 'compare' => 'LIKE'],
            ],
        ]);

        $a_id = $a_attachments ? $a_attachments[0]->ID : 0;
        $b_id = $b_attachments ? $b_attachments[0]->ID : 0;

        switch ($sort_by) {
            case 'date_asc':
                return get_post_time('U', false, $a_id) <=> get_post_time('U', false, $b_id);
            case 'date_desc':
                return get_post_time('U', false, $b_id) <=> get_post_time('U', false, $a_id);
            case 'title_asc':
                return strcmp(get_the_title($a_id), get_the_title($b_id));
            case 'title_desc':
                return strcmp(get_the_title($b_id), get_the_title($a_id));
        }
        return 0;
    });

    return $log;
}

function ird_export_csv() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized user');
    }

    $log = get_option('ird_reupload_log', []);
    if (empty($log)) {
        wp_die('No data to export.');
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="image_reupload_log.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['File Name', 'Status', 'Alt Text', 'Title', 'Caption', 'Description', 'Upload Date']);

    foreach ($log as $entry) {
        $file_name = $entry['image'];
        $status = $entry['status'];

        $attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => [
                ['key' => '_wp_attached_file', 'value' => $file_name, 'compare' => 'LIKE'],
            ],
        ]);
        $image_id = $attachments ? $attachments[0]->ID : 0;

        $alt     = $image_id ? get_post_meta($image_id, '_wp_attachment_image_alt', true) : '';
        $title   = $image_id ? get_the_title($image_id) : '';
        $caption = $image_id ? wp_get_attachment_caption($image_id) : '';
        $desc    = $image_id ? get_post_field('post_content', $image_id) : '';
        $upload_date = $image_id ? get_the_date('Y-m-d H:i:s', $image_id) : '';

        fputcsv($output, [$file_name, $status, $alt, $title, $caption, $desc, $upload_date]);
    }
    fclose($output);
    exit;
}

function ird_process_images($delete_duplicates = false) {
    $args = [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'post_status'    => 'inherit',
    ];

    $images = get_posts($args);

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $log = [];

    foreach ($images as $image) {
        $url = wp_get_attachment_url($image->ID);
        $file_name = basename($url);
        $tmp = download_url($url);

        if (is_wp_error($tmp)) {
            $log[] = ['image' => $file_name, 'status' => 'Download Error'];
            continue;
        }

        $file_array = [
            'name'     => $file_name,
            'tmp_name' => $tmp
        ];

        $new_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($new_id)) {
            @unlink($tmp);
            $log[] = ['image' => $file_name, 'status' => 'Upload Error'];
            continue;
        }

        // Copy metadata from original image
        $alt_text = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
        if (!empty($alt_text)) {
            update_post_meta($new_id, '_wp_attachment_image_alt', $alt_text);
        }

        $new_attachment = [
            'ID'           => $new_id,
            'post_title'   => get_the_title($image->ID),
            'post_excerpt' => wp_get_attachment_caption($image->ID), // Caption
            'post_content' => $image->post_content, // Description
        ];
        wp_update_post($new_attachment);

        // Log & optionally delete duplicate
        if ($delete_duplicates) {
            wp_delete_attachment($image->ID, true);
            $log[] = ['image' => $file_name, 'status' => 'Re-uploaded & Deleted Duplicate'];
        } else {
            $log[] = ['image' => $file_name, 'status' => 'Re-uploaded'];
        }
    }

    update_option('ird_reupload_log', $log);

    echo '<div class="updated notice"><p>Process completed. Re-upload log:</p></div>';
    ird_render_grid($log);
}

function ird_log_page() {
    $log = get_option('ird_reupload_log', []);
    ?>
    <div class="wrap">
        <h1>Re-Upload Log</h1>

        <form method="get" style="margin-bottom: 20px;">
            <h2>Filter & Sort Images</h2>

            <label>Status:
                <select name="ird_filter_status">
                    <option value="">-- All --</option>
                    <option value="Re-uploaded" <?php selected($_GET['ird_filter_status'] ?? '', 'Re-uploaded'); ?>>Re-uploaded</option>
                    <option value="Re-uploaded & Deleted Duplicate" <?php selected($_GET['ird_filter_status'] ?? '', 'Re-uploaded & Deleted Duplicate'); ?>>Re-uploaded & Deleted Duplicate</option>
                    <option value="Upload Error" <?php selected($_GET['ird_filter_status'] ?? '', 'Upload Error'); ?>>Upload Error</option>
                    <option value="Download Error" <?php selected($_GET['ird_filter_status'] ?? '', 'Download Error'); ?>>Download Error</option>
                </select>
            </label>

            <label style="margin-left: 20px;">
                Alt Text:
                <select name="ird_filter_alt">
                    <option value="">-- All --</option>
                    <option value="has" <?php selected($_GET['ird_filter_alt'] ?? '', 'has'); ?>>Has Alt Text</option>
                    <option value="missing" <?php selected($_GET['ird_filter_alt'] ?? '', 'missing'); ?>>Missing Alt Text</option>
                </select>
            </label>

            <label style="margin-left: 20px;">
                Sort by:
                <select name="ird_sort_by">
                    <option value="date_desc" <?php selected($_GET['ird_sort_by'] ?? '', 'date_desc'); ?>>Upload Date (Newest)</option>
                    <option value="date_asc" <?php selected($_GET['ird_sort_by'] ?? '', 'date_asc'); ?>>Upload Date (Oldest)</option>
                    <option value="title_asc" <?php selected($_GET['ird_sort_by'] ?? '', 'title_asc'); ?>>Title (A-Z)</option>
                    <option value="title_desc" <?php selected($_GET['ird_sort_by'] ?? '', 'title_desc'); ?>>Title (Z-A)</option>
                </select>
            </label>

            <input type="submit" class="button button-primary" value="Apply Filters" />
            <a href="<?php echo esc_url(remove_query_arg(['ird_filter_status','ird_filter_alt','ird_sort_by'])); ?>" class="button">Reset</a>
        </form>

        <?php
        $filtered_log = ird_apply_filters_and_sort($log);
        ird_render_grid($filtered_log);
        ?>
    </div>
    <?php
}

function ird_author_page() {
    include plugin_dir_path(__FILE__) . 'author-page-content.php';
}

function ird_demo_grid_preview() {
    $demo = [
        ['image' => 'demo1.jpg', 'status' => 'Re-uploaded'],
        ['image' => 'demo2.jpg', 'status' => 'Re-uploaded & Deleted Duplicate'],
        ['image' => 'demo3.jpg', 'status' => 'Upload Error'],
        ['image' => 'demo4.jpg', 'status' => 'Download Error'],
    ];
    echo '<div class="updated notice"><p>Demo preview loaded below:</p></div>';
    ird_render_grid($demo);
}

function ird_render_grid($log) {
    ?>
    <style>
        .ird-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .ird-item {
            text-align: center;
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            font-size: 13px;
        }
        .ird-item img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto 10px;
            border-radius: 4px;
        }
        .ird-status {
            font-weight: bold;
            color: #0073aa;
            margin-bottom: 10px;
        }
        .ird-meta {
            text-align: left;
            font-size: 12px;
            max-height: 100px;
            overflow: auto;
        }
        .ird-meta strong {
            display: inline-block;
            width: 70px;
        }
    </style>
    <div class="ird-grid">
        <?php foreach ($log as $entry) :
            $file_name = esc_html($entry['image']);
            $status = esc_html($entry['status']);

            $image_id = 0;
            $attachments = get_posts([
                'post_type'      => 'attachment',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key'     => '_wp_attached_file',
                        'value'   => $file_name,
                        'compare' => 'LIKE'
                    ]
                ]
            ]);

            if (!empty($attachments)) {
                $image_id = $attachments[0]->ID;
            }

            $thumb = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
            $preview_url = $thumb ? esc_url($thumb) : 'https://via.placeholder.com/150?text=' . urlencode($file_name);

            $alt     = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $title   = get_the_title($image_id);
            $caption = wp_get_attachment_caption($image_id);
            $desc    = get_post_field('post_content', $image_id);
            ?>
            <div class="ird-item">
                <img src="<?php echo $preview_url; ?>" alt="<?php echo esc_attr($alt ?: $file_name); ?>">
                <div class="ird-status"><?php echo $status; ?></div>
                <div class="ird-meta">
                    <div><strong>Alt:</strong> <?php echo esc_html($alt); ?></div>
                    <div><strong>Title:</strong> <?php echo esc_html($title); ?></div>
                    <div><strong>Caption:</strong> <?php echo esc_html($caption); ?></div>
                    <div><strong>Desc:</strong> <?php echo esc_html($desc); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
?>

<?php
/**
 * Plugin Name: Image Re-Downloader
 * Description: Downloads all images from the media library and re-uploads them as new media, with grid layout and duplicate delete option.
 * Version: 1.6
 * Author: Your Name
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
        <form method="post" style="margin-top:10px;">
            <?php submit_button('Preview Demo Layout', 'secondary', 'ird_demo_preview'); ?>
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
        }
    }
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
        <?php ird_render_grid($log); ?>
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
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .ird-item {
            text-align: center;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .ird-item img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto 10px;
        }
        .ird-status {
            font-size: 13px;
            color: #666;
        }
    </style>
    <div class="ird-grid">
        <?php foreach ($log as $entry) :
            $file_name = esc_html($entry['image']);

            $image_id = 0;
            $attachments = get_posts([
                'post_type'      => 'attachment',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_wp_attached_file',
                        'value' => $file_name,
                        'compare' => 'LIKE'
                    ]
                ]
            ]);
            if (!empty($attachments)) {
                $image_id = $attachments[0]->ID;
            }

            $thumb = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
            $preview_url = $thumb ? esc_url($thumb) : 'https://via.placeholder.com/150?text=' . urlencode($file_name);
            ?>
            <div class="ird-item">
                <img src="<?php echo $preview_url; ?>" alt="<?php echo $file_name; ?>">
                <div class="ird-status"><?php echo $file_name; ?><br><strong><?php echo esc_html($entry['status']); ?></strong></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
?>

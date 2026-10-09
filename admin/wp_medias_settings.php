<?php

// 1. Disable 1536x1536 and 2048x2048 image sizes
function wp_media_renderer_remove_large_image_sizes() {
    remove_image_size('1536x1536');
    remove_image_size('2048x2048');
}
add_action('init', 'wp_media_renderer_remove_large_image_sizes');

// 2. Big image size threshold (scaled) set to 2560px
add_filter('big_image_size_threshold', function() {
    return 2560;
});

// 3. On-the-fly generation mode: no intermediate sizes pre-generated upon upload
function wp_media_renderer_filter_image_sizes($sizes, $image_meta = []) {
    // No intermediate size is generated on upload.
    // Only the scaled version (in WebP) will be created if the image exceeds 2560px.
    // All required sizes will be generated dynamically on the fly via render_image().
    return [];
}
add_filter('intermediate_image_sizes_advanced', 'wp_media_renderer_filter_image_sizes', 999, 2);

// 4. Generic helper to convert an image file to WebP
if (!function_exists('go_convert_to_webp')) {
    function go_convert_to_webp(string $file, int $quality = 85): ?string {
        if (!file_exists($file)) {
            return null;
        }

        $info = pathinfo($file);
        $ext  = strtolower($info['extension'] ?? '');

        // Already in WebP format
        if ($ext === 'webp') {
            return $file;
        }

        if (!function_exists('wp_get_image_editor')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return null;
        }

        $editor->set_quality($quality);

        $dest = $info['dirname'] . '/' . $info['filename'] . '.webp';

        // If destination file already exists and differs from source
        if ($dest !== $file && file_exists($dest)) {
            @unlink($dest);
        }

        $res = $editor->save($dest, 'image/webp');
        if (is_wp_error($res)) {
            return null;
        }

        $saved_path = is_array($res) && !empty($res['path']) ? $res['path'] : $dest;

        // Delete non-webp source file once conversion succeeded
        if ($saved_path !== $file && file_exists($file)) {
            @unlink($file);
        }

        return $saved_path;
    }
}

// 5. Helper to get WordPress upload relative path
function wp_media_renderer_get_relative_path(string $path): string {
    if (function_exists('_wp_relative_upload_path')) {
        return _wp_relative_upload_path($path);
    }
    $upload_dir = wp_upload_dir();
    $basedir    = trailingslashit($upload_dir['basedir']);
    return str_replace($basedir, '', $path);
}

// Load on-the-fly image resizing and generation engine
require_once __DIR__ . '/image_resizer.php';

// 6. Hook executed upon media upload in WordPress
if (!function_exists('wp_media_renderer_convert_to_webp')) {
    function wp_media_renderer_convert_to_webp($metadata, $attachment_id) {
        if (empty($metadata) || !is_array($metadata)) {
            return $metadata;
        }

        $mime    = get_post_mime_type($attachment_id);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];

        if (!in_array($mime, $allowed, true)) {
            return $metadata;
        }

        $attached_file = get_attached_file($attachment_id);
        if (!$attached_file || !file_exists($attached_file)) {
            return $metadata;
        }

        $dir     = trailingslashit(pathinfo($attached_file, PATHINFO_DIRNAME));
        $quality = 85;

        // 1) Delete non-scaled uploaded original if scaled version was created
        $orig_to_delete = null;
        if (!empty($metadata['original_image'])) {
            $orig_to_delete = $dir . wp_basename($metadata['original_image']);
        } elseif (preg_match('/-scaled\.(jpe?g|png|webp)$/i', $attached_file)) {
            $guess = preg_replace('/-scaled\.(jpe?g|png|webp)$/i', '.$1', $attached_file);
            if (file_exists($guess) && $guess !== $attached_file) {
                $orig_to_delete = $guess;
            }
        }

        if ($orig_to_delete && file_exists($orig_to_delete) && $orig_to_delete !== $attached_file) {
            @unlink($orig_to_delete);
            unset($metadata['original_image']);
        }

        // 2) Convert any residual generated sizes to WebP
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $key => &$size) {
                if (empty($size['file'])) {
                    continue;
                }
                $size_path = $dir . $size['file'];
                if (strtolower(pathinfo($size_path, PATHINFO_EXTENSION)) === 'webp') {
                    continue;
                }
                if (file_exists($size_path)) {
                    $new_size = go_convert_to_webp($size_path, $quality);
                    if ($new_size) {
                        $size['file']      = wp_basename($new_size);
                        $size['mime-type'] = 'image/webp';
                    }
                }
            }
            unset($size);
        }

        // 3) Convert main image (scaled or original) to WebP
        if (strtolower(pathinfo($attached_file, PATHINFO_EXTENSION)) !== 'webp') {
            $new_main = go_convert_to_webp($attached_file, $quality);
            if ($new_main) {
                $metadata['file'] = wp_media_renderer_get_relative_path($new_main);
                update_attached_file($attachment_id, $new_main);
            }
        }

        // 4) Update MIME type in database
        wp_update_post([
            'ID'             => $attachment_id,
            'post_mime_type' => 'image/webp',
        ]);

        return $metadata;
    }

    add_filter('wp_generate_attachment_metadata', 'wp_media_renderer_convert_to_webp', 10, 2);
}

// 7. Media library sanitation and migration to on-the-fly generation
function wp_media_renderer_sanitize_single_image(int $id): array {
    $stats = [
        'attachment_id' => $id,
        'deleted'       => 0,
        'converted'     => 0,
        'freed'         => 0,
    ];

    $mime = get_post_mime_type($id);
    if ($mime === 'image/svg+xml') {
        return $stats;
    }

    $attached_file = get_attached_file($id);
    if (!$attached_file || !file_exists($attached_file)) {
        return $stats;
    }

    if (!function_exists('wp_get_image_editor')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $dir  = trailingslashit(pathinfo($attached_file, PATHINFO_DIRNAME));
    $meta = wp_get_attachment_metadata($id);
    if (empty($meta) || !is_array($meta)) {
        $meta = [];
    }

    $quality      = 85;
    $meta_changed = false;

    // 1. Delete unscaled original file if scaled version exists
    $orig_file = null;
    if (!empty($meta['original_image'])) {
        $orig_file = $dir . wp_basename($meta['original_image']);
    } elseif (preg_match('/-scaled\.(jpe?g|png|webp)$/i', $attached_file)) {
        $guess = preg_replace('/-scaled\.(jpe?g|png|webp)$/i', '.$1', $attached_file);
        if (file_exists($guess) && $guess !== $attached_file) {
            $orig_file = $guess;
        }
    }

    if ($orig_file && file_exists($orig_file) && $orig_file !== $attached_file) {
        $orig_size = filesize($orig_file);
        if (@unlink($orig_file)) {
            $stats['freed'] += $orig_size;
            $stats['deleted']++;
        }
        if (isset($meta['original_image'])) {
            unset($meta['original_image']);
            $meta_changed = true;
        }
    }

    // 2. Delete ALL existing intermediate sizes (100% on-the-fly migration)
    if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
        foreach ($meta['sizes'] as $size_key => $size_info) {
            if (empty($size_info['file'])) {
                continue;
            }
            $size_path = $dir . $size_info['file'];

            if (file_exists($size_path) && $size_path !== $attached_file) {
                $s_size = filesize($size_path);
                if (@unlink($size_path)) {
                    $stats['freed'] += $s_size;
                    $stats['deleted']++;
                }
            }

            // Also delete associated WebP or JPG/PNG versions
            $webp_extra = preg_replace('/\.(jpe?g|png)$/i', '.webp', $size_path);
            if ($webp_extra !== $size_path && file_exists($webp_extra) && $webp_extra !== $attached_file) {
                $w_size = filesize($webp_extra);
                if (@unlink($webp_extra)) {
                    $stats['freed'] += $w_size;
                    $stats['deleted']++;
                }
            }
        }

        // Reset pre-generated sizes metadata: will be created strictly on-the-fly when needed
        $meta['sizes'] = [];
        $meta_changed = true;
    }

    // 3. Convert main image (scaled or normal) to WebP if needed
    $main_ext = strtolower(pathinfo($attached_file, PATHINFO_EXTENSION));
    if ($main_ext !== 'webp') {
        $old_main_bytes = filesize($attached_file);
        $new_main       = go_convert_to_webp($attached_file, $quality);
        if ($new_main && file_exists($new_main)) {
            $new_main_bytes = filesize($new_main);
            if ($old_main_bytes > $new_main_bytes) {
                $stats['freed'] += ($old_main_bytes - $new_main_bytes);
            }
            $stats['converted']++;
            $meta['file'] = wp_media_renderer_get_relative_path($new_main);
            update_attached_file($id, $new_main);
            $meta_changed = true;
        }
    }

    // 4. Save metadata and update MIME type
    if ($meta_changed) {
        wp_update_attachment_metadata($id, $meta);
    }
    if ($mime !== 'image/webp') {
        wp_update_post([
            'ID'             => $id,
            'post_mime_type' => 'image/webp',
        ]);
    }

    return $stats;
}

// 8. AJAX: Retrieve list of image attachment IDs to clean
add_action('wp_ajax_wp_media_renderer_get_clean_ids', function() {
    check_ajax_referer('wp_media_renderer_clean_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
    }

    $ids = get_posts([
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    wp_send_json_success([
        'ids'   => array_values(array_map('intval', $ids)),
        'total' => count($ids),
    ]);
});

// 9. AJAX: Process batch of images
add_action('wp_ajax_wp_media_renderer_clean_batch', function() {
    check_ajax_referer('wp_media_renderer_clean_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
    }

    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    if (empty($ids)) {
        wp_send_json_success(['deleted' => 0, 'converted' => 0, 'freed' => 0]);
    }

    $total_deleted   = 0;
    $total_converted = 0;
    $total_freed     = 0;

    foreach ($ids as $id) {
        $res = wp_media_renderer_sanitize_single_image($id);
        $total_deleted   += $res['deleted'];
        $total_converted += $res['converted'];
        $total_freed     += $res['freed'];
    }

    wp_send_json_success([
        'deleted'   => $total_deleted,
        'converted' => $total_converted,
        'freed'     => $total_freed,
    ]);
});

// 10. Direct CLI or URL fallback (backwards compatibility)
add_action('init', function() {
    if (!isset($_GET['clean_original_images']) || !current_user_can('manage_options')) {
        return;
    }

    $attachments = get_posts([
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    $total_deleted   = 0;
    $total_converted = 0;
    $total_freed     = 0;

    foreach ($attachments as $id) {
        $res = wp_media_renderer_sanitize_single_image($id);
        $total_deleted   += $res['deleted'];
        $total_converted += $res['converted'];
        $total_freed     += $res['freed'];
    }

    wp_die(sprintf(
        'Media cleanup finished: %d images processed, %d files converted to WebP, %d original files deleted (%s freed).',
        count($attachments),
        $total_converted,
        $total_deleted,
        size_format($total_freed)
    ));
});
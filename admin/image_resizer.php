<?php
/**
 * GO - Media Renderer
 * On-the-fly WebP image resizing and generation engine.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes image data (attachment ID, ACF array, or URL).
 *
 * @param mixed $attachment
 * @return array ['id' => int, 'url' => string, 'width' => int, 'height' => int, 'alt' => string, 'mime_type' => string]
 */
function go_normalize_attachment_data($attachment): array {
    $data = [
        'id'        => 0,
        'url'       => '',
        'width'     => 0,
        'height'    => 0,
        'alt'       => '',
        'mime_type' => '',
    ];

    if (empty($attachment)) {
        return $data;
    }

    if (is_numeric($attachment)) {
        $attachment_id     = (int) $attachment;
        $data['id']        = $attachment_id;
        $data['url']       = function_exists('wp_get_attachment_url') ? (wp_get_attachment_url($attachment_id) ?: '') : '';
        $data['mime_type'] = function_exists('get_post_mime_type') ? (get_post_mime_type($attachment_id) ?: '') : '';
        $data['alt']       = function_exists('get_post_meta') ? (get_post_meta($attachment_id, '_wp_attachment_image_alt', true) ?: '') : '';

        if (function_exists('wp_get_attachment_metadata')) {
            $meta = wp_get_attachment_metadata($attachment_id);
            if (!empty($meta) && is_array($meta)) {
                $data['width']  = (int) ($meta['width'] ?? 0);
                $data['height'] = (int) ($meta['height'] ?? 0);
            }
        }
        return $data;
    }

    if (is_array($attachment)) {
        $data['id']        = (int) ($attachment['ID'] ?? $attachment['id'] ?? 0);
        $data['url']       = $attachment['url'] ?? '';
        $data['width']     = (int) ($attachment['width'] ?? 0);
        $data['height']    = (int) ($attachment['height'] ?? 0);
        $data['alt']       = $attachment['alt'] ?? '';
        $data['mime_type'] = $attachment['mime_type'] ?? '';

        if ($data['id'] === 0 && !empty($data['url']) && function_exists('attachment_url_to_postid')) {
            $found_id = attachment_url_to_postid($data['url']);
            if ($found_id) {
                $data['id'] = $found_id;
            }
        }

        if ($data['id'] > 0) {
            if (empty($data['mime_type']) && function_exists('get_post_mime_type')) {
                $data['mime_type'] = get_post_mime_type($data['id']) ?: '';
            }
            if (empty($data['alt']) && function_exists('get_post_meta')) {
                $data['alt'] = get_post_meta($data['id'], '_wp_attachment_image_alt', true) ?: '';
            }
            if (($data['width'] === 0 || $data['height'] === 0) && function_exists('wp_get_attachment_metadata')) {
                $meta = wp_get_attachment_metadata($data['id']);
                if (!empty($meta) && is_array($meta)) {
                    $data['width']  = (int) ($meta['width'] ?? $data['width']);
                    $data['height'] = (int) ($meta['height'] ?? $data['height']);
                }
            }
        }

        return $data;
    }

    if (is_string($attachment) && filter_var($attachment, FILTER_VALIDATE_URL)) {
        $data['url'] = $attachment;
        if (function_exists('attachment_url_to_postid')) {
            $found_id = attachment_url_to_postid($attachment);
            if ($found_id) {
                return go_normalize_attachment_data($found_id);
            }
        }
    }

    return $data;
}

/**
 * Resolves target dimensions based on requested size format or dimensions.
 *
 * @param mixed $size Size name ('thumbnail', 'medium', 'large', 'small'), array [w, h], or int width
 * @param int|null $height Optional height
 * @param bool|array $crop Crop behavior
 * @return array ['width' => int, 'height' => int, 'crop' => bool|array, 'name' => string|null]
 */
function go_resolve_target_dimensions($size, $height = null, $crop = true): array {
    $result = [
        'width'  => 0,
        'height' => 0,
        'crop'   => $crop,
        'name'   => null,
    ];

    if (is_string($size)) {
        $result['name'] = $size;
        $size_lower = strtolower($size);

        if ($size_lower === 'thumbnail') {
            $result['width']  = (int) get_option('thumbnail_size_w', 150);
            $result['height'] = (int) get_option('thumbnail_size_h', 150);
            $result['crop']   = (bool) get_option('thumbnail_crop', true);
            return $result;
        }

        if ($size_lower === 'medium') {
            $result['width']  = (int) get_option('medium_size_w', 300);
            $result['height'] = (int) get_option('medium_size_h', 300);
            $result['crop']   = false;
            return $result;
        }

        if ($size_lower === 'large') {
            $result['width']  = (int) get_option('large_size_w', 1024);
            $result['height'] = (int) get_option('large_size_h', 1024);
            $result['crop']   = false;
            return $result;
        }

        if ($size_lower === 'small') {
            $registered = wp_get_registered_image_subsizes();
            if (isset($registered['small'])) {
                $result['width']  = (int) ($registered['small']['width'] ?? 500);
                $result['height'] = (int) ($registered['small']['height'] ?? 0);
                $result['crop']   = $registered['small']['crop'] ?? false;
            } else {
                $result['width']  = 500;
                $result['height'] = 0;
                $result['crop']   = false;
            }
            return $result;
        }

        // Other registered size in WP
        $all_sizes = wp_get_registered_image_subsizes();
        if (isset($all_sizes[$size])) {
            $result['width']  = (int) ($all_sizes[$size]['width'] ?? 0);
            $result['height'] = (int) ($all_sizes[$size]['height'] ?? 0);
            $result['crop']   = $all_sizes[$size]['crop'] ?? false;
            return $result;
        }

        // Fallback to thumbnail if format unknown
        $result['width']  = (int) get_option('thumbnail_size_w', 150);
        $result['height'] = (int) get_option('thumbnail_size_h', 150);
        $result['crop']   = (bool) get_option('thumbnail_crop', true);
        return $result;
    }

    if (is_array($size)) {
        $result['width']  = (int) ($size[0] ?? $size['w'] ?? $size['width'] ?? 0);
        $result['height'] = (int) ($size[1] ?? $size['h'] ?? $size['height'] ?? 0);
        if (isset($size['crop'])) {
            $result['crop'] = $size['crop'];
        } elseif (isset($size[2])) {
            $result['crop'] = (bool) $size[2];
        }
        return $result;
    }

    if (is_numeric($size)) {
        $result['width']  = (int) $size;
        $result['height'] = (int) ($height ?? 0);
        return $result;
    }

    return $result;
}

/**
 * Generates or retrieves an on-the-fly WebP resized image.
 *
 * @param mixed $attachment Attachment ID or ACF array
 * @param mixed $size Size name or dimensions [w, h] or integer width
 * @param int|null $height Optional height
 * @param bool|array $crop Crop behavior (true by default)
 * @param int $quality WebP quality (85 by default)
 * @return array|null Generated or source image information
 */
function go_resize_image_on_the_fly($attachment, $size, $height = null, $crop = true, int $quality = 85): ?array {
    $norm = go_normalize_attachment_data($attachment);

    if (empty($norm['id']) && empty($norm['url'])) {
        return null;
    }

    // SVG handling: no resizing needed
    if ($norm['mime_type'] === 'image/svg+xml' || preg_match('/\.svg$/i', $norm['url'])) {
        return [
            'url'       => $norm['url'],
            'width'     => $norm['width'] ?: 650,
            'height'    => $norm['height'] ?: 650,
            'path'      => $norm['id'] ? get_attached_file($norm['id']) : null,
            'mime_type' => 'image/svg+xml',
            'is_svg'    => true,
        ];
    }

    if (empty($norm['id'])) {
        return [
            'url'       => $norm['url'],
            'width'     => $norm['width'],
            'height'    => $norm['height'],
            'path'      => null,
            'mime_type' => $norm['mime_type'] ?: 'image/jpeg',
            'is_svg'    => false,
        ];
    }

    $source_path = function_exists('get_attached_file') ? get_attached_file($norm['id']) : null;
    if (!$source_path || !file_exists($source_path)) {
        return [
            'url'       => $norm['url'] ?: (function_exists('wp_get_attachment_url') ? wp_get_attachment_url($norm['id']) : ''),
            'width'     => $norm['width'] ?: 650,
            'height'    => $norm['height'] ?: 650,
            'path'      => null,
            'mime_type' => $norm['mime_type'] ?: 'image/jpeg',
            'is_svg'    => false,
        ];
    }

    // Convert source file to WebP if not already converted
    if (strtolower(pathinfo($source_path, PATHINFO_EXTENSION)) !== 'webp') {
        $new_webp_source = go_convert_to_webp($source_path, $quality);
        if ($new_webp_source && file_exists($new_webp_source)) {
            $source_path = $new_webp_source;
            update_attached_file($norm['id'], $new_webp_source);
            wp_update_post([
                'ID'             => $norm['id'],
                'post_mime_type' => 'image/webp',
            ]);
        }
    }

    $dir          = trailingslashit(pathinfo($source_path, PATHINFO_DIRNAME));
    $base_name    = pathinfo($source_path, PATHINFO_FILENAME);
    $upload_dir   = wp_upload_dir();
    $target_specs = go_resolve_target_dimensions($size, $height, $crop);

    $target_w = $target_specs['width'];
    $target_h = $target_specs['height'];
    $target_c = $target_specs['crop'];

    // Retrieve source dimensions
    $meta   = wp_get_attachment_metadata($norm['id']);
    $orig_w = !empty($meta['width']) ? (int) $meta['width'] : 0;
    $orig_h = !empty($meta['height']) ? (int) $meta['height'] : 0;

    if ($orig_w === 0 || $orig_h === 0) {
        $info = @getimagesize($source_path);
        if ($info) {
            $orig_w = (int) $info[0];
            $orig_h = (int) $info[1];
        }
    }

    // If no valid target dimensions requested, return source image
    if ($target_w <= 0 && $target_h <= 0) {
        $source_url = wp_get_attachment_url($norm['id']);
        return [
            'url'       => $source_url,
            'width'     => $orig_w,
            'height'    => $orig_h,
            'path'      => $source_path,
            'mime_type' => 'image/webp',
            'is_svg'    => false,
        ];
    }

    // Calculate exact output dimensions using WordPress core
    $dims = image_resize_dimensions($orig_w, $orig_h, $target_w, $target_h, $target_c);

    // If source image is smaller and no crop is required
    if (!$dims) {
        if ($target_c && $target_w > 0 && $target_h > 0) {
            // Forced crop even if smaller
            $final_w = min($orig_w, $target_w);
            $final_h = min($orig_h, $target_h);
        } else {
            $source_url = wp_get_attachment_url($norm['id']);
            return [
                'url'       => $source_url,
                'width'     => $orig_w,
                'height'    => $orig_h,
                'path'      => $source_path,
                'mime_type' => 'image/webp',
                'is_svg'    => false,
            ];
        }
    } else {
        $final_w = (int) $dims[4];
        $final_h = (int) $dims[5];
    }

    // Define deterministic destination filename and path
    $dest_filename = sprintf('%s-%dx%d.webp', $base_name, $final_w, $final_h);
    $dest_path     = $dir . $dest_filename;

    // Calculate public URL
    $rel_path = wp_media_renderer_get_relative_path($dest_path);
    $dest_url = trailingslashit($upload_dir['baseurl']) . ltrim($rel_path, '/');

    // 1. Disk cache check: if file already exists, return immediately (0ms CPU)
    if (file_exists($dest_path) && filesize($dest_path) > 0) {
        return [
            'url'       => $dest_url,
            'width'     => $final_w,
            'height'    => $final_h,
            'path'      => $dest_path,
            'mime_type' => 'image/webp',
            'is_svg'    => false,
        ];
    }

    // 2. Safe on-the-fly generation (atomic writes to prevent race conditions)
    if (!function_exists('wp_get_image_editor')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $editor = wp_get_image_editor($source_path);
    if (is_wp_error($editor)) {
        // Fallback to source image if editor loading fails
        return [
            'url'       => wp_get_attachment_url($norm['id']),
            'width'     => $orig_w,
            'height'    => $orig_h,
            'path'      => $source_path,
            'mime_type' => 'image/webp',
            'is_svg'    => false,
        ];
    }

    $editor->set_quality($quality);
    $resize_res = $editor->resize($target_w, $target_h, $target_c);
    if (is_wp_error($resize_res)) {
        return [
            'url'       => wp_get_attachment_url($norm['id']),
            'width'     => $orig_w,
            'height'    => $orig_h,
            'path'      => $source_path,
            'mime_type' => 'image/webp',
            'is_svg'    => false,
        ];
    }

    // Atomic write to a temporary file
    $tmp_file = $dest_path . '.' . uniqid('tmp_', true) . '.webp';
    $save_res = $editor->save($tmp_file, 'image/webp');

    if (!is_wp_error($save_res) && file_exists($tmp_file)) {
        @rename($tmp_file, $dest_path);
        if (file_exists($tmp_file)) {
            @unlink($tmp_file);
        }
    }

    // Save into WordPress metadata for standard named formats
    if (!empty($target_specs['name']) && is_array($meta)) {
        $format_name = $target_specs['name'];
        if (empty($meta['sizes'][$format_name])) {
            if (empty($meta['sizes'])) {
                $meta['sizes'] = [];
            }
            $meta['sizes'][$format_name] = [
                'file'      => wp_basename($dest_path),
                'width'     => $final_w,
                'height'    => $final_h,
                'mime-type' => 'image/webp',
            ];
            wp_update_attachment_metadata($norm['id'], $meta);
        }
    }

    return [
        'url'       => $dest_url,
        'width'     => $final_w,
        'height'    => $final_h,
        'path'      => $dest_path,
        'mime_type' => 'image/webp',
        'is_svg'    => false,
    ];
}

/**
 * Native WordPress image_downsize filter:
 * Allows standard WordPress functions (wp_get_attachment_image, wp_get_attachment_image_src, etc.)
 * to automatically benefit from on-the-fly WebP generation.
 */
function wp_media_renderer_image_downsize($downsize, $attachment_id, $size) {
    if ($downsize !== false) {
        return $downsize;
    }

    // Ignore SVGs
    $mime = get_post_mime_type($attachment_id);
    if ($mime === 'image/svg+xml') {
        return false;
    }

    // If original 'full' size requested, let WP handle it
    if ($size === 'full') {
        return false;
    }

    $resized = go_resize_image_on_the_fly($attachment_id, $size);
    if ($resized && !empty($resized['url'])) {
        return [
            $resized['url'],
            (int) $resized['width'],
            (int) $resized['height'],
            true, // is_intermediate
        ];
    }

    return false;
}
add_filter('image_downsize', 'wp_media_renderer_image_downsize', 10, 3);


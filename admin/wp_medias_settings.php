<?php 

// Remove unused image sizes
if (!function_exists('remove_unwanted_image_sizes')) {
    function remove_unwanted_image_sizes($sizes) {
        unset($sizes['medium_large']);
        unset($sizes['1536x1536']);
        unset($sizes['2048x2048']);
        unset($sizes['full']);
        
        return $sizes;
    }
}
add_filter('intermediate_image_sizes_advanced', 'remove_unwanted_image_sizes');


// Disactivate 1536x1536 & 2048x2048 completely - Image sizes are still visible
// Since WP 5.3 these are automatically regenerated to handle big images such as retina
function remove_large_image_sizes() {
	remove_image_size( '1536x1536' );  // 2 x Medium Large (1536 x 1536)
	remove_image_size( '2048x2048' );  // 2 x Large (2048 x 2048)
}
add_action( 'init', 'remove_large_image_sizes' );


// Generic helper for webp conversion
if (!function_exists('go_convert_to_webp')) {

    function go_convert_to_webp(string $file, int $quality = 85): ?string {

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return null;
        }
        $editor->set_quality($quality);
        // on redimensionne large originals pour éviter d’exploser la taille
        $editor->resize(2560, 0);
        $info = pathinfo($file);
        $dest = "{$info['dirname']}/{$info['filename']}.webp";

        $res = $editor->save($dest, 'image/webp');
        if (is_wp_error($res)) {
            return null;
        }

        @unlink($file);
        return $dest;

    }
}


// Create Webp Format for each uploaded images
if (!function_exists('go_convert_all_sizes_to_webp')) {
    function go_convert_all_sizes_to_webp($metadata, $attachment_id) {
        
        // Only convert images
        $mime    = get_post_mime_type($attachment_id);
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];

        if (!in_array($mime, $allowed, true) || !function_exists('wp_get_image_editor')) {
            return $metadata;
        }
        
        $quality = 85;
        $upload_dir = wp_upload_dir();
        $basedir    = trailingslashit($upload_dir['basedir']);


        // WP Image editor API
        if (!function_exists('wp_get_image_editor')) {
            return $metadata;
        }

        // Get upload dir, add a / with trailingslashit id not allready there

        // 1) Convert All images sizes in webp
        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $key => &$size) {
                $path = $basedir . $size['file'];
                // skip déjà webp
                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'webp') {
                    continue;
                }
                if ($new = go_convert_to_webp($path, $quality)) {
                    $rel            = str_replace($basedir, '', $new);
                    $size['file']   = $rel;
                    $size['mime-type'] = 'image/webp';
                }
            }
            unset($size);
        }


        // 2) Convert original image
        // => $metadata['file'] has “Full” (or “-scaled” if WP > 5.3)
        if (!empty($metadata['file'])) {
            $orig = get_attached_file($attachment_id);
            if (strtolower(pathinfo($orig, PATHINFO_EXTENSION)) !== 'webp') {
                if ($new = go_convert_to_webp($orig, $quality)) {
                    $rel = str_replace($basedir, '', $new);
                    $metadata['file'] = $rel;
                    if (isset($metadata['sizes']['scaled'])) {
                        $metadata['sizes']['scaled']['file']      = $rel;
                        $metadata['sizes']['scaled']['mime-type'] = 'image/webp';
                    }
                    update_attached_file($attachment_id, $new);
                }
            }
        }

        // 3 Update mim type
        wp_update_post([
            'ID'             => $attachment_id,
            'post_mime_type' => 'image/webp',
        ]);

        return $metadata;
    }

    add_filter('wp_generate_attachment_metadata', 'go_convert_all_sizes_to_webp', 10, 2);

}
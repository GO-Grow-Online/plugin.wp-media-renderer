<?php

// Add configuration page
add_action('admin_menu', function () {
    add_submenu_page(
        'plugins.php',
        'Plugin Licence',
        'Plugin Licence',
        'manage_options',
        'wp-media-renderer-license',
        'wp_media_renderer_license_page'
    );
});

// Create interface & field for key submission
function wp_media_renderer_license_page() {
    $license_key = get_option('wp_media_renderer_license_key', '');
    $status = get_option('wp_media_renderer_license_status', 'inactive');
    $message = get_option('wp_media_renderer_license_message', '');

    ?>
    <div class="wrap">
        <h1>Licence - Image Renderer</h1>

        <?php if (!empty($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Setting updated.</p>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($message)) : ?>
            <div class="notice <?php echo ($status === 'active') ? 'notice-success' : 'notice-error'; ?>">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wp_media_renderer_license_nonce', 'wp_media_renderer_license_nonce'); ?>
            <input type="hidden" name="action" value="wp_media_renderer_save_license">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wp_media_renderer_license_key">Licence key</label></th>
                    <td>
                        <input type="text" name="wp_media_renderer_license_key" id="wp_media_renderer_license_key"
                               value="<?php echo esc_attr($license_key); ?>" class="regular-text">
                    </td>
                </tr>
            </table>

            <?php submit_button('Register lincence key'); ?>
        </form>

    </div>
    <?php
}

// Form submission
add_action('admin_post_wp_media_renderer_save_license', function () {
    if (!isset($_POST['wp_media_renderer_license_nonce']) || !wp_verify_nonce($_POST['wp_media_renderer_license_nonce'], 'wp_media_renderer_license_nonce')) {
        wp_die('Security error.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Missing permissions.');
    }

    $license_key = isset($_POST['wp_media_renderer_license_key']) ? sanitize_text_field($_POST['wp_media_renderer_license_key']) : '';

    // Stock the key
    update_option('wp_media_renderer_license_key', $license_key);

    // Key check
    wp_media_renderer_validate_license_key($license_key);

    // Reload page with results
    wp_redirect(admin_url('plugins.php?page=wp-media-renderer-license&updated=true'));
    exit;
});

// Call endpoint for licence verification
function wp_media_renderer_validate_license_key($license_key) {
    $domain = home_url();
    $endpoint_url = "https://grow-online.be/licences/wp-media-renderer/licence-check.php";

    // Delete old stored values to avoid cache issues
    delete_option('wp_media_renderer_license_status');
    delete_option('wp_media_renderer_license_message');

    $response = wp_remote_post($endpoint_url, [
        'timeout' => 15,
        'body' => [
            'license_key' => $license_key,
            'license_key' => $license_key,
            'domain'      => $domain
        ]
    ]);

    if (is_wp_error($response)) {
        update_option('wp_media_renderer_license_status', 'inactive');
        update_option('wp_media_renderer_license_message', 'Erreur de communication avec le serveur de licence.');
        return;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($data['success']) && $data['success'] === true) {
        update_option('wp_media_renderer_license_status', 'active');
        update_option('wp_media_renderer_license_message', $data['message']);
    } else {
        update_option('wp_media_renderer_license_status', 'inactive');
        update_option('wp_media_renderer_license_message', $data['message'] ?? 'Licence invalide.');
    }
}

// Monthly verification
add_action('wp', function () {
    if (!wp_next_scheduled('wp_media_renderer_auto_license_check')) {
        wp_schedule_event(time(), 'monthly', 'wp_media_renderer_auto_license_check');
    }
});

add_action('wp_media_renderer_auto_license_check', 'wp_media_renderer_check_license_status');

function wp_media_renderer_check_license_status() {
    $license_key = get_option('wp_media_renderer_license_key', '');
    $domain = home_url();
    $endpoint_url = "https://grow-online.be/licences/wp-media-renderer/licence-check.php";

    if (empty($license_key)) {
        return;
    }

    $response = wp_remote_post($endpoint_url, [
        'timeout' => 15,
        'body'    => [
            'license_key' => $license_key,
            'domain'      => $domain
        ]
    ]);

    if (is_wp_error($response)) {
        update_option('wp_media_renderer_license_status', 'inactive');
        return;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($data['success']) && $data['success'] === true) {
        update_option('wp_media_renderer_license_status', 'active');
    } else {
        update_option('wp_media_renderer_license_status', 'inactive');
    }
}
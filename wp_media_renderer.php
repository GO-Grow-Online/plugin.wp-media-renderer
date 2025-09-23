<?php
/**
 * Plugin Name: GO - Media Renderer
 * Description: Display images & videos with render_image() & render_videos(), powerfull and light functions that brings performance and accessibility to your theme. 
 * Version: 1.0.1
 * Author URI: https://grow-online.be
 * Author: Grow Online
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Load update checker
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://raw.githubusercontent.com/GO-Grow-Online/plugin.wp-media-renderer/refs/heads/main/wp-media-renderer-version.json',
    __FILE__,
    'wordpress-media-renderer'
);


// Check if ACF in with us, otherwise plugin wont work
if (!function_exists('acf_add_local_field_group')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>' . __('Plugins manquants', 'Media renderer') . '</strong> : ' . __('Rendez-vous dans "thème", "Install Plguins". Installez et/ou activez ensuite les plugins affichés.', 'Media renderer') . '</p></div>';
    });
    return;
}


// Plugin disactivation handle - mandatory to have it in main file with the use of __FILE__
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('wp_media_renderer_auto_license_check');

    // Update WP db
    update_option('wp_media_renderer_license_status', 'inactive');
    update_option('wp_media_renderer_license_message', __('Le plugin a été désactivé. La licence est mise en pause.', 'Media renderer'));

    // Update licence database
    $license_key = get_option('wp_media_renderer_license_key', '');
    $domain = home_url();

    if (!empty($license_key)) {
        wp_remote_post('https://grow-online.be/licences/go-image-renderer-licence-deactivate.php', [
            'timeout' => 15,
            'body'    => [
                'license_key' => $license_key,
                'domain'      => $domain,
            ]
        ]);
    }
});


// Required
require_once __DIR__ . '/admin/wp_medias_settings.php';
require_once __DIR__ . '/admin/acf_fields_settings.php';
require_once __DIR__ . '/admin/licence.php';

$license_status = get_option('wp_media_renderer_license_status', 'inactive');

// If licence is active, load functions
if ($license_status === 'active') {
    require_once __DIR__ . '/admin/render_functions.php';
} else {
    function render_image( $args = [] ) {
        if ( empty( $args['img'] ) || ! is_array( $args['img'] ) || ! isset( $args['img']['ID'] ) ) {
            if ( is_user_logged_in() ) {
                echo '';
            }
            return;
        }

        $image_id = $args['img']['ID'];
        $image_url = wp_get_attachment_image_url( $image_id, 'full' );
        
        if ( $image_url ) {
            $alt_text = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
            printf( '<div class="img-wrap"><img src="%s" alt="%s" ></div>', esc_url( $image_url ), esc_attr( $alt_text ));
            if ( is_user_logged_in() ) {
                echo "<span class='admin-msg'>" . __("Image renderer's licence is not active. The performances of your website are impacted.", "Media renderer") . "</span>";
            }
        }
    }

    function render_video( $args = [] ) {
        if ( empty( $args['video'] ) || ! is_array( $args['video'] ) || ! isset( $args['video']['ID'] ) ) {
            if ( is_user_logged_in() ) {
                echo '<span class="admin-msg">' . __("Media renderer is not active, and the video data is invalid.", 'Media renderer') . '</span>';
            }
            return;
        }

        $video_id = $args['video']['ID'];
        $video_url = wp_get_attachment_url( $video_id );

        $thumbnail_id = get_field('thumbnail', $video_id);
        $poster_url = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'full') : '';

        if ( $video_url ) {
            if ( is_user_logged_in() ) {
                echo '<span class="admin-msg">' . __("Video renderer's licence is not active. The performances of your website are impacted.", 'Media renderer') . '</span>';
            }

            printf(
                '<div class="vid-wrap"><video controls preload="auto" poster="%s"><source src="%s" type="%s"></video></div>',
                esc_url($poster_url),
                esc_url( $video_url ),
                esc_attr( get_post_mime_type( $video_id ) )
            );
        }
    }


    function get_svg($svg) {
        return '<img src="'. $svg['url'] .'"/>'; 
        return "<span class='admin-msg'>. __('Media renderer is not active. Icons cannot be rendered.', 'Media renderer') .'</span>"; 
    }
}


add_action('wp_enqueue_scripts', 'go_media_renderer_code');

function go_media_renderer_code() {
    wp_enqueue_script( 'go-media-renderer-js',  plugins_url('assets/js/go_media_renderer.js', __FILE__));
    wp_enqueue_style( 'go-media-renderer-css', plugins_url('assets/css/go_media_renderer.css', __FILE__));
}

<?php

function render_image($args = []) {

    // If image is empty, look for placeholder in ACF options
    $raw_img = !empty($args['img']) ? $args['img'] : (function_exists('get_field') ? get_field('img_placeholder', 'options') : null);

    // Normalize image data (support int ID, ACF array, URL)
    $norm   = go_normalize_attachment_data($raw_img);
    $img_id = $norm['id'];

    // Retrieve ACF fields (with fallback to values passed in $args)
    $acf_force_portrait = ($img_id && function_exists('get_field')) ? (bool) get_field('force_portrait', $img_id) : false;
    $acf_display_legend = ($img_id && function_exists('get_field')) ? (bool) get_field('display_legend', $img_id) : false;
    $acf_seamless       = ($img_id && function_exists('get_field')) ? (bool) get_field('seamless', $img_id) : false;

    $defaults = [
        'img'            => null,
        'format'         => null,
        'sizes'          => null,
        'crop'           => true,
        'fs'             => false,
        'defer'          => true,
        'seamless'       => $acf_seamless,
        'force_portrait' => $acf_force_portrait,
        'display_legend' => $acf_display_legend,
        'figcaption'     => $acf_display_legend,
        'alt'            => null,
        'class'          => '',
        'img_class'      => '',
    ];

    $args = wp_parse_args($args, $defaults);

    $has_image     = !empty($norm['url']) || !empty($img_id);
    $is_svg        = ($norm['mime_type'] === 'image/svg+xml') || preg_match('/\.svg$/i', $norm['url']);
    $loading       = $args['defer'] ? "lazy" : "eager";
    $fetchpriority = $args['defer'] ? '' : ' fetchpriority="high"';

    // Alt text
    $alt = !empty($args['alt']) ? $args['alt'] : (!empty($norm['alt']) ? $norm['alt'] : '');

    // Caption and Schema.org metadata
    $caption     = is_array($raw_img) && !empty($raw_img['caption']) ? $raw_img['caption'] : ($img_id ? wp_get_attachment_caption($img_id) : '');
    $description = is_array($raw_img) && !empty($raw_img['description']) ? $raw_img['description'] : '';
    $title       = is_array($raw_img) && !empty($raw_img['name']) ? $raw_img['name'] : ($img_id ? get_the_title($img_id) : '');

    // Container CSS classes
    $wrap_classes = ['img-wrap'];
    if (!empty($args['force_portrait'])) $wrap_classes[] = 'img-wrap--portrait';
    if (!empty($args['seamless']))       $wrap_classes[] = 'img-wrap--seamless';
    if (!empty($args['display_legend'])) $wrap_classes[] = 'img-wrap--displayLegend';
    if (!empty($args['class']))          $wrap_classes[] = esc_attr($args['class']);

    $img_class = 'img-wrap__img' . (!empty($args['img_class']) ? ' ' . esc_attr($args['img_class']) : '');
    ?>

    <div class="<?php echo esc_attr(implode(' ', $wrap_classes)); ?>">

        <?php if ($has_image) : ?>
            <?php 
            // 1. Blurred background image for "force_portrait" mode
            if ($args['force_portrait'] && !$is_svg) {
                $bg_thumb = go_resize_image_on_the_fly($raw_img, 'thumbnail', null, true);
                $bg_url   = $bg_thumb['url'] ?? $norm['url'];
                echo '<!--googleoff: index--><img class="img-wrap__bg" loading="' . esc_attr($loading) . '"' . $fetchpriority . ' type="image/webp" src="' . esc_url($bg_url) . '" alt="' . esc_attr($alt) . '"><!--googleon: index-->';
            }
            ?>

            <?php if ($args['figcaption']) : ?>
                <figure class="img-wrap__figure" itemscope itemtype="http://schema.org/ImageObject">
            <?php endif; ?>

            <?php
            // CASE 2: Named format requested (e.g. 'small', 'medium', 'large') -> render simple <img>
            if (!empty($args['format'])) {
                if ($is_svg) {
                    $img_src = $norm['url'];
                    $w       = $norm['width'] ?: 650;
                    $h       = $norm['height'] ?: 650;
                    $mime    = 'image/svg+xml';
                } else {
                    $resized = go_resize_image_on_the_fly($raw_img, $args['format'], null, $args['crop']);
                    $img_src = $resized['url'] ?? $norm['url'];
                    $w       = $resized['width'] ?? ($norm['width'] ?: 650);
                    $h       = $resized['height'] ?? ($norm['height'] ?: 650);
                    $mime    = $resized['mime_type'] ?? 'image/webp';
                }

                printf(
                    '<img class="%s" loading="%s"%s type="%s" src="%s" alt="%s" width="%d" height="%d">',
                    esc_attr($img_class),
                    esc_attr($loading),
                    $fetchpriority,
                    esc_attr($mime),
                    esc_url($img_src),
                    esc_attr($alt),
                    (int) $w,
                    (int) $h
                );

            // CASE 3: Custom size parameters defined per screen / media query
            } elseif (!empty($args['sizes']) && is_array($args['sizes'])) {
                if ($is_svg) {
                    printf(
                        '<img class="%s" loading="%s"%s type="image/svg+xml" src="%s" alt="%s" width="%d" height="%d">',
                        esc_attr($img_class),
                        esc_attr($loading),
                        $fetchpriority,
                        esc_url($norm['url']),
                        esc_attr($alt),
                        (int) ($norm['width'] ?: 650),
                        (int) ($norm['height'] ?: 650)
                    );
                } else {
                    $mob_img = ($img_id && function_exists('get_field')) ? get_field('mob_img', $img_id) : null;
                    $tab_img = ($img_id && function_exists('get_field')) ? get_field('tab_img', $img_id) : null;

                    $sources_html  = [];
                    $fallback_data = null;

                    // Accepts ['(max-width: 500px)' => [440, 500]] OR [['media' => '...', 'size' => [440, 500], 'crop' => true]]
                    foreach ($args['sizes'] as $key => $rule) {
                        if (is_array($rule) && isset($rule['media'])) {
                            $media     = $rule['media'];
                            $size_spec = $rule['size'] ?? $rule['sizes'] ?? null;
                            $rule_crop = $rule['crop'] ?? $args['crop'];
                        } else {
                            $media     = $key;
                            $size_spec = $rule;
                            $rule_crop = $args['crop'];
                        }

                        // Art direction ACF mob_img / tab_img
                        $target_img = $raw_img;
                        if ($mob_img && preg_match('/max-width:\s*(?:[1-6]\d{2}|7[0-6]\d|500)px/i', $media)) {
                            $target_img = $mob_img;
                        } elseif ($tab_img && preg_match('/(?:max-width:\s*(?:102[0-4]|9\d{2})px|min-width:\s*7\d{2}px)/i', $media)) {
                            $target_img = $tab_img;
                        }

                        $resized = go_resize_image_on_the_fly($target_img, $size_spec, null, $rule_crop);
                        if ($resized && !empty($resized['url'])) {
                            $sources_html[] = sprintf(
                                '<source media="%s" type="%s" srcset="%s">',
                                esc_attr($media),
                                esc_attr($resized['mime_type'] ?? 'image/webp'),
                                esc_url($resized['url'])
                            );
                            $fallback_data = $resized;
                        }
                    }

                    if (!$fallback_data) {
                        $fallback_data = go_resize_image_on_the_fly($raw_img, 'medium', null, $args['crop']);
                    }
                    $fb_url = $fallback_data['url'] ?? $norm['url'];
                    $fb_w   = $fallback_data['width'] ?? ($norm['width'] ?: 650);
                    $fb_h   = $fallback_data['height'] ?? ($norm['height'] ?: 650);
                    ?>
                    <picture>
                        <?php echo implode("\n                        ", $sources_html); ?>
                        <img class="<?php echo esc_attr($img_class); ?>" width="<?php echo (int) $fb_w; ?>" height="<?php echo (int) $fb_h; ?>" loading="<?php echo esc_attr($loading); ?>"<?php echo $fetchpriority; ?> alt="<?php echo esc_attr($alt); ?>" src="<?php echo esc_url($fb_url); ?>">
                    </picture>
                    <?php
                }

            // CASE 1: Empty sizes -> standard default responsive logic
            } else {
                if ($is_svg) {
                    printf(
                        '<img class="%s" loading="%s"%s type="image/svg+xml" src="%s" alt="%s" width="%d" height="%d">',
                        esc_attr($img_class),
                        esc_attr($loading),
                        $fetchpriority,
                        esc_url($norm['url']),
                        esc_attr($alt),
                        (int) ($norm['width'] ?: 650),
                        (int) ($norm['height'] ?: 650)
                    );
                } else {
                    $mob_img = ($img_id && function_exists('get_field')) ? get_field('mob_img', $img_id) : null;
                    $tab_img = ($img_id && function_exists('get_field')) ? get_field('tab_img', $img_id) : null;

                    // Mobile (< 500px): mob_img if present, otherwise thumbnail
                    $mob_src  = $mob_img ?: $raw_img;
                    $mob_data = go_resize_image_on_the_fly($mob_src, 'thumbnail', null, true);
                    $mob_url  = $mob_data['url'] ?? $norm['url'];

                    // Tablet (< 1023px): tab_img if present, otherwise medium
                    $tab_src  = $tab_img ?: $raw_img;
                    $tab_data = go_resize_image_on_the_fly($tab_src, 'medium', null, false);
                    $tab_url  = $tab_data['url'] ?? $norm['url'];

                    // Desktop (>= 1024px): large if fullscreen, otherwise medium
                    $desk_format = $args['fs'] ? 'large' : 'medium';
                    $desk_data   = go_resize_image_on_the_fly($raw_img, $desk_format, null, false);
                    $desk_url    = $desk_data['url'] ?? $norm['url'];

                    $w = $desk_data['width'] ?? ($norm['width'] ?: 650);
                    $h = $desk_data['height'] ?? ($norm['height'] ?: 650);
                    ?>
                    <picture>
                        <source media="(max-width: 500px)" type="image/webp" srcset="<?php echo esc_url($mob_url); ?>">
                        <source media="(max-width: 1023px)" type="image/webp" srcset="<?php echo esc_url($tab_url); ?>">
                        <source media="(min-width: 1024px)" type="image/webp" srcset="<?php echo esc_url($desk_url); ?>">

                        <img class="<?php echo esc_attr($img_class); ?>" width="<?php echo (int) $w; ?>" height="<?php echo (int) $h; ?>" loading="<?php echo esc_attr($loading); ?>"<?php echo $fetchpriority; ?> alt="<?php echo esc_attr($alt); ?>" src="<?php echo esc_url($desk_url); ?>">
                    </picture>
                    <?php
                }
            }
            ?>

            <?php if ($args['figcaption']) : ?>
                <?php if (!empty($caption)) : ?>
                    <figcaption class="img-wrap__figcaption"><?php echo esc_html($caption); ?></figcaption>
                <?php endif; ?>
                <?php if (!empty($norm['url'])) : ?>
                    <meta itemprop="url" content="<?php echo esc_url($norm['url']); ?>"/>
                <?php endif; ?>
                <?php if (!empty($description)) : ?>
                    <meta itemprop="description" content="<?php echo esc_attr($description); ?>"/>
                <?php endif; ?>
                <?php if (!empty($title)) : ?>
                    <meta itemprop="name" content="<?php echo esc_attr($title); ?>"/>
                <?php endif; ?>
                </figure>
            <?php endif; ?>

        <?php else : ?>
            <img class="img-wrap__img" width="650" height="650" loading="<?php echo esc_attr($loading); ?>"<?php echo $fetchpriority; ?> src="<?php echo esc_url(plugins_url('../assets/image_placeholder.svg', __FILE__)); ?>" alt="Logo de <?php bloginfo('name'); ?> - Aucune image trouvée">
        <?php endif; ?>
    </div>

    <?php
}

function render_video($args = []) {

    $video = $args['video'] ?? null;
    
    if (empty($video)) {
        return;
    }

    $video_id = !empty($video['ID']) ? $video['ID'] : (!empty($video['id']) ? $video['id'] : 0);
    $force_portrait = ($video_id && function_exists('get_field')) ? get_field('force_portrait', $video_id) : false;
    $display_legend = ($video_id && function_exists('get_field')) ? get_field('display_legend', $video_id) : false;
    $seamless       = ($video_id && function_exists('get_field')) ? get_field('seamless', $video_id) : false;

    $defaults = [
        'autoplay' => null,
        'loop' => null,
        'muted' => false,

        'defer' => true,

        'controls' => true,
        'controls_muted' => true,
        'controls_fs' => true,
        
        'fs_vid' => true,
    ];
    
    // Combine both argument arrays - $args is primary
    $args = wp_parse_args($args, $defaults);

    $figcaption = isset($args['figcaption']) ? $args['figcaption'] : false;

    // Get ACF fields for video attributes
    $autoplay = get_field('autoplay', $video['ID']);
    $loop = get_field('loop', $video['ID']);
    $muted = get_field('muted', $video['ID']);
    $controls = get_field('controls', $video['ID']);
    $controls_muted = get_field('controls_muted', $video['ID']);
    $controls_fs = get_field('controls_fs', $video['ID']);
    $fs = get_field('fs_vid', $video['ID']);
    $resp_video = get_field('vid_resp', $video['ID']);
    $thumbnail_id = get_field('thumbnail', $video['ID']);
    
    // Set video attributes
    $autoplay_attr = $autoplay ? ' autoplay' : '';
    $loop_attr = $loop ? ' loop' : '';
    $muted_attr = $muted ? ' muted' : '';

    // HTML output
    ?>
    <div class="vid-wrap vid-wrap--unTouched vid-wrap--loading vid-wrap--progress-loading<?php echo $autoplay ? ' vid-wrap--playing' : ''; ?>">

        <?php if ($figcaption): ?>
            <figure itemscope itemtype="http://schema.org/VideoObject">
        <?php endif; ?>
        
        <video class="vid-wrap__video" <?php echo $loop_attr; echo $muted_attr; echo $autoplay_attr; ?>
               preload="auto" width="<?php echo esc_attr($video['width']); ?>" height="<?php echo esc_attr($video['height']); ?>">
            
            <?php if ($fs): ?>
                <source data-src="<?php echo esc_url($video['url']); ?>" src="..." type="video/<?php echo esc_attr($video['subtype']); ?>" media="only screen and (min-width: 720px)">
                <?php if ($resp_video): ?>
                    <source data-src="<?php echo esc_url($resp_video['url']); ?>" src="..." type="video/<?php echo esc_attr($resp_video['subtype']); ?>" media="only screen and (max-width: 719px)">
                <?php endif; ?>
            <?php else: ?>
                <source data-src="<?php echo esc_url($video['url']); ?>" src="..." type="video/<?php echo esc_attr($video['subtype']); ?>">
            <?php endif; ?>
        </video>

        <?php if ($controls): ?>
            <div class="vid-wrap__controls" data-state="hidden">
                <button class="vid-wrap__controls__playpause" type="button" data-state="play" aria-label="<?php echo esc_attr(__('Play/Pause', 'go-media-renderer')); ?>">
                    <?php echo get_svg(dirname(__DIR__) . '/assets/icons/play.svg', true); ?>
                    <?php echo get_svg(dirname(__DIR__) . '/assets/icons/pause.svg', true); ?>
                </button>
                <button class="vid-wrap__controls__stop" type="button" data-state="stop" aria-label="<?php echo esc_attr(__('Stop', 'go-media-renderer')); ?>">
                    <?php echo get_svg(dirname(__DIR__) . '/assets/icons/stop.svg', true); ?>
                </button>
                <div class="vid-wrap__controls__progress">
                    <progress value="0" min="0"></progress>
                </div>
                <?php if (!$muted && $controls_muted): ?>
                    <button class="vid-wrap__controls__mute" type="button" data-state="mute" aria-label="<?php echo esc_attr(__('Activer/désactiver sourdine', 'go-media-renderer')); ?>">
                        <?php echo get_svg(dirname(__DIR__) . '/assets/icons/mute.svg', true); ?>
                        <?php echo get_svg(dirname(__DIR__) . '/assets/icons/unmute.svg', true); ?>
                    </button>
                <?php endif; ?>
                <?php if ($controls_fs): ?>
                    <button class="vid-wrap__controls__fs" type="button" data-state="go-fullscreen" aria-label="<?php echo esc_attr(__('Plein écran', 'go-media-renderer')); ?>">
                        <?php echo get_svg(dirname(__DIR__) . '/assets/icons/fullscreen.svg', true); ?>
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($figcaption): ?>
            <figcaption><?php echo esc_html($video['caption']); ?></figcaption>
            <meta itemprop="url" content="<?php echo esc_url($video['url']); ?>" />
            <meta itemprop="description" content="<?php echo esc_html($video['description']); ?>" />
            <meta itemprop="name" content="<?php echo esc_html($video['title']); ?>" />
            <?php
            // Utilisez render_image() ici si vous avez besoin d'une balise complète
            if (!empty($thumbnail_id)) {
                $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'full');
                echo '<meta itemprop="thumbnailUrl" content="' . esc_url($thumbnail_url) . '" />';
            }
            ?>
            <meta itemprop="uploadDate" content="<?php echo esc_attr(date('Y-m-d\TH:i:s\Z', strtotime($video['date']))); ?>" />
            <meta itemprop="contentUrl" content="<?php echo esc_url($video['url']); ?>" />
            </figure>
        <?php endif; ?>

        <?php if (!empty($thumbnail_id)): ?>
            <?php
            // Votre fonction render_image() est appelée ici avec le bon format
            render_image([
                'img' => get_field('thumbnail', $video['ID']),
                'defer' => $args['defer'],
            ]);
            ?>
        <?php endif; ?>
    </div>
    <?php
}

// Display svg's code instead of an 'img' element
function get_svg( $media_file, $is_path = false ) {
    if ($is_path) {
        if (file_exists($media_file)) {
            $html = file_get_contents( $media_file );
            // Security: strip potentially malicious scripts/tags from SVG if needed, but since it's local, it's trusted.
            return $html;
        }
        return '';
    }else{
        if ($media_file['mime_type'] === 'image/svg+xml') {
            $file_path = get_attached_file( $media_file['ID'] );
            $html = file_get_contents( $file_path );
            return $html;
        }
    }

    return is_user_logged_in() ? '<p class="admin-msg">Invalid file type. Please upload an SVG.</p>' : '';
}

?>
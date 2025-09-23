<?php

function render_image($args = []) {

        // If image is empty get placeholder in "general" option page
        $img = $args['img'] ?: get_field('img_placeholder', 'options');

        // Classes parameters - Can be overwritten by WebMaster in $args
        // force_portrait   | create a blured bg image
        // display_legend   | allow to show or hide <caption> 
        // seamless         | add a class deleting default styles
        $force_portrait = !empty($img) ? get_field('force_portrait', $img['id']) : false;
        $display_legend = !empty($img) ? get_field('display_legend', $img['id']) : false;
        $seamless = !empty($img) ? get_field('seamless', $img['id']) : false;

        $defaults = [
            'img' => null,
            'format' => null,
            'fs' => false,
            'defer' => true,

            'seamless' => $seamless,
            'force_portrait' => $force_portrait,
            'display_legend' => $display_legend,
            
            // If display legend is true, we need <figcaption> anyway
            'figcaption' => $display_legend,
        ];
        
        // Combine both argument arrays - $args is primary
        $args = wp_parse_args($args, $defaults);

        // Validate 'img' argument
        if ($args['img'] && (!is_array($args['img']) || !isset($args['img']['url']))) {
            trigger_error('Invalid image format provided. Expected an array with a "url" key.', E_USER_WARNING);
            $args['img'] = null;
        }

        $loading = $args['defer'] ? "lazy" : "eager";
        $mime_type = $img['mime_type'] ?? '';
        $is_svg = $mime_type == 'image/svg+xml';

        ?>

        <div class="img-wrap<?php 
            echo $args['force_portrait'] ? ' img-wrap--portrait' : ''; 
            echo $args['seamless'] ? ' img-wrap--seamless' : ''; 
            echo $args['display_legend'] ? ' img-wrap--displayLegend' : ''; 
            ?>">

            <?php if ($img) : ?>
                <?php 
                // Render blured bg image in "force_portrait" mode
                if ($args['force_portrait'] && !$is_svg) { 
                    echo '<!--googleoff: index--><img class="img-wrap__bg" loading="'. esc_attr($loading) .'" type="'. esc_attr($mime_type) .'" src="'. esc_url($img['sizes']['thumbnail']) .'" alt="'. esc_attr($img['alt']) .'"><!--googleon: index-->'; }
                ?>
                
                <?php if ($args['figcaption']) : ?>
                    <figure class="img-wrap__figure" itemscope itemtype="http://schema.org/ImageObject">
                <?php endif; ?>

                <?php 
                // Render picture content if no image format is set, and if file is svg only with tab or mob files assigned
                $no_img_format = !$args['format'];

                if ($no_img_format && !$is_svg) {
                    $mob = get_field('mob_img', $img['id']);
                    $tab = get_field('tab_img', $img['id']);
                    $thumbnail = $mob ? $mob['sizes']['thumbnail'] : $img['sizes']['thumbnail'];
                    $medium = $tab ? $tab['sizes']['medium'] : $img['sizes']['medium'];

                    // Width & Height attr
                    $w = $img['width'] ? $img['width'] : 650;
                    $h = $img['height'] ? $img['height'] : 650;
                    ?>
                    <picture>
                        <source media="(max-width: 500px)" type="<?php echo esc_attr($mime_type); ?>" srcset="<?php echo esc_url($thumbnail); ?>">
                        <source media="(max-width: 1023px)" type="<?php echo esc_attr($mime_type); ?>" srcset="<?php echo esc_url($medium); ?>">

                        <?php if ($args['fs']) : ?>
                            <source media="(min-width: 1024px)" type="<?php echo esc_attr($mime_type); ?>" srcset="<?php echo esc_url($img['sizes']['large']); ?>">
                        <?php elseif ($img['sizes']['medium'] !== $medium) : ?>
                            <source media="(min-width: 1024px)" type="<?php echo esc_attr($mime_type); ?>" srcset="<?php echo esc_url($img['sizes']['medium']); ?>">
                        <?php endif; ?>

                        <img class="img-wrap__img" width="<?php echo $w; ?>" width="<?php echo $h; ?>" loading="<?php echo esc_attr($loading); ?>" alt="<?php echo esc_attr($img['alt']); ?>" src="<?php echo esc_url($medium); ?>">

                    </picture>
                    <?php
                } else {
                    
                    if (!$is_svg) { // Has image format but is not svg
                        
                        // Fallback if the image format is not generated on the website
                        $format = isset($img['sizes'][$args['format']]) ? $img['sizes'][$args['format']] : $img['sizes']['thumbnail'];
                        if (!isset($img['sizes'][$args['format']]) && is_user_logged_in()) {
                            echo "<span class='admin-msg'>Format not found. Thumbnail loaded.</span>";
                        }

                        printf('<img class="img-wrap__img" loading="%s" type="%s" src="%s" alt="%s" width="%d" height="%d">', esc_attr($loading), esc_attr($mime_type), esc_url($format), esc_attr($img['alt']), esc_attr($img['width']), esc_attr($img['height']));

                    } else { // Is SVG
                        printf('<img class="img-wrap__img" loading="%s" type="%s" src="%s" alt="%s" width="%d" height="%d">', esc_attr($loading), esc_attr($mime_type), esc_url($img['url']), esc_attr($img['alt']), esc_attr($img['width']), esc_attr($img['height']));
                    }
                }
                ?>

                <?php 
                // Render figcaption 
                if ($args['figcaption']) :
                        if (!empty($img['caption'])) { echo '<figcaption class="img-wrap__figcaption">' . esc_html($img['caption']) . '</figcaption>'; }
                        if (!empty($img['url'])) { echo '<meta itemprop="url" content="' . esc_html($img['url']) . '"/>'; }
                        if (!empty($img['description'])) { echo '<meta itemprop="description" content="' . esc_html($img['description']) . '"/>'; }
                        if (!empty($img['name'])) { echo '<meta itemprop="name" content="' . esc_html($img['name']) . '"/>'; } ?>
                    </figure>
                <?php endif; ?>

            <?php else : ?>
                <img class="img-wrap__img" width="650" height="650" loading="<?php echo esc_attr($loading) ?>" src="<?php echo esc_url(plugins_url('/assets/image_placeholder.svg', __FILE__)); ?>" alt="Logo de <?php bloginfo('name'); ?> - Aucune image trouvée">
            <?php endif; ?>
        </div>

    <?php
}

function render_video($args = []) {

    $video = $args['video'];
    
    if (empty($video)) {
        return;
    }

    $force_portrait = !empty($img) ? get_field('force_portrait', $img['id']) : false;
    $display_legend = !empty($img) ? get_field('display_legend', $img['id']) : false;
    $seamless = !empty($img) ? get_field('seamless', $img['id']) : false;

    $defaults = [
        'autoplay' => null,
        'loop' => null,
        'muted' => false,

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
                    <?php echo get_svg(plugins_url('../assets/icons/play.svg', __FILE__), true); ?>
                    <?php echo get_svg(plugins_url('../assets/icons/pause.svg', __FILE__), true); ?>
                </button>
                <button class="vid-wrap__controls__stop" type="button" data-state="stop" aria-label="<?php echo esc_attr(__('Stop', 'go-media-renderer')); ?>">
                    <?php echo get_svg(plugins_url('../assets/icons/stop.svg', __FILE__), true); ?>
                </button>
                <div class="vid-wrap__controls__progress">
                    <progress value="0" min="0"></progress>
                </div>
                <?php if (!$muted && $controls_muted): ?>
                    <button class="vid-wrap__controls__mute" type="button" data-state="mute" aria-label="<?php echo esc_attr(__('Activer/désactiver sourdine', 'go-media-renderer')); ?>">
                        <?php echo get_svg(plugins_url('../assets/icons/mute.svg', __FILE__), true); ?>
                        <?php echo get_svg(plugins_url('../assets/icons/unmute.svg', __FILE__), true); ?>
                    </button>
                <?php endif; ?>
                <?php if ($controls_fs): ?>
                    <button class="vid-wrap__controls__fs" type="button" data-state="go-fullscreen" aria-label="<?php echo esc_attr(__('Plein écran', 'go-media-renderer')); ?>">
                        <?php echo get_svg(plugins_url('../assets/icons/fullscreen.svg', __FILE__), true); ?>
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
            render_image(['img' => get_field('thumbnail', $video['ID'])]);
            ?>
        <?php endif; ?>
    </div>
    <?php
}

// Display svg's code instead of an 'img' element
function get_svg( $media_file, $is_url = false ) {
    if ($is_url) {
        $html = file_get_contents( $media_file );
        return $html;
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
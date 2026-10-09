<?php

// Redirect from former plugins.php URL to Tools (tools.php)
add_action('admin_init', function () {
    global $pagenow;
    if ($pagenow === 'plugins.php' && isset($_GET['page']) && $_GET['page'] === 'wp-media-renderer-license') {
        wp_safe_redirect(admin_url('tools.php?page=wp-media-renderer-license'));
        exit;
    }
});

// Add menu page under default Tools tab (tools.php)
add_action('admin_menu', function () {
    add_management_page(
        'Media Renderer',
        'Media Renderer',
        'manage_options',
        'wp-media-renderer-license',
        'wp_media_renderer_license_page'
    );
});

// Admin interface
function wp_media_renderer_license_page() {
    $license_key = get_option('wp_media_renderer_license_key', '');
    $status      = get_option('wp_media_renderer_license_status', 'inactive');
    $message     = get_option('wp_media_renderer_license_message', '');

    $image_count = count(get_posts([
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]));

    $clean_nonce = wp_create_nonce('wp_media_renderer_clean_nonce');
    ?>
    <div class="wrap">
        <h1>Media Renderer</h1>

        <?php if (!empty($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Réglages mis à jour.</p>
            </div>
        <?php endif; ?>

        <?php if (!empty($message)) : ?>
            <div class="notice <?php echo ($status === 'active') ? 'notice-success' : 'notice-error'; ?>">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Carte 1 : Passage en génération d'image à la volée -->
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>Passer en génération d'image à la volée</h2>
            <p>
                Ce script optimise votre médiathèque pour basculer vers le nouveau moteur de génération dynamique :
            </p>
            <ul style="list-style: disc; margin-left: 20px; line-height: 1.6;">
                <li>Convertit l'image principale JPG/PNG en format léger <strong>WebP</strong>.</li>
                <li>Supprime les originaux non redimensionnés lorsque la version <code>scaled</code> existe.</li>
                <li>Supprime toutes les tailles d'images intermédiaires pré-générées non nécessaires pour libérer de l'espace disque.</li>
                <li>Active la <strong>génération à la volée</strong> : seules les dimensions réellement appelées dans vos templates seront générées lors de l'exécution de <code>render_image()</code>.</li>
            </ul>

            <p style="margin-top: 15px;">
                <strong>Images trouvées dans la médiathèque :</strong> 
                <span class="badge" style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; font-weight: bold;"><?php echo (int) $image_count; ?> images</span>
            </p>

            <div style="margin-top: 20px;">
                <button type="button" id="mr-start-clean-btn" class="button button-primary button-hero" <?php echo ($image_count === 0) ? 'disabled' : ''; ?>>
                    Passer en génération d'image à la volée
                </button>
            </div>

            <!-- Barre de progression -->
            <div id="mr-progress-wrapper" style="display: none; margin-top: 25px;">
                <div style="background: #e2e4e7; border-radius: 4px; height: 26px; overflow: hidden; position: relative; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
                    <div id="mr-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.2s ease;"></div>
                    <div id="mr-progress-label" style="position: absolute; width: 100%; text-align: center; top: 0; line-height: 26px; font-size: 13px; font-weight: 600; color: #1d2327;">0%</div>
                </div>

                <p id="mr-status-text" style="margin-top: 10px; font-style: italic; color: #50575e;">Initialisation...</p>

                <div id="mr-stats" style="margin-top: 15px; display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; background: #f6f7f7; padding: 12px; border-radius: 4px; border: 1px solid #dcdcde;">
                    <div><strong>Images traitées :</strong> <br><span id="mr-stat-processed">0</span> / <span id="mr-stat-total">0</span></div>
                    <div><strong>Fichiers convertis :</strong> <br><span id="mr-stat-converted">0</span></div>
                    <div><strong>Fichiers supprimés :</strong> <br><span id="mr-stat-deleted">0</span></div>
                    <div><strong>Espace libéré :</strong> <br><span id="mr-stat-freed">0 Ko</span></div>
                </div>
            </div>

            <div id="mr-complete-notice" style="display: none; margin-top: 20px;" class="notice notice-success inline">
                <p id="mr-complete-text"><strong>Passage en génération à la volée terminé avec succès !</strong></p>
            </div>
        </div>

        <!-- Carte 2 : Licence -->
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>Licence</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wp_media_renderer_license_nonce', 'wp_media_renderer_license_nonce'); ?>
                <input type="hidden" name="action" value="wp_media_renderer_save_license">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wp_media_renderer_license_key">Clé de licence</label></th>
                        <td>
                            <input type="text" name="wp_media_renderer_license_key" id="wp_media_renderer_license_key"
                                   value="<?php echo esc_attr($license_key); ?>" class="regular-text">
                            <p class="description">Entrez votre clé de licence pour activer les fonctionnalités de rendu dynamique et d'optimisation.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Statut</th>
                        <td>
                            <?php if ($status === 'active') : ?>
                                <span style="display: inline-block; padding: 4px 10px; background: #d1e7dd; color: #0f5132; border-radius: 3px; font-weight: bold;">Actif</span>
                            <?php else : ?>
                                <span style="display: inline-block; padding: 4px 10px; background: #f8d7da; color: #842029; border-radius: 3px; font-weight: bold;">Inactif</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Enregistrer la clé de licence'); ?>
            </form>
        </div>
    </div>

    <script>
    (function() {
        const startBtn       = document.getElementById('mr-start-clean-btn');
        const progressWrap   = document.getElementById('mr-progress-wrapper');
        const progressBar    = document.getElementById('mr-progress-bar');
        const progressLabel  = document.getElementById('mr-progress-label');
        const statusText     = document.getElementById('mr-status-text');
        const statProcessed  = document.getElementById('mr-stat-processed');
        const statTotal      = document.getElementById('mr-stat-total');
        const statConverted  = document.getElementById('mr-stat-converted');
        const statDeleted    = document.getElementById('mr-stat-deleted');
        const statFreed      = document.getElementById('mr-stat-freed');
        const completeNotice = document.getElementById('mr-complete-notice');
        const completeText   = document.getElementById('mr-complete-text');

        if (!startBtn) return;

        function formatBytes(bytes) {
            if (bytes === 0) return '0 Octet';
            const k = 1024;
            const sizes = ['Octets', 'Ko', 'Mo', 'Go'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        startBtn.addEventListener('click', function() {
            if (!confirm('Voulez-vous passer en génération d\'image à la volée ? Cette opération va convertir les images principales en WebP, supprimer les originaux lourds et supprimer les déclinaisons intermédiaires existantes pour libérer de l\'espace. Les formats nécessaires seront ensuite re-générés automatiquement à la volée lors de l\'affichage.')) {
                return;
            }

            startBtn.disabled = true;
            completeNotice.style.display = 'none';
            progressWrap.style.display = 'block';
            statusText.textContent = 'Récupération de la liste des images...';
            progressBar.style.width = '0%';
            progressLabel.textContent = '0%';

            let totalImages = 0;
            let processedImages = 0;
            let totalDeleted = 0;
            let totalConverted = 0;
            let totalFreedBytes = 0;
            const batchSize = 5;

            const nonce = '<?php echo esc_js($clean_nonce); ?>';

            // 1. Récupérer les IDs
            const data = new URLSearchParams();
            data.append('action', 'wp_media_renderer_get_clean_ids');
            data.append('nonce', nonce);

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data.toString()
            })
            .then(res => res.json())
            .then(response => {
                if (!response.success || !response.data || !response.data.ids) {
                    throw new Error(response.data?.message || 'Erreur lors de la récupération des images.');
                }

                const ids = response.data.ids;
                totalImages = ids.length;
                statTotal.textContent = totalImages;

                if (totalImages === 0) {
                    statusText.textContent = 'Aucune image à traiter.';
                    startBtn.disabled = false;
                    return;
                }

                // Découpage en lots
                const batches = [];
                for (let i = 0; i < ids.length; i += batchSize) {
                    batches.push(ids.slice(i, i + batchSize));
                }

                function processNextBatch(index) {
                    if (index >= batches.length) {
                        // Terminé !
                        progressBar.style.width = '100%';
                        progressLabel.textContent = '100%';
                        statusText.textContent = 'Traitement terminé !';
                        completeText.innerHTML = '<strong>Assainissement terminé avec succès !</strong> ' +
                            processedImages + ' images traitées, ' +
                            totalConverted + ' fichiers convertis en WebP, ' +
                            totalDeleted + ' fichiers supprimés (' + formatBytes(totalFreedBytes) + ' libérés).';
                        completeNotice.style.display = 'block';
                        startBtn.disabled = false;
                        return;
                    }

                    const currentBatch = batches[index];
                    statusText.textContent = 'Traitement du lot ' + (index + 1) + ' sur ' + batches.length + '...';

                    const batchData = new URLSearchParams();
                    batchData.append('action', 'wp_media_renderer_clean_batch');
                    batchData.append('nonce', nonce);
                    currentBatch.forEach(id => batchData.append('ids[]', id));

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: batchData.toString()
                    })
                    .then(res => res.json())
                    .then(resData => {
                        if (resData.success && resData.data) {
                            totalDeleted += resData.data.deleted || 0;
                            totalConverted += resData.data.converted || 0;
                            totalFreedBytes += resData.data.freed || 0;
                        }
                    })
                    .catch(err => {
                        console.error('Erreur sur le lot ' + (index + 1), err);
                    })
                    .finally(() => {
                        processedImages += currentBatch.length;
                        if (processedImages > totalImages) processedImages = totalImages;

                        statProcessed.textContent = processedImages;
                        statDeleted.textContent = totalDeleted;
                        statConverted.textContent = totalConverted;
                        statFreed.textContent = formatBytes(totalFreedBytes);

                        const percent = Math.round((processedImages / totalImages) * 100);
                        progressBar.style.width = percent + '%';
                        progressLabel.textContent = percent + '%';

                        processNextBatch(index + 1);
                    });
                }

                processNextBatch(0);
            })
            .catch(error => {
                alert('Erreur : ' + error.message);
                statusText.textContent = 'Erreur survenue : ' + error.message;
                startBtn.disabled = false;
            });
        });
    })();
    </script>
    <?php
}

// Handle license save request
add_action('admin_post_wp_media_renderer_save_license', function () {
    if (!isset($_POST['wp_media_renderer_license_nonce']) || !wp_verify_nonce($_POST['wp_media_renderer_license_nonce'], 'wp_media_renderer_license_nonce')) {
        wp_die('Security error.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    $license_key = isset($_POST['wp_media_renderer_license_key']) ? sanitize_text_field($_POST['wp_media_renderer_license_key']) : '';

    // Store key
    update_option('wp_media_renderer_license_key', $license_key);

    // Validate key with license server
    wp_media_renderer_validate_license_key($license_key);

    // Redirect to Tools > Media Renderer
    wp_redirect(admin_url('tools.php?page=wp-media-renderer-license&updated=true'));
    exit;
});

// Remote endpoint call for license validation
function wp_media_renderer_validate_license_key($license_key) {
    $domain       = home_url();
    $endpoint_url = "https://grow-online.be/licences/licence-check.php";

    delete_option('wp_media_renderer_license_status');
    delete_option('wp_media_renderer_license_message');

    $response = wp_remote_post($endpoint_url, [
        'timeout' => 15,
        'body'    => [
            'type'        => "wp_media_renderer",
            'license_key' => $license_key,
            'domain'      => $domain
        ]
    ]);

    if (is_wp_error($response)) {
        update_option('wp_media_renderer_license_status', 'inactive');
        update_option('wp_media_renderer_license_message', 'Error communicating with license server.');
        return;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($data['success']) && $data['success'] === true) {
        update_option('wp_media_renderer_license_status', 'active');
        update_option('wp_media_renderer_license_message', $data['message']);
    } else {
        update_option('wp_media_renderer_license_status', 'inactive');
        update_option('wp_media_renderer_license_message', $data['message'] ?? 'Invalid license.');
    }
}

// Monthly license status check
add_action('wp', function () {
    if (!wp_next_scheduled('wp_media_renderer_auto_license_check')) {
        wp_schedule_event(time(), 'monthly', 'wp_media_renderer_auto_license_check');
    }
});

add_action('wp_media_renderer_auto_license_check', 'wp_media_renderer_check_license_status');

function wp_media_renderer_check_license_status() {
    $license_key = get_option('wp_media_renderer_license_key', '');
    $domain      = home_url();
    $endpoint_url = "https://grow-online.be/licences/licence-check.php";

    if (empty($license_key)) {
        return;
    }

    $response = wp_remote_post($endpoint_url, [
        'timeout' => 15,
        'body'    => [
            'type'        => 'wp_media_renderer',
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
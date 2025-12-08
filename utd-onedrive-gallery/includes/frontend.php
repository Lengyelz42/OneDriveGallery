<?php

// Frontend: shortcode and minimal post-load JS for deferred image loading

add_action('init', function() {
    add_shortcode('onedrive_gallery', 'utd_onedrive_gallery_shortcode');
});

// Register a simple block editor block so the gallery can be inserted via the block inserter.
add_action('init', function() {
    // Editor script handle (empty src; we'll add inline JS using WP globals so no build step required)
    $handle = 'utd-onedrive-block-editor';
    wp_register_script($handle, '', array('wp-blocks','wp-element','wp-i18n','wp-editor','wp-components'), null);

    $editor_js = "(function(wp){\n" .
        "var el = wp.element.createElement;\n" .
        "var registerBlockType = wp.blocks.registerBlockType;\n" .
        "var TextControl = wp.components.TextControl;\n" .
        "var ToggleControl = wp.components.ToggleControl;\n" .
        "var useBlockProps = (wp.blockEditor && wp.blockEditor.useBlockProps) ? wp.blockEditor.useBlockProps : (wp.editor && wp.editor.useBlockProps) ? wp.editor.useBlockProps : function(){ return {}; };\n" .
        "registerBlockType('utd-onedrive-gallery/gallery', {\n" .
            "title: wp.i18n.__('OneDrive Gallery','utd-onedrive-gallery'),\n" .
            "icon: 'format-gallery',\n" .
            "category: 'widgets',\n" .
            "attributes: { folder: { type: 'string', default: '' }, prop: { type: 'boolean', default: false }, irp: { type: 'string', default: '' } },\n" .
            "edit: function(props){\n" .
                "var bp = useBlockProps();\n" .
                "var attrs = props.attributes || {};\n" .
                "return el('div', bp,\n" .
                    "el('p', null, wp.i18n.__('This block inserts the OneDrive gallery shortcode. Configure folder below.','utd-onedrive-gallery')),";
    $editor_js .= "\n" .
        "        el(TextControl, { label: wp.i18n.__('Folder','utd-onedrive-gallery'), value: attrs.folder, onChange: function(v){ props.setAttributes({ folder: v }); } }),\n" .
        "        el(ToggleControl, { label: wp.i18n.__('Proportional view (prop)','utd-onedrive-gallery'), checked: !!attrs.prop, onChange: function(v){ props.setAttributes({ prop: !!v }); } }),\n" .
        "        el(TextControl, { label: wp.i18n.__('Images per row (irp)','utd-onedrive-gallery'), value: attrs.irp || '', type: 'number', onChange: function(v){ props.setAttributes({ irp: v }); }, help: wp.i18n.__('Enter an integer 1-12 to override the Images Per Row setting for this block instance.','utd-onedrive-gallery') })\n" .
        "    );\n" .
        "},\n" .
            "save: function(){ return null; }\n" .
        "});\n" .
    "})(window.wp);";

    wp_add_inline_script($handle, $editor_js);

    // Server-side render so the block outputs the same markup as the shortcode on the frontend
    register_block_type('utd-onedrive-gallery/gallery', array(
        'editor_script' => $handle,
        'render_callback' => 'utd_onedrive_gallery_block_render',
    ));
});

function utd_onedrive_gallery_block_render($attributes) {
    $atts = array('folder' => isset($attributes['folder']) ? $attributes['folder'] : '');
    // Forward block attributes to shortcode: prop (true/false) and irp (images per row override)
    if (isset($attributes['prop'])) {
        $atts['prop'] = $attributes['prop'] ? 'true' : 'false';
    }
    if (isset($attributes['irp']) && $attributes['irp'] !== '') {
        $atts['ipr'] = sanitize_text_field($attributes['irp']);
    } elseif (isset($attributes['ipr']) && $attributes['ipr'] !== '') {
        // support legacy ipr attribute if present
        $atts['ipr'] = sanitize_text_field($attributes['ipr']);
    }
    return utd_onedrive_gallery_shortcode($atts);
}

// Fetch items from Graph for the given folder name. This uses the shared
// token helpers in the main plugin file (utd_onedrive_graph_api_get etc.).
function utd_onedrive_gallery_fetch_graph_items($folder_name = '', $user_principal = '', $fetch_urls = true) {
    if (empty($folder_name)) return array();
    $base = '/me/drive';

    $items = array();
    // determine children listing (path vs root lookup)
    if (strpos($folder_name, '/') !== false || strpos($folder_name, '\\') !== false) {
        $folder_name = str_replace('\\', '/', $folder_name);
        $parts = array_filter(array_map('trim', explode('/', $folder_name)));
        if (empty($parts)) return new WP_Error('folder_not_found', "Invalid folder path '{$folder_name}'");
        $encoded = implode('/', array_map('rawurlencode', $parts));
        $children = utd_onedrive_graph_api_get($base . '/root:/' . $encoded . ':/children?$select=@microsoft.graph.downloadUrl,file,image,photo,id,name');
        if (is_wp_error($children)) return $children;
    } else {
        $resp = utd_onedrive_graph_api_get($base . '/root/children');
        if (is_wp_error($resp)) return $resp;
        $folder = null;
        foreach ($resp['value'] as $child) {
            if (!empty($child['folder']) && strcasecmp($child['name'], $folder_name) === 0) {
                $folder = $child;
                break;
            }
        }
        if (empty($folder)) return new WP_Error('folder_not_found', "Folder '{$folder_name}' not found in OneDrive root.");
        $children = utd_onedrive_graph_api_get($base . '/items/' . $folder['id'] . '/children?$select=@microsoft.graph.downloadUrl,file,image,photo,id,name');
        if (is_wp_error($children)) return $children;
    }

    foreach ($children['value'] as $c) {
        if (!empty($c['folder'])) continue;
        $mime = $c['file']['mimeType'] ?? '';
        $type = '';
        if (stripos($mime, 'image/') === 0) $type = 'image';
        if (stripos($mime, 'video/') === 0) $type = 'video';
        if (empty($type)) continue;

        $durl = '';
        if ($fetch_urls) {
            if (!empty($c['@microsoft.graph.downloadUrl'])) {
                $durl = $c['@microsoft.graph.downloadUrl'];
            } else {
                // Fallback to capture redirect from content endpoint
                $content_resp = wp_remote_get('https://graph.microsoft.com/v1.0' . $base . '/items/' . $c['id'] . '/content', array('redirection' => 0, 'timeout' => 15));
                if (!is_wp_error($content_resp)) {
                    $durl = wp_remote_retrieve_header($content_resp, 'location') ?: '';
                }
            }
        }

        $w = null; $h = null;
        if (!empty($c['image'])) {
            $w = isset($c['image']['width']) ? intval($c['image']['width']) : $w;
            $h = isset($c['image']['height']) ? intval($c['image']['height']) : $h;
        }
        if (!empty($c['photo'])) {
            $w = isset($c['photo']['width']) ? intval($c['photo']['width']) : $w;
            $h = isset($c['photo']['height']) ? intval($c['photo']['height']) : $h;
        }

        $items[] = array('type' => $type, 'url' => esc_url_raw($durl), 'name' => $c['name'], 'id' => $c['id'], 'w' => $w, 'h' => $h);
    }
    return $items;
}

// Shortcode: outputs the gallery grid but uses data attributes for deferred loading
function utd_onedrive_gallery_shortcode($atts) {
    $atts = shortcode_atts(array(
        'id' => '',
        'folder' => '',
        'prop' => '',
        // ipr: images-per-row override for this shortcode instance (1-12)
        'ipr' => '',
    ), $atts, 'onedrive_gallery');

    // Respect admin setting: if load_images is disabled, skip requesting download URLs
    $options = get_option('utd_onedrive_gallery_settings');
    $load_images = isset($options['load_images']) ? boolval($options['load_images']) : false;

    // If 'prop' is set, override the proportional view setting
    if (isset($atts['prop']) && $atts['prop'] !== '') {
        $prop_val = strtolower(trim($atts['prop']));
        if ($prop_val === 'true' || $prop_val === '1') {
            $load_images = true;
        } elseif ($prop_val === 'false' || $prop_val === '0') {
            $load_images = false;
        }
    }

    // Use images_per_row setting to size grid items consistently
    $images_per_row = intval($options['images_per_row'] ?? 3);
    if ($images_per_row < 1) $images_per_row = 1;

    // Shortcode override: ipr (images per row) or irp (alternate name) — integer between 1 and 12
    $short_ipr = '';
    if (!empty($atts['ipr'])) $short_ipr = trim($atts['ipr']);
    if (!empty($atts['irp'])) $short_ipr = trim($atts['irp']);
    if ($short_ipr !== '') {
        if (preg_match('/^[0-9]+$/', $short_ipr)) {
            $ipr_val = intval($short_ipr);
            if ($ipr_val < 1) $ipr_val = 1;
            if ($ipr_val > 12) $ipr_val = 12;
            $images_per_row = $ipr_val;
        }
    }

    // Never fetch items or download URLs server-side. Always render an empty container with folder name;
    // client-side JS will build the appropriate view (proportional or same-size) using AJAX for metadata only.
    $out = '<div class="onedrive-gallery-grid" data-odg-folder="' . esc_attr($atts['folder'] ?? '') . '" data-odg-proportional="' . ($load_images ? '1' : '0') . '" style="--odg-images-per-row:' . $images_per_row . '"></div>';
    return $out;

}

// Enqueue minimal frontend script & styles (small payload). This script runs after page load and kicks off image downloads.
add_action('wp_enqueue_scripts', function() {
    $dir = plugin_dir_url(__FILE__) . '../assets/';
    // Register a small inline script file path (we'll enqueue an inline script to keep footprint tiny)
    wp_register_script('utd-onedrive-frontend-lite', '', array(), null, true);

    // Localize or pass minimal settings if needed
    wp_enqueue_script('utd-onedrive-frontend-lite');

    // Localize AJAX endpoint + nonce for runtime and expose caption settings
    $opt = get_option('utd_onedrive_gallery_settings');
    $load_images_flag = isset($opt['load_images']) ? boolval($opt['load_images']) : false;
    $show_desc = !empty($opt['show_image_description']) ? true : false;
    $use_filename = !empty($opt['use_filename']) ? true : false;
    wp_localize_script('utd-onedrive-frontend-lite', 'UTD_ODG_AJAX', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('utd_onedrive_gallery_load'),
        'load_images' => $load_images_flag,
        'show_image_description' => $show_desc,
        'use_filename' => $use_filename,
    ));

    // Add the runtime that swaps data-odg-src -> src on load and provides a simple lightbox
    $script = <<<'JS'
(function(){
    var mediaList = [];
    var currentIndex = -1;

    function initLightbox(){
        mediaList = Array.from(document.querySelectorAll('.onedrive-gallery-item img, .onedrive-gallery-item video'));
        mediaList.forEach(function(el, idx){
            el.dataset.odgIndex = idx;
            el.style.cursor = 'zoom-in';
            el.removeEventListener('click', el._odgClickHandler);
            var handler = function(e){ e.stopPropagation(); openLightboxAtIndex(idx); };
            el.addEventListener('click', handler);
            el._odgClickHandler = handler;
        });
    }

    // Load exif-js library dynamically (from CDN) and call callback when ready
    function loadExifJs(cb){
        if (window.EXIF) return cb && cb();
        var src = 'https://cdn.jsdelivr.net/npm/exif-js';
        var s = document.querySelector('script[data-odg-exif]');
        if (s){ s.addEventListener('load', function(){ cb && cb(); }); return; }
        s = document.createElement('script'); s.src = src; s.async = true; s.setAttribute('data-odg-exif','1');
        s.onload = function(){ cb && cb(); };
        s.onerror = function(){ console.warn('Failed to load exif-js from CDN'); cb && cb(new Error('load_failed')); };
        document.head.appendChild(s);
    }

    // Simple attribute escaper for safe insertion into HTML attribute strings
    function escAttr(s){
        return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // Fetch image bytes and parse EXIF using exif-js. Shows an alert with the tags.
    function fetchAndShowExif(url){
        if (!url) { return; }
        loadExifJs(function(err){
            if (err){ console.warn('Unable to load EXIF library'); return; }
            // Try to fetch the image bytes (requires CORS on the image host)
            fetch(url, { method: 'GET', mode: 'cors', cache: 'no-store' })
            .then(function(res){ if (!res.ok) throw new Error('Network response not ok: ' + res.status); return res.arrayBuffer(); })
            .then(function(arr){
                try{
                    var tags = EXIF.readFromBinaryFile(arr) || {};
                    // Prefer ImageDescription, then Windows XP comment (XPComment), then UserComment
                    var raw = tags.ImageDescription || tags['ImageDescription'] || tags.XPComment || tags['XPComment'] || tags.UserComment || tags.Comment || tags.Description || null;
                    var value = '';
                    if (raw == null) {
                        value = '';
                    } else if (Array.isArray(raw) || raw instanceof Uint8Array) {
                        // XPComment is often stored as UTF-16LE byte array. Try to decode.
                        try {
                            var u8 = (raw instanceof Uint8Array) ? raw : new Uint8Array(raw);
                            if (typeof TextDecoder !== 'undefined') {
                                value = new TextDecoder('utf-16le').decode(u8);
                                value = value.replace(/\0+$/,'');
                            } else {
                                var chars = [];
                                for (var i=0;i<u8.length;i+=2){
                                    var code = u8[i] | (u8[i+1] << 8);
                                    if (code === 0) break;
                                    chars.push(String.fromCharCode(code));
                                }
                                value = chars.join('');
                            }
                        } catch(e){
                            try { value = String.fromCharCode.apply(null, raw); } catch(e2) { value = ''; }
                        }
                    } else {
                        value = String(raw);
                    }
                    value = value.trim();
                    if (value) {
                        // If the modal caption exists, set it to the EXIF value and show it
                        var modal = document.getElementById('odg-modal');
                        if (modal) {
                            var cap = modal.querySelector('.odg-modal-caption');
                            if (!cap) {
                                cap = document.createElement('div');
                                cap.className = 'odg-modal-caption';
                                modal.appendChild(cap);
                            }
                            cap.textContent = value;
                            cap.style.display = 'block';
                        }
                    }
                }catch(e){
                    console.error('EXIF parse error', e);
                }
            })
            .catch(function(e){
                console.warn('Failed to fetch image bytes for EXIF (CORS may block this):', e);
            });
        });
    }

    // Loader helpers: show a spinner at top of the gallery while items load
    function showLoader(container){
        if (!container) return;
        if (container.querySelector('.odg-loading')) return;
        var loader = document.createElement('div');
        loader.className = 'odg-loading';
        loader.setAttribute('aria-hidden','true');
        loader.innerHTML = '<div class="odg-spinner" aria-hidden="true"></div>';
        container.insertBefore(loader, container.firstChild);
    }
    function hideLoader(container){
        if (!container) return;
        var l = container.querySelector('.odg-loading'); if (l) l.remove();
    }

    // Masonry-like layout: place items into N columns (images_per_row) and stack items to minimize vertical gaps.
    function layoutGalleryMasonry() {
        try {
            var container = document.querySelector('.onedrive-gallery-grid');
            if (!container) return;
            // Only apply masonry when there are image elements (load_images mode)
            var items = Array.from(container.querySelectorAll('.onedrive-gallery-item'));
            if (!items || items.length === 0) return;

            // Determine columns from css variable --odg-images-per-row or fallback to 3
            var cs = getComputedStyle(container);
            var colStr = cs.getPropertyValue('--odg-images-per-row') || '3';
            var cols = parseInt(colStr, 10) || 3;
            if (window.innerWidth <= 600) cols = 1;

            // Try to read gap from CSS (flex gap) if available, otherwise fallback to 8px
            var gapCSS = parseFloat(cs.getPropertyValue('gap')) || parseFloat(cs.getPropertyValue('--gap')) || NaN;
            var gap = !isNaN(gapCSS) ? Math.round(gapCSS) : 8;
            var containerWidth = container.clientWidth;
            var colWidth = Math.floor((containerWidth - gap * (cols - 1)) / cols);

            // prepare container for absolute layout
            container.style.position = 'relative';

            // initialize column heights
            var colHeights = [];
            for (var i = 0; i < cols; i++) colHeights[i] = 0;

            // position each item into shortest column
            items.forEach(function(item){
                // ensure the item content has its intrinsic height measured
                item.style.width = colWidth + 'px';
                item.style.position = 'absolute';
                item.style.boxSizing = 'border-box';

                // If image aspect is provided in data attributes, set aspect-ratio on the inner img/placeholder
                var imgEl = item.querySelector('img');
                if (imgEl && imgEl.dataset && imgEl.dataset.odgWidth && imgEl.dataset.odgHeight) {
                    imgEl.style.aspectRatio = imgEl.dataset.odgWidth + ' / ' + imgEl.dataset.odgHeight;
                }

                // compute height (including margins/padding)
                var h = item.offsetHeight;

                // find shortest column
                var minCol = 0; var minH = colHeights[0];
                for (var ci = 1; ci < cols; ci++){
                    if (colHeights[ci] < minH){ minH = colHeights[ci]; minCol = ci; }
                }

                var left = (colWidth + gap) * minCol;
                var top = minH;
                item.style.transform = 'translate(' + left + 'px,' + top + 'px)';

                // update column height
                colHeights[minCol] = minH + h + gap;
            });

            // set container height to tallest column
            var maxH = Math.max.apply(null, colHeights);
            container.style.height = (maxH > 0 ? maxH + 'px' : 'auto');
        } catch (e) { console.error('UTD layout error', e); }
    }

    function showMediaAt(index){
        if (!mediaList || mediaList.length === 0) return;
        index = (index + mediaList.length) % mediaList.length;
        currentIndex = index;
        var modal = document.getElementById('odg-modal');
        if (!modal) return;
        var container = modal.querySelector('.odg-modal-content');
        container.innerHTML = '';
        var mediaEl = mediaList[currentIndex];
        if (!mediaEl) return;
        var tag = mediaEl.tagName.toLowerCase();
        // Always use real image URL for modal, even if gallery image src is a placeholder
        var realUrl = mediaEl.getAttribute('data-odg-url') || mediaEl.src || mediaEl.currentSrc;
        // Use the current source of the image (which is the scaled down original) as the initial source
        var thumbUrl = mediaEl.currentSrc || mediaEl.src || realUrl;
        // If it's a data URI (placeholder), skip it and use realUrl
        if (thumbUrl && thumbUrl.indexOf('data:') === 0) thumbUrl = realUrl;

        if (tag === 'img'){
            var img = document.createElement('img');
            img.alt = mediaEl.alt || '';
            try{ img.crossOrigin = 'anonymous'; } catch(e){}
            
            // Show the grid image first
            // CSS handles fitting to viewport while maintaining aspect ratio
            img.onload = function(){
                // No JS sizing needed - CSS max-width/max-height handles it
            };
            img.src = thumbUrl;
            
            // If the grid image is different from the real URL (e.g. if we ever use thumbnails), swap it
            if (realUrl && realUrl !== thumbUrl) {
                var orig = new window.Image();
                orig.onload = function(){ img.src = realUrl; };
                orig.src = realUrl;
            }
            container.appendChild(img);
        } else {
            var vid = document.createElement('video');
            vid.controls = true; vid.autoplay = true; vid.muted = true;
            vid.src = realUrl;
            container.appendChild(vid);
            vid.load();
        }
        // caption (lightbox bottom bar) - controlled by settings
        var title = mediaEl.getAttribute('data-odg-title') || '';
        var captionText = '';
        if (typeof UTD_ODG_AJAX !== 'undefined' && UTD_ODG_AJAX.show_image_description) {
            if (UTD_ODG_AJAX.use_filename) {
                // prefer data-odg-name (set from server) or fallback to filename from URL
                captionText = mediaEl.getAttribute('data-odg-name') || '';
                if (!captionText) {
                    var fn = (mediaEl.getAttribute('data-odg-url') || mediaEl.src || '').split('/').pop() || '';
                    captionText = fn.replace(/\.[^/.]+$/, '');
                } else {
                    // strip extension if present on server-provided name
                    captionText = String(captionText).replace(/\.[^/.]+$/, '');
                }
            } else {
                // When not using filename as caption, only use an explicit title (do not fall back to server filename)
                captionText = title || '';
            }
        }

        var cap = modal.querySelector('.odg-modal-caption');
        if (!cap) {
            cap = document.createElement('div');
            cap.className = 'odg-modal-caption';
            modal.appendChild(cap);
        }
        if (captionText) { cap.textContent = captionText; cap.style.display = 'block'; }
        else { cap.style.display = 'none'; }
        modal.classList.add('open');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        // Attempt to read EXIF directly from the image bytes (client-side). This requires the image host
        // to allow CORS. If CORS blocks the request, the code will alert the user with remediation steps.
        if (tag === 'img') {
            // prefer realUrl (full download URL) when available
            var exifUrl = realUrl || thumbUrl;
            // Only attempt client-side EXIF read if captions are enabled
            try {
                if (typeof UTD_ODG_AJAX !== 'undefined' && UTD_ODG_AJAX.show_image_description) {
                    fetchAndShowExif(exifUrl);
                }
            } catch(e){ console.error('EXIF fetch call failed', e); }
        }
    }

    function openLightboxAtIndex(idx){ showMediaAt(idx); }
    function closeLightbox(){
        var modal = document.getElementById('odg-modal');
        if (!modal) return;
        modal.classList.remove('open');
        modal.style.display = 'none';
        document.body.style.overflow='';
        var c = modal.querySelector('.odg-modal-content');
        if (c) c.innerHTML = '';
    }

    // wire modal controls
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.odg-modal-close'); if (btn){ e.stopPropagation(); closeLightbox(); return; }
        var prev = e.target.closest('.odg-modal-prev'); if (prev){ e.stopPropagation(); showMediaAt(currentIndex - 1); return; }
        var next = e.target.closest('.odg-modal-next'); if (next){ e.stopPropagation(); showMediaAt(currentIndex + 1); return; }
        var modal = e.target.closest('#odg-modal'); if (modal && modal.classList.contains('open') && !e.target.closest('.odg-modal-inner')) closeLightbox();
    });
    // Click handler for play overlay: open the lightbox for the underlying media
    document.addEventListener('click', function(e){
        var play = e.target.closest('.odg-play');
        if (!play) return;
        e.stopPropagation();
        var item = play.closest('.onedrive-gallery-item');
        if (!item) return;
        var media = item.querySelector('img[data-odg-id], video[data-odg-id]');
        if (!media) return;
        // If media has odgIndex assigned, use that to open lightbox at the right position
        var idx = media.dataset.odgIndex;
        if (typeof idx !== 'undefined' && idx !== null && idx !== '') {
            openLightboxAtIndex(parseInt(idx, 10));
        } else {
            // fallback: trigger click on media element
            media.click();
        }
    });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape'){ closeLightbox(); } if (e.key === 'ArrowLeft'){ showMediaAt(currentIndex - 1); } if (e.key === 'ArrowRight'){ showMediaAt(currentIndex + 1); } });

    function startLoading(){
        try{
            // Ensure UTD_ODG_AJAX is available
            if (typeof UTD_ODG_AJAX === 'undefined') {
                console.error('UTD_ODG_AJAX not defined');
                return;
            }
            
            // Check for containers that need to be built by JS (have data-odg-folder)
            var containersToBuild = Array.from(document.querySelectorAll('.onedrive-gallery-grid[data-odg-folder]'));
            containersToBuild.forEach(function(container){
                if (container.dataset.odgBuilt === '1') return; // already built
                var folder = container.getAttribute('data-odg-folder') || '';
                var isProportional = container.getAttribute('data-odg-proportional') === '1';
                if (!folder) return;
                
                // Mark as being built to prevent duplicate processing
                container.dataset.odgBuilt = '1';
                
                // Fetch folder IDs from server (metadata only, no image binaries)
                var fdIds = new FormData();
                fdIds.append('action', 'utd_onedrive_get_folder_ids');
                fdIds.append('nonce', UTD_ODG_AJAX.nonce);
                fdIds.append('folder', folder);
                fetch(UTD_ODG_AJAX.ajax_url, { method: 'POST', body: fdIds, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(json){
                        console.log('Folder IDs response:', json);
                        try{ if (json && json.data && json.data.LOGINFO) console.info('LOGINFO folder_ids', json.data.LOGINFO); }catch(e){}
                        if (!(json && json.success && json.data && Array.isArray(json.data.ids))) {
                            console.error('Invalid response from folder IDs endpoint:', json);
                            return;
                        }
                        var idsMeta = json.data.ids; // [{id, w?, h?}, ...]
                        console.log('Retrieved ' + idsMeta.length + ' items for folder:', folder);
                        
                        if (isProportional) {
                            // Build proportional view: create items with data-odg-id and optional dimensions
                            var html = '';
                            idsMeta.forEach(function(m){
                                var type = m.type || 'image';
                                var src = (m.url && m.url.length) ? m.url : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
                                if (type === 'video') {
                                    html += '<div class="onedrive-gallery-item" data-type="video">';
                                    html += '<video muted playsinline preload="metadata" loop data-odg-id="' + (m.id||'') + '"';
                                    if (m.url && m.url.length) html += ' data-odg-url="' + m.url + '"';
                                    if (m.name) html += ' data-odg-name="' + escAttr(m.name) + '"';
                                    if (m.w && m.h) html += ' data-odg-width="'+m.w+'" data-odg-height="'+m.h+'"';
                                    html += '><source src="' + src + '" type="video/mp4"></video>';
                                    // Play overlay (non-interactive so clicks pass through to video)
                                    html += '<div class="odg-play" aria-hidden="true"></div>';
                                    html += '</div>';
                                } else {
                                    html += '<div class="onedrive-gallery-item" data-type="image">';
                                    html += '<img src="' + src + '" data-odg-id="' + (m.id||'') + '"';
                                    if (m.w && m.h) html += ' data-odg-width="'+m.w+'" data-odg-height="'+m.h+'"';
                                    if (m.url && m.url.length) html += ' data-odg-url="' + m.url + '"';
                                    if (m.name) html += ' data-odg-name="' + escAttr(m.name) + '"';
                                    html += ' loading="lazy">';
                                    html += '</div>';
                                }
                            });
                            container.innerHTML = html;
                            
                            // Show loader and use counters to detect when all images finished loading
                            showLoader(container);
                            var mediaEls = Array.from(container.querySelectorAll('img[data-odg-id], video[data-odg-id]'));
                            var total = mediaEls.length; var loaded = 0;
                            function markLoaded(el){
                                loaded++;
                                layoutGalleryMasonry();
                                if (loaded >= total) {
                                    hideLoader(container);
                                    initLightbox();
                                }
                            }
                            if (total === 0) { hideLoader(container); initLightbox(); }
                            mediaEls.forEach(function(el){
                                if (el.tagName.toLowerCase() === 'img') {
                                    if (el.complete) { markLoaded(el); }
                                    else { el.addEventListener('load', function(){ markLoaded(el); }); el.addEventListener('error', function(){ markLoaded(el); }); }
                                } else if (el.tagName.toLowerCase() === 'video') {
                                    // reveal play overlay when metadata/frame is available
                                    var overlay = el.parentElement ? el.parentElement.querySelector('.odg-play') : null;
                                    if (el.readyState && el.readyState > 0) {
                                        if (overlay) overlay.classList.add('visible');
                                        markLoaded(el);
                                    } else {
                                        el.addEventListener('loadedmetadata', function(){ if (overlay) overlay.classList.add('visible'); markLoaded(el); });
                                        el.addEventListener('error', function(){ markLoaded(el); });
                                    }
                                } else { markLoaded(el); }
                            });
                            // Ensure layout is run shortly in case images are cached and complete already
                            setTimeout(layoutGalleryMasonry, 50);
                        } else {
                            // Build same-size view: 4:3 ratio boxes, images fill width and crop overflow
                            var html = '';
                            idsMeta.forEach(function(m){
                                var type = m.type || 'image';
                                var src = (m.url && m.url.length) ? m.url : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
                                if (type === 'video') {
                                    html += '<div class="onedrive-gallery-item odg-fixed-ratio" data-type="video">';
                                    html += '<video muted playsinline preload="metadata" loop data-odg-id="' + (m.id||'') + '"';
                                    if (m.url && m.url.length) html += ' data-odg-url="' + m.url + '"';
                                    if (m.name) html += ' data-odg-name="' + escAttr(m.name) + '"';
                                    if (m.w && m.h) html += ' data-odg-width="'+m.w+'" data-odg-height="'+m.h+'"';
                                    html += '><source src="' + src + '" type="video/mp4"></video>';
                                    // Play overlay (non-interactive so clicks pass through to video)
                                    html += '<div class="odg-play" aria-hidden="true"></div>';
                                    html += '</div>';
                                } else {
                                    html += '<div class="onedrive-gallery-item odg-fixed-ratio" data-type="image">';
                                    html += '<img src="' + src + '" data-odg-id="' + (m.id||'') + '"';
                                    if (m.w && m.h) html += ' data-odg-width="'+m.w+'" data-odg-height="'+m.h+'"';
                                    if (m.url && m.url.length) html += ' data-odg-url="' + m.url + '"';
                                    if (m.name) html += ' data-odg-name="' + escAttr(m.name) + '"';
                                    html += ' loading="lazy">';
                                    html += '</div>';
                                }
                            });
                            container.innerHTML = html;
                            
                            // For fixed-ratio view, show loader until all images load then init lightbox
                            showLoader(container);
                            var mediaFix = Array.from(container.querySelectorAll('img[data-odg-id], video[data-odg-id]'));
                            var totalFix = mediaFix.length; var loadedFix = 0;
                            function markLoadedFix(el){ if (el.tagName && el.tagName.toLowerCase() === 'img') el.classList.add('odg-loaded'); loadedFix++; if (loadedFix >= totalFix){ hideLoader(container); initLightbox(); } }
                            if (totalFix === 0) { hideLoader(container); initLightbox(); }
                            mediaFix.forEach(function(el){
                                var tag = (el.tagName || '').toLowerCase();
                                if (tag === 'img') {
                                    if (el.complete) { markLoadedFix(el); }
                                    else { el.addEventListener('load', function(){ markLoadedFix(el); }); el.addEventListener('error', function(){ markLoadedFix(el); }); }
                                } else if (tag === 'video') {
                                    var overlay = el.parentElement ? el.parentElement.querySelector('.odg-play') : null;
                                    if (el.readyState && el.readyState > 0) { if (overlay) overlay.classList.add('visible'); markLoadedFix(el); }
                                    else { el.addEventListener('loadedmetadata', function(){ if (overlay) overlay.classList.add('visible'); markLoadedFix(el); }); el.addEventListener('error', function(){ markLoadedFix(el); }); }
                                } else {
                                    markLoadedFix(el);
                                }
                            });
                        }
                    })
                    .catch(function(e){ console.error('folder ids fetch failed', e); });
            });
        }catch(e){ console.error('UTD frontend load error', e); }
    }

    if (document.readyState === 'complete') startLoading(); else { window.addEventListener('load', startLoading); if ('requestIdleCallback' in window) requestIdleCallback(startLoading); }

    // Re-layout on resize (debounced)
    var _odg_resizeTimer = null;
    window.addEventListener('resize', function(){ clearTimeout(_odg_resizeTimer); _odg_resizeTimer = setTimeout(function(){ layoutGalleryMasonry(); }, 150); });

})();
JS;
    wp_add_inline_script('utd-onedrive-frontend-lite', $script);

    // No debug info displayed on frontend.

    // Minimal styles (you can expand or keep them inlined elsewhere)
    wp_register_style('utd-onedrive-frontend-style', false);
    wp_enqueue_style('utd-onedrive-frontend-style');

    // Gallery CSS - unified for all views, works across all browsers including Edge/tablets/phones
    $css = ".onedrive-gallery-grid{display:flex;flex-wrap:wrap;gap:8px;}" .
        ".onedrive-gallery-item{box-sizing:border-box;flex:0 0 calc((100% / var(--odg-images-per-row)) - 8px);max-width:calc((100% / var(--odg-images-per-row)) - 8px);padding:4px;}" .
        ".onedrive-gallery-item img, .onedrive-gallery-item video{width:100%;height:auto;display:block;border-radius:4px;object-fit:cover;}" .
        ".onedrive-gallery-item[data-type=video]{cursor:pointer;}" .
        "@media (max-width:600px){ .onedrive-gallery-item{flex:0 0 100%;max-width:100%;} }\n" .
        "/* Fixed 4:3 ratio boxes for same-size view */\n" .
        ".onedrive-gallery-item.odg-fixed-ratio{position:relative;overflow:hidden;aspect-ratio:4/3;}" .
        ".onedrive-gallery-item.odg-fixed-ratio img, .onedrive-gallery-item.odg-fixed-ratio video{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;object-position:center;}\n";

    // Play overlay for video thumbnails
    $css .= ".odg-play{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:64px;height:64px;border-radius:50%;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:2;pointer-events:auto;cursor:pointer;opacity:0;transition:opacity 160ms ease;}" .
        ".odg-play.visible{opacity:1;}" .
        ".odg-play:before{content:'';display:block;width:0;height:0;border-left:18px solid #fff;border-top:12px solid transparent;border-bottom:12px solid transparent;margin-left:4px;}";

    // Loading spinner styles
    $css .= "\n/* Loader shown while gallery images download */\n" .
        ".odg-loading{width:100%;display:flex;justify-content:center;align-items:center;padding:8px 0;box-sizing:border-box;}\n" .
        ".odg-spinner{width:28px;height:28px;border:4px solid rgba(255,255,255,0.18);border-top-color:#ffffff;border-radius:50%;animation:odg-spin 1s linear infinite;}\n" .
        "@keyframes odg-spin{to{transform:rotate(360deg);}}\n";

    // Modal control styles: black circular buttons fixed to viewport edges
    $css .= "\n/* Lightbox controls - fixed to viewport edges */\n" .
        ".odg-modal .odg-modal-close, .odg-modal .odg-modal-nav {" .
            "position:fixed;width:44px;height:44px;border-radius:50%;background:rgba(0,0,0,0.9);color:#fff;border:none;display:flex;align-items:center;justify-content:center;font-size:20px;line-height:1;box-shadow:0 4px 12px rgba(0,0,0,0.6);cursor:pointer;transition:background 120ms ease;z-index:10001;" .
        "}\n" .
        ".odg-modal .odg-modal-close:focus, .odg-modal .odg-modal-nav:focus{outline:2px solid rgba(255,255,255,0.15);}\n" .
        ".odg-modal .odg-modal-close{top:12px;right:12px;font-size:22px;}\n" .
        ".odg-modal .odg-modal-prev{left:12px;top:50%;transform:translateY(-50%);font-size:22px;}\n" .
        ".odg-modal .odg-modal-next{right:12px;top:50%;transform:translateY(-50%);font-size:22px;}\n" .
        ".odg-modal .odg-modal-close:hover, .odg-modal .odg-modal-nav:hover{background:rgba(0,0,0,1);}\n";

    wp_add_inline_style('utd-onedrive-frontend-style', $css);

    // Ensure modal content images/videos fit viewport without scrolling
    $fitCss = "\n/* Lightbox: fit image to viewport, no scroll, preserve ratio */\n" .
        ".odg-modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.95); }\n" .
        ".odg-modal.open { display: flex; align-items: center; justify-content: center; }\n" .
        ".odg-modal .odg-modal-inner { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }\n" .
        ".odg-modal .odg-modal-content { display: flex; align-items: center; justify-content: center; position:relative; }\n" .
        ".odg-modal .odg-modal-content img, .odg-modal .odg-modal-content video { max-width: 95vw; max-height: 95vh; width: auto; height: auto; display: block; object-fit: contain; }\n" .
        "/* Lightbox caption bar (bottom-centered, semi-transparent) */\n" .
        ".odg-modal-caption{position:fixed;left:50%;transform:translateX(-50%);bottom:24px;background:rgba(0,0,0,0.5);color:#fff;padding:8px 14px;border-radius:6px;max-width:85vw;box-sizing:border-box;text-align:center;z-index:10002;font-size:15px;}\n";
    wp_add_inline_style('utd-onedrive-frontend-style', $fitCss);
});

// Output modal HTML in footer so it exists for the lightbox
add_action('wp_footer', function(){
    echo "<div id='odg-modal' class='odg-modal' aria-hidden='true'>\n";
    echo "<button class='odg-modal-close' aria-label='Close'>×</button>\n";
    echo "<button class='odg-modal-nav odg-modal-prev' aria-label='Prev'>&lsaquo;</button>\n";
    echo "<button class='odg-modal-nav odg-modal-next' aria-label='Next'>&rsaquo;</button>\n";
    echo "<div class='odg-modal-inner'>\n";
    echo "<div class='odg-modal-content'></div>\n";
    echo "</div>\n</div>\n";
});

// (batch download-URLs endpoint removed — download URLs are returned with the children listing)


    // AJAX handler: return the number of image/video children in the named folder (no image data)
    add_action('wp_ajax_utd_onedrive_get_folder_count', 'utd_onedrive_gallery_ajax_get_folder_count');
    add_action('wp_ajax_nopriv_utd_onedrive_get_folder_count', 'utd_onedrive_gallery_ajax_get_folder_count');
    function utd_onedrive_gallery_ajax_get_folder_count() {
        // Reset the global refresh flag for this new AJAX request
        $GLOBALS['utd_onedrive_graph_refresh_attempted'] = false;
        
        if (empty($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'utd_onedrive_gallery_load')) {
            wp_send_json_error('invalid_nonce', 403);
        }
        $folder = sanitize_text_field(wp_unslash($_POST['folder'] ?? ''));
        if (empty($folder)) {
            wp_send_json_success(array('count' => 0));
        }

        // Find folder in root and count image/video children. This only retrieves metadata (no file content).
        $base = '/me/drive';
        $start = microtime(true);
        $graph_calls = 0;
        $graph_ms = 0.0;
        $http_calls = 0;
        $http_ms = 0.0;
        $start = microtime(true);
        
        // Check if folder contains path separators (nested folders)
        if (strpos($folder, '/') !== false || strpos($folder, '\\') !== false) {
            // Handle path-based addressing: /me/drive/root:/path/to/folder:/children
            $folder = str_replace('\\', '/', $folder);
            $parts = array_filter(array_map('trim', explode('/', $folder)));
            if (empty($parts)) {
                wp_send_json_success(array('count' => 0));
                return;
            }
            $encoded = implode('/', array_map('rawurlencode', $parts));
            $t0 = microtime(true);
                // Request children and include downloadUrl + metadata in a single call
                $children = utd_onedrive_graph_api_get($base . '/root:/' . $encoded . ':/children?$select=@microsoft.graph.downloadUrl,file,image,photo,id,name');
            $graph_ms += (microtime(true) - $t0) * 1000.0; $graph_calls++;
            if (is_wp_error($children)) {
                wp_send_json_error(array(
                    'error' => 'graph_children_error',
                    'message' => $children->get_error_message(),
                    'code' => $children->get_error_code()
                ));
                return;
            }
        } else {
            // Single folder name - search in root
            $t0 = microtime(true);
                $resp = utd_onedrive_graph_api_get($base . '/root/children');
            $graph_ms += (microtime(true) - $t0) * 1000.0; $graph_calls++;
            if (is_wp_error($resp)) {
                wp_send_json_error(array(
                    'error' => 'graph_error',
                    'message' => $resp->get_error_message(),
                    'code' => $resp->get_error_code()
                ));
                return;
            }

            $folderItem = null;
            foreach ($resp['value'] as $child) {
                if (!empty($child['folder']) && strcasecmp($child['name'], $folder) === 0) {
                    $folderItem = $child;
                    break;
                }
            }
            
            if (empty($folderItem)) {
                wp_send_json_success(array('count' => 0));
            }

            $t0 = microtime(true);
                // Request children with download URLs and metadata in a single call
                $children = utd_onedrive_graph_api_get($base . '/items/' . $folderItem['id'] . '/children?$select=@microsoft.graph.downloadUrl,file,image,photo,id,name');
            $graph_ms += (microtime(true) - $t0) * 1000.0; $graph_calls++;
            if (is_wp_error($children)) {
                wp_send_json_error(array(
                    'error' => 'graph_children_error',
                    'message' => $children->get_error_message(),
                    'code' => $children->get_error_code()
                ));
                return;
            }
        }

        $count = 0;
        foreach ($children['value'] as $c) {
            if (!empty($c['folder'])) continue;
            $mime = $c['file']['mimeType'] ?? '';
            if (stripos($mime, 'image/') === 0 || stripos($mime, 'video/') === 0) $count++;
        }
        wp_send_json_success(array('count' => $count));
    }


    // AJAX handler: return ordered list of media item IDs (and optional w/h) in the named folder
    add_action('wp_ajax_utd_onedrive_get_folder_ids', 'utd_onedrive_gallery_ajax_get_folder_ids');
    add_action('wp_ajax_nopriv_utd_onedrive_get_folder_ids', 'utd_onedrive_gallery_ajax_get_folder_ids');
    function utd_onedrive_gallery_ajax_get_folder_ids() {
        // Reset the global refresh flag for this new AJAX request
        $GLOBALS['utd_onedrive_graph_refresh_attempted'] = false;
        
        if (empty($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'utd_onedrive_gallery_load')) {
            wp_send_json_error('invalid_nonce', 403);
        }
        $folder = sanitize_text_field(wp_unslash($_POST['folder'] ?? ''));
        if (empty($folder)) {
            wp_send_json_success(array('ids' => array()));
        }

        $base = '/me/drive';
        
        // Check if folder contains path separators (nested folders)
        if (strpos($folder, '/') !== false || strpos($folder, '\\') !== false) {
            // Handle path-based addressing: /me/drive/root:/path/to/folder:/children
            $folder = str_replace('\\', '/', $folder);
            $parts = array_filter(array_map('trim', explode('/', $folder)));
            if (empty($parts)) {
                wp_send_json_error(array('error' => 'invalid_path', 'message' => 'Invalid folder path'));
                return;
            }
            $encoded = implode('/', array_map('rawurlencode', $parts));
            $children = utd_onedrive_graph_api_get($base . '/root:/' . $encoded . ':/children');
            if (is_wp_error($children)) {
                wp_send_json_error(array(
                    'error' => 'graph_children_error',
                    'message' => $children->get_error_message(),
                    'code' => $children->get_error_code()
                ));
                return;
            }
        } else {
            // Single folder name - search in root
            $resp = utd_onedrive_graph_api_get($base . '/root/children');
            if (is_wp_error($resp)) {
                wp_send_json_error(array(
                    'error' => 'graph_error',
                    'message' => $resp->get_error_message(),
                    'code' => $resp->get_error_code()
                ));
                return;
            }

            $folderItem = null;
            foreach ($resp['value'] as $child) {
                if (!empty($child['folder']) && strcasecmp($child['name'], $folder) === 0) {
                    $folderItem = $child;
                    break;
                }
            }
            
            if (empty($folderItem)) {
                wp_send_json_error(array(
                    'error' => 'folder_not_found',
                    'message' => "Folder '{$folder}' not found in OneDrive root. Please ensure the folder exists and is in the root directory."
                ));
                return;
            }

            $t0 = microtime(true);
            $children = utd_onedrive_graph_api_get($base . '/items/' . $folderItem['id'] . '/children?$select=@microsoft.graph.downloadUrl,file,image,photo,id,name');
            $graph_ms += (microtime(true) - $t0) * 1000.0; $graph_calls++;
            if (is_wp_error($children)) {
                wp_send_json_error(array(
                    'error' => 'graph_children_error',
                    'message' => $children->get_error_message(),
                    'code' => $children->get_error_code()
                ));
                return;
            }
        }

        $ids = array();
        foreach ($children['value'] as $c) {
            if (!empty($c['folder'])) continue;
            $mime = $c['file']['mimeType'] ?? '';
                if (stripos($mime, 'image/') === 0 || stripos($mime, 'video/') === 0) {
                $meta = array('id' => $c['id']);
                    // Determine media type
                    if (stripos($mime, 'image/') === 0) $meta['type'] = 'image';
                    elseif (stripos($mime, 'video/') === 0) $meta['type'] = 'video';
                if (!empty($c['name'])) $meta['name'] = sanitize_text_field($c['name']);
                if (!empty($c['image'])) { if (isset($c['image']['width'])) $meta['w'] = intval($c['image']['width']); if (isset($c['image']['height'])) $meta['h'] = intval($c['image']['height']); }
                if (!empty($c['photo'])) { if (isset($c['photo']['width'])) $meta['w'] = intval($c['photo']['width']); if (isset($c['photo']['height'])) $meta['h'] = intval($c['photo']['height']); }

                // Prefer the download URL returned with the children listing. Only fall back to an HTTP probe if absent.
                if (!empty($c['@microsoft.graph.downloadUrl'])) {
                    $meta['url'] = esc_url_raw($c['@microsoft.graph.downloadUrl']);
                } else {
                    $http_t0 = microtime(true);
                    $content_resp = wp_remote_get('https://graph.microsoft.com/v1.0/me/drive/items/' . rawurlencode($c['id']) . '/content', array('redirection' => 0, 'timeout' => 15));
                    $http_ms += (microtime(true) - $http_t0) * 1000.0; $http_calls++;
                    if (!is_wp_error($content_resp)) {
                        $loc = wp_remote_retrieve_header($content_resp, 'location') ?: '';
                        if ($loc) $meta['url'] = esc_url_raw($loc);
                    }
                }

                $ids[] = $meta;
            }
        }
                $duration_ms = round((microtime(true) - $start) * 1000, 2);
                $payload = array('ids' => $ids, 'LOGINFO' => array(
                    'duration_ms' => $duration_ms,
                    'count' => count($ids),
                    'graph_calls' => $graph_calls,
                    'graph_ms' => round($graph_ms,2),
                    'http_calls' => $http_calls,
                    'http_ms' => round($http_ms,2)
                ));
                wp_send_json_success($payload);
    }

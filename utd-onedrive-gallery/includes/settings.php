<?php
// Settings UI for UTD OneDrive Gallery - loaded only in admin

// Add admin menu for OneDrive settings
add_action('admin_menu', 'utd_onedrive_gallery_add_admin_menu');
function utd_onedrive_gallery_add_admin_menu() {
    add_options_page(
        'OneDrive Gallery Settings',
        'OneDrive Gallery',
        'manage_options',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_settings_page'
    );
}

// Shortcode documentation for [onedrive_gallery]
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'settings_page_utd-onedrive-gallery') {
        echo '<div class="notice notice-info" style="margin-bottom:16px;padding:16px;">'
            . '<h2>Shortcode Usage: <code>[onedrive_gallery]</code></h2>'
            . '<p><strong>Basic usage:</strong> <code>[onedrive_gallery folder="FOLDERNAME"]</code></p>'
            . '<p><strong>Options:</strong></p>'
            . '<ul>'
            . '<li><code>folder</code>: The OneDrive folder name or path to display.</li>'
            . '<li><code>prop</code>: <code>true</code> or <code>false</code>. If set, overrides the default proportional view setting. <br>'
            . 'When <code>prop="true"</code>, gallery shows proportional view. When <code>prop="false"</code>, gallery uses same-size thumbnails.</li>'
            . '<li><code>ipr</code>: Integer from <code>1</code> to <code>8</code>. Overrides the global "Images Per Row" setting for this shortcode instance. Example: <code>ipr="4"</code>.</li>'
            . '<li><code>show_caption</code>: Optional <code>true</code> or <code>false</code>. When provided on the shortcode or block instance, this overrides the global "Show Image Description" setting for that gallery only. Example: <code>show_caption="false"</code>.</li>'
            . '<li><code>caption_source</code>: Optional caption source for the gallery instance. Allowed values: <code>EXIF</code>, <code>FILENAME</code>, <code>JSON</code>, or <code>NONE</code>. When provided it overrides the site default for that gallery. Examples: <code>caption_source="FILENAME"</code> or <code>caption_source="NONE"</code>.</li>'
            . '</ul>'
            . '<p><strong>Examples:</strong></p>'
            . '<ul>'
            . '<li><code>[onedrive_gallery folder="Photos" prop="false"]</code> — basic example.</li>'
            . '<li><code>[onedrive_gallery folder="Photos/2025" show_caption="true" caption_source="EXIF"]</code> — forces captions on and uses EXIF for that gallery only.</li>'
            . '<li><code>[onedrive_gallery folder="Docs" show_caption="false" caption_source="NONE"]</code> — disables captions for this gallery instance.</li>'
            . '</ul>'
            . '</div>';
    }
});

// Register settings
add_action('admin_init', 'utd_onedrive_gallery_settings_init');
function utd_onedrive_gallery_settings_init() {
    register_setting('utd-onedrive-gallery', 'utd_onedrive_gallery_settings', array(
        'sanitize_callback' => 'utd_onedrive_gallery_settings_sanitize'
    ));

    // Authentication tab/section
    add_settings_section(
        'utd_onedrive_gallery_section_auth',
        __('Authentication', 'utd-onedrive-gallery'),
        function() { echo '<p>Configure your OneDrive connection below.</p>'; },
        'utd-onedrive-gallery'
    );

    // OAuth client settings and auth fields
    add_settings_field(
        'utd_onedrive_client_id',
        __('Microsoft App Client ID', 'utd-onedrive-gallery'),
        'utd_onedrive_gallery_client_id_render',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_section_auth'
    );
    add_settings_field(
        'utd_onedrive_tenant',
        __('Tenant (use "consumers" for personal OneDrive)', 'utd-onedrive-gallery'),
        'utd_onedrive_gallery_tenant_render',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_section_auth'
    );
    add_settings_field(
        'utd_onedrive_manual_refresh',
        __('Refresh Token (for non-interactive authentication)', 'utd-onedrive-gallery'),
        'utd_onedrive_gallery_manual_refresh_render',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_section_auth'
    );

    // General settings tab/section
    add_settings_section(
        'utd_onedrive_gallery_section_general',
        __('General Settings', 'utd-onedrive-gallery'),
        function() { echo '<p>General display and behavior settings.</p>'; },
        'utd-onedrive-gallery'
    );

    add_settings_field(
        'utd_onedrive_images_per_row',
        __('Images Per Row', 'utd-onedrive-gallery'),
        'utd_onedrive_gallery_images_per_row_render',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_section_general'
    );
    add_settings_field(
        'utd_onedrive_show_image_description',
        __('Show Image Description (caption)', 'utd-onedrive-gallery'),
        'utd_onedrive_gallery_show_description_render',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_section_general'
    );

    add_settings_field(
        'utd_onedrive_caption_source',
        __('Caption source', 'utd-onedrive-gallery'),
        'utd_onedrive_gallery_caption_source_render',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_section_general'
    );

    add_settings_field(
        'utd_onedrive_exif_code',
        __('EXIF code / key', 'utd-onedrive-gallery'),
        'utd_onedrive_gallery_exif_code_render',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_section_general'
    );

    // New general setting: load images
    add_settings_field(
        'utd_onedrive_load_images',
        __('Proportional gallery view', 'utd-onedrive-gallery'),
        'utd_onedrive_gallery_load_images_render',
        'utd-onedrive-gallery',
        'utd_onedrive_gallery_section_general'
    );
}

function utd_onedrive_gallery_client_id_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    ?>
    <input type='text' name='utd_onedrive_gallery_settings[client_id]' value='<?php echo esc_attr($options['client_id'] ?? ''); ?>' style='width: 420px;'>
    <p class='description'>Enter your Microsoft App (Azure) Client ID.</p>
    <?php
}

function utd_onedrive_gallery_show_description_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    $val = !empty($options['show_image_description']);
    ?>
    <label><input type='checkbox' name='utd_onedrive_gallery_settings[show_image_description]' value='1' <?php checked($val, true); ?> /> <?php esc_html_e('Show the caption bar in fullscreen (EXIF ImageDescription)', 'utd-onedrive-gallery'); ?></label>
    <p class='description'>If unchecked, no caption bar will be shown in fullscreen mode.</p>
    <?php
}

function utd_onedrive_gallery_use_filename_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    $val = !empty($options['use_filename']);
    ?>
    <label><input type='checkbox' name='utd_onedrive_gallery_settings[use_filename]' value='1' <?php checked($val, true); ?> /> <?php esc_html_e('Use filename as caption (without extension)', 'utd-onedrive-gallery'); ?></label>
    <p class='description'>When enabled, the filename (with extension removed) will be used if no other caption source is available.</p>
    <?php
}

function utd_onedrive_gallery_caption_source_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    $val = strtoupper(trim($options['caption_source'] ?? 'EXIF'));
    $opts = array('EXIF' => 'EXIF', 'FILENAME' => 'Filename', 'JSON' => 'JSON (metadata)', 'NONE' => 'None');
    ?>
    <select name='utd_onedrive_gallery_settings[caption_source]'>
        <?php foreach ($opts as $k => $label) : ?>
            <option value='<?php echo esc_attr($k); ?>' <?php selected($val, $k); ?>><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
    </select>
    <p class='description'>Choose the source for the lightbox caption bar when <em>Show Image Description</em> is enabled. Selecting <strong>None</strong> disables captions regardless of the global setting.</p>
    <?php
}

function utd_onedrive_gallery_exif_code_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    $val = $options['exif_code'] ?? 'XPTitle';
    ?>
    <input type='text' name='utd_onedrive_gallery_settings[exif_code]' value='<?php echo esc_attr($val); ?>' style='width:220px;' />
    <p class='description'>EXIF tag key to prefer when reading EXIF captions (default: <code>XPTitle</code>). Useful when your camera or processing tool stores captions in custom EXIF fields.</p>
    <?php
}

function utd_onedrive_gallery_images_per_row_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    $val = intval($options['images_per_row'] ?? 3);
    ?>
    <input type='number' min='1' max='12' name='utd_onedrive_gallery_settings[images_per_row]' value='<?php echo esc_attr($val); ?>' style='width:80px;'>
    <p class='description'>Number of images to display per row in the gallery. Default: 3</p>
    <?php
}

function utd_onedrive_gallery_tenant_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    ?>
    <input type='text' name='utd_onedrive_gallery_settings[tenant]' value='<?php echo esc_attr($options['tenant'] ?? 'consumers'); ?>' style='width: 200px;'>
    <p class='description'>For personal OneDrive accounts, use <code>consumers</code>. For work/school accounts, use your tenant GUID or <code>common</code>.</p>
    <?php
}

function utd_onedrive_gallery_manual_refresh_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    $val = $options['manual_refresh_token'] ?? '';
    ?>
    <textarea name='utd_onedrive_gallery_settings[manual_refresh_token]' rows='4' style='width:100%; max-width:600px;'><?php echo esc_textarea($val); ?></textarea>
        <p class='description'><strong>For personal OneDrive non-interactive authentication:</strong> Paste your refresh token here. The plugin will automatically obtain access tokens as needed.</p>

        <details>
            <summary style="cursor: pointer; font-weight:600;">Detailed setup instructions</summary>
            <div style="margin:12px 0 0 8px;">
                <h4>1) Create an app registration in Azure / Entra</h4>
                <ol>
                    <li>Open the <strong>Azure Portal</strong> (aka Entra) and go to <strong>Azure Active Directory &gt; App registrations</strong>.</li>
                    <li>Click <strong>New registration</strong> and set a descriptive <em>Name</em> (for example: <em>UTD OneDrive Gallery</em>).</li>
                    <li>Supported account types: choose <strong>Accounts in any organizational directory and personal Microsoft accounts</strong> (this allows personal Microsoft accounts).</li>
                    <li>Redirect URI: add a platform of type <strong>Public client (mobile &amp; desktop)</strong> and set the redirect URI to:<br>
                        <code>https://login.microsoftonline.com/common/oauth2/nativeclient</code>
                    </li>
                </ol>
                <h4>2) API permissions</h4>
                <ul>
                    <li>Go to <strong>API permissions</strong> &gt; <strong>Add a permission</strong> &gt; Microsoft Graph &gt; Delegated permissions.</li>
                    <li>Add: <code>offline_access</code>, <code>Files.Read</code> and <code>User.Read</code>.</li>
                    <li>For personal accounts, the user can consent during the sign-in step; admin consent is not required.</li>
                </ul>
                <h4>3) Obtain an authorization code (interactive, one-time)</h4>
                <p>Open the following URL in your browser (replace <code>YOUR_CLIENT_ID</code>):</p>
                <pre style="background:#f6f8fa;padding:10px;border-radius:6px;overflow:auto"><code>https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?client_id=YOUR_CLIENT_ID&amp;response_type=code&amp;redirect_uri=https://login.microsoftonline.com/common/oauth2/nativeclient&amp;scope=offline_access%20Files.Read%20User.Read</code></pre>
                <p>Sign in with your personal Microsoft account and grant consent. Once redirected, copy the <code>code</code> parameter from the final URL.</p>
                                <h4>4) Exchange the authorization code for a refresh token (one-time)</h4>
                                <p>Now exchange the <code>code</code> value you copied for tokens. Run the following <code>curl</code> command on your machine (or use Postman). Replace the placeholders <code>YOUR_CLIENT_ID</code> and <code>THE_CODE_FROM_STEP_3</code>. If you created a confidential client and have a <code>client_secret</code>, include it in the request body as shown.</p>
                                <pre style="background:#f6f8fa;padding:10px;border-radius:6px;overflow:auto"><code>curl -X POST "https://login.microsoftonline.com/consumers/oauth2/v2.0/token" \
 -H "Content-Type: application/x-www-form-urlencoded" \
 -d "client_id=YOUR_CLIENT_ID&grant_type=authorization_code&code=THE_CODE_FROM_STEP_3&redirect_uri=https://login.microsoftonline.com/common/oauth2/nativeclient&scope=offline_access%20Files.Read%20User.Read"</code></pre>
                                                                <h4>PowerShell (Windows) alternative</h4>
                                                                <p>If you prefer PowerShell instead of curl (Windows PowerShell 5.1 or later), run the following commands. The example posts form-encoded data and returns a JSON object; copy the <code>refresh_token</code> value from the response.</p>
                                                                <pre style="background:#f6f8fa;padding:10px;border-radius:6px;overflow:auto"><code>$body = @{
    client_id    = 'YOUR_CLIENT_ID'
    grant_type   = 'authorization_code'
    code         = 'THE_CODE_FROM_STEP_3'
    redirect_uri = 'https://login.microsoftonline.com/common/oauth2/nativeclient'
    scope        = 'offline_access Files.Read User.Read'
}

$response = Invoke-RestMethod -Method Post -Uri 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token' -Body $body -ContentType 'application/x-www-form-urlencoded'

# Inspect the result and copy the refresh token
$response | ConvertTo-Json -Depth 5
Write-Host "Refresh token:`n" $response.refresh_token

# If your app is a confidential client (has a client secret), add the secret to the body before calling:
#$body.client_secret = 'YOUR_CLIENT_SECRET'
# Then call Invoke-RestMethod as above.</code></pre>
                                <p>If you use a tenant other than <code>consumers</code>, change the host path accordingly (for example <code>https://login.microsoftonline.com/&lt;YOUR_TENANT&gt;/oauth2/v2.0/token</code>).</p>
                                <p>The response will be JSON and include an <code>access_token</code> and a <code>refresh_token</code>. Example (truncated):</p>
                                <pre style="background:#f6f8fa;padding:10px;border-radius:6px;overflow:auto"><code>{
    "token_type": "Bearer",
    "scope": "Files.Read User.Read openid profile offline_access",
    "expires_in": 3599,
    "ext_expires_in": 3599,
    "access_token": "ey...",
    "refresh_token": "0.AAA..."
}</code></pre>
                                <p>Copy the full value of <code>refresh_token</code> and paste it into the <strong>Refresh Token</strong> textarea above, then click <strong>Save Changes</strong>. The plugin will use the refresh token to obtain access tokens as needed.</p>
                                <p><strong>Notes & troubleshooting</strong></p>
                                <ul>
                                        <li>If you created a confidential app (with a client secret), include <code>&amp;client_secret=YOUR_CLIENT_SECRET</code> in the request body.</li>
                                        <li>If you receive an <em>invalid_grant</em> or consent error, ensure the redirect URI, client id, and scopes match the app registration and that you granted consent when signing in.</li>
                                        <li>Keep the refresh token secret — treat it like a password. If the token is compromised, revoke it by clearing the field in plugin settings and rotating credentials in Azure.</li>
                                        <li>To verify the token works, use the <em>Test Microsoft Graph API</em> button on this settings page after saving.</li>
                                </ul>
            </div>
        </details>
    <?php
}

function utd_onedrive_gallery_load_images_render() {
    $options = get_option('utd_onedrive_gallery_settings');
    $val = isset($options['load_images']) ? boolval($options['load_images']) : false;
    ?>
    <label><input type='checkbox' name='utd_onedrive_gallery_settings[load_images]' value='1' <?php checked($val, true); ?> /> <?php esc_html_e('Proportional Gallery view', 'utd-onedrive-gallery'); ?></label>
    <p class='description'>The default Gallery View. When enabled, images keep their natural proportions and the gallery arranges items to minimize gaps. When unchecked, the gallery uses same-size thumbnails/placeholders.</p>
    <?php
}

/**
 * Sanitize and normalize settings before saving.
 * Ensures checkbox values persist correctly when unchecked.
 */
function utd_onedrive_gallery_settings_sanitize($input) {
    $existing = get_option('utd_onedrive_gallery_settings', array());
    $out = array();

    // Strings
    $out['client_id'] = isset($input['client_id']) ? sanitize_text_field($input['client_id']) : ($existing['client_id'] ?? '');
    $out['tenant'] = isset($input['tenant']) ? sanitize_text_field($input['tenant']) : ($existing['tenant'] ?? 'consumers');
    $out['manual_refresh_token'] = isset($input['manual_refresh_token']) ? sanitize_textarea_field($input['manual_refresh_token']) : ($existing['manual_refresh_token'] ?? '');
    if (isset($input['client_secret'])) $out['client_secret'] = sanitize_text_field($input['client_secret']);

    // Integers
    $out['images_per_row'] = isset($input['images_per_row']) ? intval($input['images_per_row']) : intval($existing['images_per_row'] ?? 3);

    // Booleans (checkboxes) - ensure false when unchecked (missing from $input)
    $out['show_image_description'] = !empty($input['show_image_description']) ? 1 : 0;
    $out['load_images'] = !empty($input['load_images']) ? 1 : 0;

    // Caption source and EXIF code
    $out['caption_source'] = isset($input['caption_source']) ? strtoupper(sanitize_text_field($input['caption_source'])) : ($existing['caption_source'] ?? 'EXIF');
    $out['exif_code'] = isset($input['exif_code']) ? sanitize_text_field($input['exif_code']) : ($existing['exif_code'] ?? 'XPTitle');

    // Preserve tokens if present in existing settings (do not clobber)
    if (!empty($existing['graph_tokens'])) $out['graph_tokens'] = $existing['graph_tokens'];

    return $out;
}

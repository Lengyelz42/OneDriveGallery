<?php
/*
Plugin Name: UTD OneDrive Gallery Plugin
Plugin URI: https://utd.hu/onedrive-gallery/
Description: Displays an image/video gallery from a configured OneDrive folder.
Version: 2.8.5
Author: Lengyel Zoltán
License: GPL2
*/
// Do not enable or alter WP debug settings from within the plugin.

// Per-request state to avoid multiple token refresh attempts in the same PHP request.

// Per-request state to avoid multiple token refresh attempts in the same PHP request.
$GLOBALS['utd_onedrive_graph_refresh_attempted'] = $GLOBALS['utd_onedrive_graph_refresh_attempted'] ?? false;


// Register Gutenberg block
add_action('init', 'utd_onedrive_gallery_register_block');
function utd_onedrive_gallery_register_block() {
	if (!function_exists('register_block_type')) return;
	wp_register_script(
		'utd-onedrive-gallery-block',
		plugins_url('assets/block.js', __FILE__),
		array('wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor'),
		filemtime(plugin_dir_path(__FILE__).'assets/block.js')
	);
}

// Load admin settings UI only in admin screens (keeps frontend lightweight)
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
}

// Load frontend shortcode and minimal post-load script (separate file)
require_once plugin_dir_path(__FILE__) . 'includes/frontend.php';
// OneDrive OAuth2 authentication logic
// OAuth/Graph-based authentication removed: plugin uses shared-link mode only.

// Add connect button to settings page
// OAuth admin notices removed - shared-link mode is used by default.



// Admin menu moved to includes/settings.php

// Admin settings moved to includes/settings.php

/**
 * Build the Microsoft OAuth2 authorization URL for admin connect flow.
 */
function utd_onedrive_graph_build_auth_url() {
	$options = get_option('utd_onedrive_gallery_settings');
	$client_id = $options['client_id'] ?? '';
	$tenant = $options['tenant'] ?? 'common';
	if (empty($client_id)) return '';
	$redirect = admin_url('options-general.php?page=utd-onedrive-gallery');
	$state = wp_generate_password(12, false, false);
	update_option('utd_onedrive_graph_oauth_state', $state);
	$scopes = implode('%20', array('offline_access', 'Files.Read', 'User.Read'));
	$auth = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize?client_id=%s&response_type=code&redirect_uri=%s&response_mode=query&scope=%s&state=%s', urlencode($tenant), urlencode($client_id), urlencode($redirect), $scopes, urlencode($state));
	return $auth;
}

/**
 * Exchange authorization code for tokens
 */
function utd_onedrive_graph_exchange_code($code) {
	$options = get_option('utd_onedrive_gallery_settings');
	$client_id = $options['client_id'] ?? '';
	$tenant = $options['tenant'] ?? 'common';
	if (empty($client_id) || empty($code)) return new WP_Error('missing', 'Missing client_id or code');
	$token_url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
	$redirect = admin_url('options-general.php?page=utd-onedrive-gallery');
	$body = array(
		'client_id' => $client_id,
		'scope' => 'offline_access Files.Read User.Read',
		'code' => $code,
		'redirect_uri' => $redirect,
		'grant_type' => 'authorization_code',
		// client_secret omitted for public/native clients; include only if configured
	);
	if (!empty($options['client_secret'])) $body['client_secret'] = $options['client_secret'];
	$resp = wp_remote_post($token_url, array('body' => $body, 'timeout' => 20));
	if (is_wp_error($resp)) return $resp;
	$code = wp_remote_retrieve_response_code($resp);
	$body_json = json_decode(wp_remote_retrieve_body($resp), true);
	if ($code !== 200 || empty($body_json['access_token'])) {
		return new WP_Error('token_error', 'Token exchange failed: ' . wp_remote_retrieve_body($resp));
	}
	$tokens = array(
		'access_token' => $body_json['access_token'],
		'refresh_token' => $body_json['refresh_token'] ?? '',
		'expires_at' => time() + intval($body_json['expires_in'] ?? 3599) - 60,
	);
	$settings = get_option('utd_onedrive_gallery_settings');
	$settings['graph_tokens'] = $tokens;
	update_option('utd_onedrive_gallery_settings', $settings);
	return true;
}

/**
 * Refresh access token using refresh_token
 */
function utd_onedrive_graph_refresh_access_token() {
	$settings = get_option('utd_onedrive_gallery_settings');
	$tokens = $settings['graph_tokens'] ?? array();
	$refresh = $tokens['refresh_token'] ?? '';
	$client_id = $settings['client_id'] ?? '';
	$tenant = $settings['tenant'] ?? 'common';
	if (empty($refresh) || empty($client_id)) return new WP_Error('missing', 'Missing refresh token or client_id');
	$token_url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
	$body = array(
		'client_id' => $client_id,
		'scope' => 'offline_access Files.Read User.Read',
		'refresh_token' => $refresh,
		'grant_type' => 'refresh_token',
		// client_secret omitted for public/native clients; include only if configured
	);
	if (!empty($settings['client_secret'])) $body['client_secret'] = $settings['client_secret'];
	$start = microtime(true);
	$resp = wp_remote_post($token_url, array('body' => $body, 'timeout' => 20));
	$duration = microtime(true) - $start;
	if (is_wp_error($resp)) return $resp;
	$code = wp_remote_retrieve_response_code($resp);
	$body_json = json_decode(wp_remote_retrieve_body($resp), true);
	if ($code !== 200 || empty($body_json['access_token'])) {
		return new WP_Error('refresh_failed', 'Refresh failed');
	}
	$tokens = array(
		'access_token' => $body_json['access_token'],
		'refresh_token' => $body_json['refresh_token'] ?? $refresh,
		'expires_at' => time() + intval($body_json['expires_in'] ?? 3599) - 60,
	);
	$settings['graph_tokens'] = $tokens;
	update_option('utd_onedrive_gallery_settings', $settings);
	return $tokens;
}

/**
 * Try refreshing delegated tokens using a manually-provided refresh token (one-time paste)
 */
function utd_onedrive_graph_refresh_using_manual_token() {
	$settings = get_option('utd_onedrive_gallery_settings');
	$manual = trim($settings['manual_refresh_token'] ?? '');
	if (empty($manual)) return new WP_Error('no_manual', 'No manual refresh token configured');
	$client_id = $settings['client_id'] ?? '';
	$client_secret = $settings['client_secret'] ?? '';
	$tenant = $settings['tenant'] ?? 'common';
	if (empty($client_id)) return new WP_Error('missing_client', 'Missing client id');
	$token_url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
	$body = array(
		'client_id' => $client_id,
		'grant_type' => 'refresh_token',
		'refresh_token' => $manual,
		'scope' => 'offline_access Files.Read User.Read',
	);
	// include client_secret when available (confidential client)
	if (!empty($client_secret)) $body['client_secret'] = $client_secret;
	$start = microtime(true);
	$resp = wp_remote_post($token_url, array('body' => $body, 'timeout' => 20));
	$duration = microtime(true) - $start;
	if (is_wp_error($resp)) return $resp;
	$code = wp_remote_retrieve_response_code($resp);
	$body_json = json_decode(wp_remote_retrieve_body($resp), true);
	if ($code !== 200 || empty($body_json['access_token'])) {
		return new WP_Error('refresh_failed', 'Manual refresh failed: ' . wp_remote_retrieve_body($resp));
	}
	$tokens = array(
		'access_token' => $body_json['access_token'],
		'refresh_token' => $body_json['refresh_token'] ?? $manual,
		'expires_at' => time() + intval($body_json['expires_in'] ?? 3599) - 60,
	);
	$settings['graph_tokens'] = $tokens;
	update_option('utd_onedrive_gallery_settings', $settings);
	return $tokens;
}

// App-only (client credentials) removed - not supported for personal OneDrive accounts

/**
 * Perform GET request to Graph API using stored tokens; refresh if needed
 */
function utd_onedrive_graph_api_get($path) {
	$settings = get_option('utd_onedrive_gallery_settings');
	$tokens = $settings['graph_tokens'] ?? array();
	
	// If no access token, try using manual refresh token to obtain one
	if (empty($tokens['access_token'])) {
		$manual = utd_onedrive_graph_refresh_using_manual_token();
		if (!is_wp_error($manual)) {
			$tokens = $manual;
		}
	}
	
	if (empty($tokens['access_token'])) {
		return new WP_Error('no_token', 'No access token available. Please configure a refresh token in plugin settings.');
	}
	
	// Refresh if expired
	if (empty($tokens['expires_at']) || time() >= $tokens['expires_at']) {
		$ref = utd_onedrive_graph_refresh_access_token();
		if (is_wp_error($ref)) {
			// Fallback: try manual refresh token
			$manual = utd_onedrive_graph_refresh_using_manual_token();
			if (is_wp_error($manual)) return $ref;
			$tokens = $manual;
		} else {
			// Use the returned tokens directly
			$tokens = $ref;
		}
	}
	
	$access = $tokens['access_token'];
	$url = (strpos($path, 'https://') === 0) ? $path : 'https://graph.microsoft.com/v1.0' . $path;
	$resp = wp_remote_get($url, array('headers' => array('Authorization' => 'Bearer ' . $access), 'timeout' => 20));
	if (is_wp_error($resp)) return $resp;
	$code = wp_remote_retrieve_response_code($resp);
	$body = wp_remote_retrieve_body($resp);
	if ($code === 401) {
		// try refresh once
		$ref = utd_onedrive_graph_refresh_access_token();
		if (!is_wp_error($ref)) {
			$tokens = $ref;
			$access = $tokens['access_token'];
			$resp = wp_remote_get($url, array('headers' => array('Authorization' => 'Bearer ' . $access), 'timeout' => 20));
			if (is_wp_error($resp)) return $resp;
			$body = wp_remote_retrieve_body($resp);
			$code = wp_remote_retrieve_response_code($resp);
		}
	}
	if ($code < 200 || $code >= 400) return new WP_Error('graph_error', 'Graph request failed: ' . $body);
	return json_decode($body, true);
}

/**
 * Find folder by name in the user's drive and list its image/video children.
 * Returns array of items ['type'=>'image'|'video','url'=>...]
 */
// Frontend-only: `utd_onedrive_gallery_fetch_graph_items` moved to
// `includes/frontend.php` so admin-only auth helpers stay in the main file.
// See includes/frontend.php for the implementation.

function utd_onedrive_gallery_settings_page() {
	?>
	<div class="wrap">
		<h1>OneDrive Gallery Settings</h1>
		<form action='options.php' method='post'>
			<?php
			// Print nonce + registered option fields
			settings_fields('utd-onedrive-gallery');

			// Tab headers
			?>
			<h2 class="nav-tab-wrapper">
				<a href="#tab-general" class="nav-tab nav-tab-active" data-tab="general"><?php echo esc_html__('General Settings', 'utd-onedrive-gallery'); ?></a>
				<a href="#tab-auth" class="nav-tab" data-tab="auth"><?php echo esc_html__('Authentication', 'utd-onedrive-gallery'); ?></a>
			</h2>

			<div id="tab-general" class="odg-tab-panel" style="display:block;padding:12px 0;">
				<h2><?php echo esc_html__('General Settings', 'utd-onedrive-gallery'); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label><?php echo esc_html__('Images Per Row', 'utd-onedrive-gallery'); ?></label></th>
						<td><?php utd_onedrive_gallery_images_per_row_render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label><?php echo esc_html__('Show Image Description', 'utd-onedrive-gallery'); ?></label></th>
						<td><?php utd_onedrive_gallery_show_description_render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label><?php echo esc_html__('Caption source', 'utd-onedrive-gallery'); ?></label></th>
						<td><?php utd_onedrive_gallery_caption_source_render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label><?php echo esc_html__('EXIF code / key', 'utd-onedrive-gallery'); ?></label></th>
						<td><?php utd_onedrive_gallery_exif_code_render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label><?php echo esc_html__('Proportional Gallery view', 'utd-onedrive-gallery'); ?></label></th>
						<td><?php utd_onedrive_gallery_load_images_render(); ?></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</div>

			<div id="tab-auth" class="odg-tab-panel" style="display:none;padding:12px 0;">
				<h2><?php echo esc_html__('Authentication', 'utd-onedrive-gallery'); ?></h2>
				<p>
				<?php
				// Graph connect/disconnect and test buttons
				$auth_url = utd_onedrive_graph_build_auth_url();
				$settings = get_option('utd_onedrive_gallery_settings');
				$connected = !empty($settings['graph_tokens']['access_token']);
				if ($connected) {
					$disconnect_url = add_query_arg(array('page' => 'utd-onedrive-gallery', 'disconnect_graph' => 1, '_wpnonce' => wp_create_nonce('utd_onedrive_disconnect')), admin_url('options-general.php'));
					echo '<a href="'.esc_url($disconnect_url).'" class="button">'.esc_html__('Disconnect Microsoft','utd-onedrive-gallery').'</a>';
				} else {
					if (!empty($auth_url)) {
						echo '<a href="'.esc_url($auth_url).'" class="button">'.esc_html__('Connect to Microsoft','utd-onedrive-gallery').'</a>';
					} else {
						echo '<span class="description">'.esc_html__('Enter Client ID to enable Microsoft connection.','utd-onedrive-gallery').'</span>';
					}
				}
				$test_graph_url = add_query_arg(array('page' => 'utd-onedrive-gallery', 'test_graph' => 1, '_wpnonce' => wp_create_nonce('utd_onedrive_test_graph')), admin_url('options-general.php'));
				echo ' <a href="'.esc_url($test_graph_url).'" class="button">'.esc_html__('Test Microsoft Graph API','utd-onedrive-gallery').'</a>';
				?>
				</p>
				<table class="form-table">
					<tr>
						<th scope="row"><label><?php echo esc_html__('Microsoft App Client ID', 'utd-onedrive-gallery'); ?></label></th>
						<td><?php utd_onedrive_gallery_client_id_render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label><?php echo esc_html__('Tenant', 'utd-onedrive-gallery'); ?></label></th>
						<td><?php utd_onedrive_gallery_tenant_render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label><?php echo esc_html__('Refresh Token', 'utd-onedrive-gallery'); ?></label></th>
						<td><?php utd_onedrive_gallery_manual_refresh_render(); ?></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</div>

			<script>
			(function(){
				var tabs = document.querySelectorAll('.nav-tab');
				tabs.forEach(function(t){ t.addEventListener('click', function(e){
					e.preventDefault();
					var sel = this.getAttribute('data-tab');
					document.querySelectorAll('.nav-tab').forEach(function(x){ x.classList.remove('nav-tab-active'); });
					this.classList.add('nav-tab-active');
					document.querySelectorAll('.odg-tab-panel').forEach(function(p){ p.style.display = 'none'; });
					var panel = document.getElementById('tab-' + sel);
					if (panel) panel.style.display = 'block';
				}); });
			})();
			</script>
			<?php
			// Handle OAuth callback (code)
			if (isset($_GET['code']) && isset($_GET['state'])) {
				$state = get_option('utd_onedrive_graph_oauth_state');
				if ($_GET['state'] !== $state) {
					echo '<div class="notice notice-error"><p>' . esc_html__('OAuth state mismatch.','utd-onedrive-gallery') . '</p></div>';
				} else {
					$res = utd_onedrive_graph_exchange_code(sanitize_text_field($_GET['code']));
					if (is_wp_error($res)) {
						echo '<div class="notice notice-error"><p>' . esc_html__('Failed to connect to Microsoft: ','utd-onedrive-gallery') . esc_html($res->get_error_message()) . '</p></div>';
					} else {
						echo '<div class="notice notice-success"><p>' . esc_html__('Connected to Microsoft successfully.','utd-onedrive-gallery') . '</p></div>';
					}
				}
			}
			// Handle disconnect
			if (isset($_GET['disconnect_graph']) && !empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'utd_onedrive_disconnect')) {
				$settings = get_option('utd_onedrive_gallery_settings');
				unset($settings['graph_tokens']);
				update_option('utd_onedrive_gallery_settings', $settings);
				echo '<div class="notice notice-success"><p>' . esc_html__('Disconnected from Microsoft.','utd-onedrive-gallery') . '</p></div>';
			}
			?>
		</form>
	</div>
	<?php

	// If user clicked the Graph test button, call a simple Graph endpoint and show raw JSON
	if (isset($_GET['test_graph']) && !empty($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'utd_onedrive_test_graph')) {
		echo '<h2>' . esc_html__('Microsoft Graph API Test','utd-onedrive-gallery') . '</h2>';
		$resp = utd_onedrive_graph_api_get('/me/drive/root');
		if (is_wp_error($resp)) {
			echo '<div class="notice notice-error"><p>' . esc_html__('Graph test failed: ','utd-onedrive-gallery') . esc_html($resp->get_error_message()) . '</p></div>';
		} else {
			$json = wp_json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			echo '<div class="notice notice-success"><p>' . esc_html__('Successfully connected to Microsoft Graph!','utd-onedrive-gallery') . '</p></div>';
			echo '<p>' . esc_html__('Raw Graph response from /me/drive/root:','utd-onedrive-gallery') . '</p>';
			echo '<textarea rows="10" style="width:100%;">' . esc_textarea($json) . '</textarea>';
		}
	}


	
}

/**
 * Test helper: fetch shared link and return debug info (safe for display).
 * @param string $shared_link
 * @return array|WP_Error
 */
// shared-link test helper removed; plugin uses Graph API and a dedicated Graph test in settings.

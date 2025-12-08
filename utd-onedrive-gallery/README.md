# UTD OneDrive Gallery Plugin

## Installation
1. Copy the `utd-onedrive-gallery` folder into your WordPress `wp-content/plugins` directory.
2. In your WordPress admin dashboard, go to Plugins and activate "UTD OneDrive Gallery Plugin".

## Usage
- This plugin displays images and videos from a configured OneDrive folder using a shortcode: `[onedrive_gallery folder="Pictures"]` or a nested path like `[onedrive_gallery folder="Pictures/2025"]`.

## Development
- Edit `utd-onedrive-gallery.php` and the `src`/`assets` files to add features.

### Build the Gutenberg block

1. Install dependencies:

```bash
cd wp-content/plugins/utd-onedrive-gallery
npm install
```

2. Build (production):

```bash
npm run build
```

3. Development (watch):

```bash
npm run start
```

The built script will be output to the `assets` folder (e.g. `assets/block.js`) and is enqueued by the plugin.

## Notes
- Replace placeholder content and update author information as needed.
- For more details, see the WordPress Plugin Developer Handbook.

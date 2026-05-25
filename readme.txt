=== ReloadWP Image Optimizer ===
Contributors: andreialba
Tags: image compression, webp, optimize images, compress images, media library, bulk optimize, woocommerce, exif
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically compress JPEG and PNG images on upload, convert to WebP, strip EXIF metadata, bulk optimize existing media, and restore originals - all on your server, no API keys required.

== Description ==

**ReloadWP Image Optimizer** takes care of your site's image weight the moment a file hits the Media Library. No API keys, no cloud services, no monthly fees - everything runs on your server using WordPress's own image processing layer (GD or Imagick).

= What it does on every upload =

* Re-encodes JPEG and PNG files at your chosen quality level (default: 82 - a sweet spot between visual fidelity and file size).
* Optionally converts images to **WebP**, the modern format supported by all major browsers that typically cuts file size by 25-35% compared to JPEG at equivalent quality.
* Optionally strips **EXIF, GPS, and other metadata** - reducing file size further and protecting your users' privacy.
* Optionally **resizes** images that exceed your configured maximum width or height, preserving the original aspect ratio.
* **Skips re-encoding** if the processed file would end up larger than the original, so you never accidentally make things worse.
* Leaves **GIF files untouched** so animated images are never flattened to a single frame.
* Saves a **backup of every original** before making any changes, so you can always restore.

= Bulk Optimize Existing Media =

Already have hundreds of images in your Media Library? No problem. Go to **Media -> Bulk Optimize** to see exactly how many images are optimized, how many are pending, and a live progress bar. Hit **Start Bulk Optimization** and the plugin processes everything in safe batches using your current settings, with a live processed counter and estimated time remaining so users can see ongoing progress while the job runs. Originals are backed up before any change is made.

You can also select specific images in the Media Library list view and use the **Optimize images** bulk action to optimize only those files.

Users who can upload media can also access the matching Media Library optimization actions, so single-image and bulk workflows now follow the same permission model.

= Media Library Integration =

The plugin adds an **Optimizer** column to the Media Library list view showing the status of every image at a glance:

* **Optimized** (green) - the image has been compressed; a **Restore Original** link appears below.
* **Already optimal** (green) - the plugin checked the image using your current settings and found no smaller result, so the original file was kept.
* **Not optimized** (grey) - the image hasn't been processed yet; an **Optimize** link appears below.

If an image is marked **Already optimal**, the **Optimize** action is hidden for that exact settings combination so the Media Library does not keep retrying the same file unnecessarily. As soon as you change plugin settings such as quality, output format, or max dimensions, that image becomes optimizable again automatically.

This works for WebP-converted images too - if the original JPEG or PNG was converted to WebP on upload, the column still shows its optimized state and lets you restore the original.

= Restore & Backup Management =

* **Restore individual images** - click "Restore Original" in the Optimizer column or in the row actions. You're taken straight back to the Media Library page you were on, with filters and search intact.
* **Restore selected images** - select images in the Media Library list view and use the **Restore optimized images** bulk action.
* **Restore all images** - use the "Restore All Originals" button in the plugin settings to roll back your entire library in batches, with live progress and estimated time remaining displayed after the restore starts.
* **Delete all backups** - once you're happy with the results, free up disk space with the "Delete All Backups" button. The settings page shows the current backup folder size so you know exactly how much space you'll recover.

Backups are stored in your uploads folder under `image-compressor-backups/`, preserving each file's relative uploads path so nested folders and custom upload structures stay intact.

= Settings (Settings -> ReloadWP Image Optimizer) =

* **Output Format** - keep the original format (JPEG/PNG, re-compressed only) or convert to WebP. The WebP option is automatically disabled if your server does not support it.
* **Compression Quality** - a slider from 1 (smallest file) to 100 (best quality). Default is 82.
* **Strip EXIF Metadata** - remove camera make/model, GPS coordinates, date/time, and other hidden data from uploaded images.
* **Max Width / Max Height** - set pixel limits; images exceeding either dimension are resized proportionally on upload. Set to 0 to disable.
* **Restore All Originals** - roll back all optimized images to their originals in one go with live progress shown while the restore runs.
* **Delete All Backups** - permanently remove the backup folder to reclaim disk space.
* **Compression Stats** - tracks total images compressed and kilobytes saved since activation, with a reset button.

= Compatible with WooCommerce =

ReloadWP Image Optimizer works seamlessly with **WooCommerce**. Product images, gallery images, and variation images are all processed automatically when uploaded through the WooCommerce product editor or the standard Media Library. Smaller images mean faster product pages, better Core Web Vitals scores, and higher conversion rates - especially on mobile.

= Compatible with Popular Theme Builders =

The plugin is fully compatible with all major WordPress theme builders and page builders because it operates at the media upload level, independently of how your content is displayed:

* **Elementor** - images uploaded through Elementor's widget panels and the native media picker are optimized automatically.
* **Divi** - all images added via the Divi Builder, including section backgrounds and module images, are processed at upload time.
* **Beaver Builder** - works with Beaver Builder's media uploader and all image/background modules.
* **Bricks Builder** - fully compatible; images processed before they reach the builder.
* **Oxygen Builder** - no conflicts; optimization happens at the WordPress core upload layer.
* **WPBakery (Visual Composer)** - compatible with all image elements and background pickers.
* **Gutenberg / Block Editor** - all images inserted via the block editor's media picker or Upload block are optimized on the way in.
* **GeneratePress, Astra, Kadence, OceanWP, Hello Elementor** - compatible with all popular lightweight themes; optimization is theme-agnostic.
* **ACF (Advanced Custom Fields)** - images uploaded to ACF image and gallery fields are processed automatically.

= Compatible with Popular Caching & Performance Plugins =

* **WP Rocket** - images are already optimized at source, complementing WP Rocket's lazy loading and CDN features.
* **LiteSpeed Cache** - works alongside LiteSpeed's image optimization; no conflicts.
* **W3 Total Cache** - fully compatible.
* **Smush / Imagify / ShortPixel** - can be used alongside or as a standalone alternative; no known conflicts.

= No lock-in, no subscription =

* Zero external requests - your images never leave your server.
* No API key required.
* Clean uninstall - all plugin options are removed when you uninstall. Optimized images and backups remain intact.
* Translation-ready with a dedicated `image-compressor` text domain and `languages` directory.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings -> ReloadWP Image Optimizer** to configure compression quality, output format, EXIF stripping, and maximum image dimensions.
4. Start uploading images - compression happens automatically.
5. To optimize existing images, go to **Media -> Bulk Optimize**.

== Frequently Asked Questions ==

= Does this affect images I have already uploaded? =

Not automatically. To optimize existing images, go to **Media -> Bulk Optimize** and click **Start Bulk Optimization**. You can also select specific images in the Media Library list view and use the **Optimize images** bulk action.

= What does "Already optimal" mean in the Media Library? =

It means the plugin already tested that image with your current settings and found that re-compressing or converting it would not produce a smaller file, so the original was left unchanged. The image is treated as already processed for the current settings, and the Optimize link is hidden to avoid repeated no-op runs.

If you later change plugin settings such as compression quality, output format, or max dimensions, the image becomes optimizable again automatically.

= Will it work if my server only has GD (no Imagick)? =

Yes. The plugin uses WordPress's built-in `WP_Image_Editor` abstraction, which works with either GD or Imagick. Note that GD always strips EXIF metadata on re-encode regardless of your setting; Imagick can preserve it when the "Strip EXIF" option is unchecked.

= My server doesn't support WebP. What happens? =

The plugin detects WebP support automatically. If your server cannot produce WebP files, the WebP option is disabled in the settings UI and the plugin falls back to re-compressing the original format (JPEG or PNG).

= What quality value should I use? =

82 is the default and a proven sweet spot for web images - visually indistinguishable from the original for most photographs while delivering meaningful file-size savings. Go lower (70-75) for even smaller files, or higher (88-90) for maximum fidelity on portfolio or print-preview sites.

= Does the plugin compress all image types? =

It compresses JPEG and PNG files. GIF files are intentionally left unchanged to preserve animation. WebP, AVIF, SVG, and other formats uploaded directly are also left unchanged.

= I uploaded a JPEG and it was converted to WebP. Can I get the original back? =

Yes. The original JPEG is backed up before conversion. In the Media Library list view, find the image in the **Optimizer** column - it will show "Optimized" with a **Restore Original** link. Clicking it restores the original JPEG, updates the attachment, and regenerates all thumbnails.

= Where are backups stored? =

Backups are stored in your uploads directory under `wp-content/uploads/image-compressor-backups/`, preserving each image's relative uploads path beneath that folder.

= Is it compatible with WooCommerce? =

Yes, fully compatible. Product images, gallery images, and variation images are all optimized automatically when uploaded through WooCommerce or the standard Media Library.

= Is it compatible with Elementor, Divi, Beaver Builder, or other page builders? =

Yes. Because the plugin hooks into WordPress's core upload pipeline (`wp_handle_upload_prefilter`), it works with every tool that uses the standard WordPress media uploader - including Elementor, Divi, Beaver Builder, Bricks, Oxygen, WPBakery, and all other major builders.

= Is the plugin safe to use on a production site? =

Yes. The plugin operates on the upload temp file before WordPress stores it, and always backs up the original before modifying any existing image. If anything goes wrong (unsupported format, editor error, output larger than input), it returns the original file untouched. Your images are never silently corrupted.

= Does it slow down uploads? =

Re-encoding adds a small amount of server-side processing time, typically well under a second for standard web images. For very large files (20 MB+) on shared hosting, you may notice a brief delay.

= Will uninstalling the plugin remove my compressed images? =

No. The compressed files in your Media Library are permanent. Only the plugin's settings (stored in `wp_options`) are deleted on uninstall. Optimized images and any remaining backups stay in place.

= Can I delete the backups to save disk space? =

Yes. Once you're satisfied with the optimization results, go to **Settings -> ReloadWP Image Optimizer** and click **Delete All Backups**. The settings page shows the current backup folder size before you confirm. Note: after deleting backups, individual images can no longer be restored to their originals.

== Screenshots ==

1. Settings page - configure output format, quality, EXIF stripping, max dimensions, restore all originals, delete backups, and compression stats.
2. Media -> Bulk Optimize page - library stats, live progress bar, processed counter, estimated time remaining, current settings summary, and Start Bulk Optimization button.
3. Media Library list view - Optimizer column showing optimized/not-optimized status with inline Restore Original and Optimize links.
4. Media Library bulk actions - Optimize images and Restore optimized images bulk actions.
5. Restore All Originals flow in settings with on-demand live progress and ETA after the button is pressed.

== Upgrade Notice ==

= 1.0.0 =
First public release of ReloadWP Image Optimizer with automatic upload compression, optional WebP conversion and EXIF stripping, bulk optimization, original backup and restore tools, smart "Already optimal" handling, and WordPress 7.0-tested repository metadata.

== Changelog ==

= 1.0.0 =
* First public release of **ReloadWP Image Optimizer**.
* Automatic JPEG and PNG compression on upload.
* Optional WebP conversion.
* Optional EXIF metadata stripping.
* Configurable max width and max height resize.
* Adjustable quality slider (default 82).
* Smart **Already optimal** detection for images that do not get smaller with the current settings.
* Automatically hides the per-image **Optimize** action for files already checked under the current settings, and makes them optimizable again when settings change.
* Media -> Bulk Optimize page with library stats, progress bar, current settings summary, and batch processing.
* Bulk optimization skips repeated no-op retries for images already checked with the current settings.
* Optimizer column in the Media Library list view with inline Optimize and Restore Original actions.
* Optimize images and Restore optimized images bulk actions in the Media Library list view.
* Restore All Originals batch action in plugin settings.
* Delete All Backups action in plugin settings, including backup folder size reporting.
* Upload-path mirrored backup storage under `image-compressor-backups/YYYY/MM/`.
* Compression stats with reset button.
* Original files are backed up only when a real optimized output is created, preventing false optimized states.
* Restore redirect preserved back to the originating Media Library view.
* Larger outputs are skipped so optimization never replaces a file with a bigger one.
* Scoped quality overrides so the plugin only affects its own optimization flows.
* Safer backup, restore, and cleanup path handling for nested uploads.
* Consistent Media Library permissions for single-image and bulk optimization actions.
* Includes WordPress.org-ready plugin headers and WordPress 7.0-tested metadata.
* Clean uninstall that removes stored options.

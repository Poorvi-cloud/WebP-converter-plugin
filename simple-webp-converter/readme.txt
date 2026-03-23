=== Poorvi's WebP Converter ===
Contributors: poorvi
Tags: webp, images, performance, optimization
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert uploaded JPG/PNG images to WebP format and serve WebP to supporting browsers.

== Description ==

Poorvi's WebP Converter automatically converts your uploaded JPEG and PNG images to WebP format,
reducing file sizes while maintaining visual quality. Modern browsers receive WebP,
while older browsers continue to receive the original images.

Features:
* Automatic conversion on upload.
* Bulk conversion tool for existing images.
* Supports both Imagick and GD libraries.
* No complicated configuration required.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/poorvis-webp-converter` directory,
   or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Ensure your server has Imagick with WebP support or GD with `imagewebp()` enabled.
4. Go to **Settings → Poorvi's WebP Converter** to run the bulk conversion for existing images.

== Frequently Asked Questions ==

= Does this work on shared hosting? =

Yes, as long as your hosting provider has Imagick with WebP support or GD with `imagewebp()` enabled.

= Will this modify my original files? =

No. The plugin creates separate `.webp` versions of your existing JPEG and PNG files.

= What happens if a browser does not support WebP? =

Browsers that do not support WebP will continue to receive the original JPEG/PNG image.

== Screenshots ==

1. Settings page with bulk conversion tool.

== Changelog ==

= 1.0.0 =
* Initial release with automatic upload conversion and bulk conversion tool.

== Upgrade Notice ==

= 1.0.0 =
Initial stable release.

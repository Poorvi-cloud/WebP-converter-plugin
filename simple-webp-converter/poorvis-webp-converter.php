<?php
/**
 * Plugin Name: Poorvi's WebP Converter
 * Plugin URI: http://poorvi.local/poorvis-webp-converter
 * Description: Convert uploaded JPG/PNG images to WebP and serve WebP to browsers that accept it. Includes a bulk conversion tool.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Author: Poorvi
 * Author URI: http://poorvi.local
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: poorvis-webp-converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Poorvis_WebP_Converter' ) ) {

	class Poorvis_WebP_Converter {

		private static $instance = null;
		private $using_imagick   = false;
		private $using_gd        = false;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
				self::$instance->setup();
			}
			return self::$instance;
		}

		private function setup() {

			// detect capabilities
			if ( extension_loaded( 'imagick' ) ) {
				try {
					$im      = new Imagick();
					$formats = $im->queryFormats( 'WEBP' );
					if ( ! empty( $formats ) ) {
						$this->using_imagick = true;
					}
					$im->clear();
					$im->destroy();
				} catch ( Exception $e ) {
					error_log( "Poorvi's WebP Converter Imagick init error: " . $e->getMessage() );
				}
			}

			if ( function_exists( 'imagewebp' ) ) {
				$this->using_gd = true;
			}

			// admin notices if no support
			add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );

			// hooks for converting on upload (after metadata is generated)
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'convert_attachment_metadata' ), 10, 2 );

			// serve webp when supported
			add_filter( 'wp_get_attachment_url', array( $this, 'maybe_serve_webp_url' ), 10, 2 );
			add_filter( 'wp_get_attachment_image_src', array( $this, 'maybe_serve_webp_image_src' ), 10, 4 );

			// admin menu
			add_action( 'admin_menu', array( $this, 'add_admin_page' ) );

			// ajax for bulk
			add_action( 'wp_ajax_swc_bulk_convert', array( $this, 'ajax_bulk_convert' ) );
		}

		public function maybe_show_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( ! $this->using_imagick && ! $this->using_gd ) {
				echo '<div class="notice notice-error"><p><strong>' .
				     esc_html__( "Poorvi's WebP Converter:", 'poorvis-webp-converter' ) .
				     '</strong> ' .
				     esc_html__( 'Your server has neither Imagick with WebP support nor GD imagewebp(). The plugin cannot create WebP files until one is available.', 'poorvis-webp-converter' ) .
				     '</p></div>';
			}
		}

		/**
		 * Convert image files after sizes are generated.
		 */
		public function convert_attachment_metadata( $metadata, $attachment_id ) {

			$mime = get_post_mime_type( $attachment_id );
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				return $metadata;
			}

			$upload_dir    = wp_get_upload_dir();
			$file          = path_join( $upload_dir['basedir'], $metadata['file'] );
			$full_original = $file;

			// convert original if exists
			$this->convert_if_supported( $full_original );

			// convert all sizes included in metadata
			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$dir = pathinfo( $full_original, PATHINFO_DIRNAME );
				foreach ( $metadata['sizes'] as $sizeinfo ) {
					if ( ! empty( $sizeinfo['file'] ) ) {
						$size_path = $dir . '/' . $sizeinfo['file'];
						$this->convert_if_supported( $size_path );
					}
				}
			}

			return $metadata;
		}

		private function convert_if_supported( $filepath ) {
			if ( ! file_exists( $filepath ) ) {
				return false;
			}
			$ext = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
				return false;
			}

			$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $filepath );

			// if webp exists and newer, skip
			if ( file_exists( $webp_path ) && filemtime( $webp_path ) >= filemtime( $filepath ) ) {
				return true;
			}

			return $this->create_webp( $filepath, $webp_path );
		}

		private function create_webp( $src_path, $dest_path ) {

			// prefer Imagick
			if ( $this->using_imagick ) {
				try {
					$im = new Imagick( $src_path );
					$im->setImageFormat( 'webp' );
					$im->setImageCompressionQuality( 80 );

					// preserve orientation for JPEG EXIF
					if ( $im->getImageOrientation() ) {
						$im->setImageOrientation( Imagick::ORIENTATION_UNDEFINED );
					}

					$result = $im->writeImage( $dest_path );
					$im->clear();
					$im->destroy();
					if ( $result ) {
						return true;
					}
				} catch ( Exception $e ) {
					error_log( "Poorvi's WebP Converter Imagick error: " . $e->getMessage() );
				}
			}

			// fallback to GD
			if ( $this->using_gd ) {
				$ext = strtolower( pathinfo( $src_path, PATHINFO_EXTENSION ) );
				if ( 'png' === $ext ) {
					$img = imagecreatefrompng( $src_path );
					if ( ! $img ) {
						return false;
					}
					$width  = imagesx( $img );
					$height = imagesy( $img );
					$true   = imagecreatetruecolor( $width, $height );
					imagealphablending( $true, false );
					imagesavealpha( $true, true );
					$transparent = imagecolorallocatealpha( $true, 0, 0, 0, 127 );
					imagefill( $true, 0, 0, $transparent );
					imagecopy( $true, $img, 0, 0, 0, 0, $width, $height );
					$ok = imagewebp( $true, $dest_path, 80 );
					imagedestroy( $img );
					imagedestroy( $true );
					return $ok;
				} else {
					$img = imagecreatefromjpeg( $src_path );
					if ( ! $img ) {
						return false;
					}
					$ok = imagewebp( $img, $dest_path, 80 );
					imagedestroy( $img );
					return $ok;
				}
			}

			return false;
		}

		/**
		 * When attachment url is requested, serve webp if browser accepts it and file exists.
		 */
		public function maybe_serve_webp_url( $url, $post_id ) {

			$mime = get_post_mime_type( $post_id );
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				return $url;
			}

			if ( empty( $_SERVER['HTTP_ACCEPT'] ) || false === strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) ) {
				return $url;
			}

			$path = $this->attachment_url_to_path( $url );
			if ( ! $path ) {
				return $url;
			}

			$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );
			if ( file_exists( $webp_path ) ) {
				$webp_url = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $url );
				return $webp_url;
			}

			return $url;
		}

		/**
		 * Filter wp_get_attachment_image_src to replace URL (keeps width/height).
		 */
		public function maybe_serve_webp_image_src( $image, $attachment_id, $size, $icon ) {

			if ( ! is_array( $image ) || empty( $image[0] ) ) {
				return $image;
			}

			$img_url = $image[0];
			$mime    = get_post_mime_type( $attachment_id );

			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				return $image;
			}

			if ( empty( $_SERVER['HTTP_ACCEPT'] ) || false === strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) ) {
				return $image;
			}

			$path = $this->attachment_url_to_path( $img_url );
			if ( ! $path ) {
				return $image;
			}

			$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );
			if ( file_exists( $webp_path ) ) {
				$image[0] = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $img_url );
			}

			return $image;
		}

		private function attachment_url_to_path( $url ) {
			$upload_dir = wp_get_upload_dir();
			if ( false === strpos( $url, $upload_dir['baseurl'] ) ) {
				return false;
			}
			$relative = substr( $url, strlen( $upload_dir['baseurl'] ) );
			$relative = ltrim( $relative, '/' );
			return path_join( $upload_dir['basedir'], $relative );
		}

		/* ---------------------
		   Admin UI & Bulk Convert
		----------------------*/
		public function add_admin_page() {
			add_options_page(
				__( "Poorvi's WebP Converter", 'poorvis-webp-converter' ),
				__( "Poorvi's WebP Converter", 'poorvis-webp-converter' ),
				'manage_options',
				'poorvis-webp-converter',
				array( $this, 'render_admin_page' )
			);
		}

		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized', 'poorvis-webp-converter' ) );
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( "Poorvi's WebP Converter", 'poorvis-webp-converter' ); ?></h1>
				<p><?php esc_html_e( 'Server support:', 'poorvis-webp-converter' ); ?>
					<?php
					if ( $this->using_imagick ) {
						echo ' <strong>Imagick (WebP)</strong>';
					} elseif ( $this->using_gd ) {
						echo ' <strong>GD (imagewebp)</strong>';
					} else {
						echo ' <strong style="color:red">' . esc_html__( 'None (install Imagick with WebP or enable GD)', 'poorvis-webp-converter' ) . '</strong>';
					}
					?>
				</p>

				<h2><?php esc_html_e( 'Bulk convert existing images', 'poorvis-webp-converter' ); ?></h2>
				<p><?php esc_html_e( 'Click the button below to convert attachments in batches. This runs AJAX requests so it will not time out. It converts original files and all sizes to WebP.', 'poorvis-webp-converter' ); ?></p>

				<button id="swc-start" class="button button-primary"><?php esc_html_e( 'Start Bulk Convert', 'poorvis-webp-converter' ); ?></button>
				<button id="swc-stop" class="button"><?php esc_html_e( 'Stop', 'poorvis-webp-converter' ); ?></button>
				<div id="swc-log" style="white-space:pre-wrap;margin-top:10px;border:1px solid #ddd;padding:10px;background:#fff;"></div>
			</div>

			<script>
			(function(){
				const startBtn = document.getElementById('swc-start');
				const stopBtn  = document.getElementById('swc-stop');
				const log      = document.getElementById('swc-log');
				let running    = false;
				let offset     = 0;
				const batch    = 20;

				function appendLog(msg) {
					log.textContent += msg + "\n";
					log.scrollTop = log.scrollHeight;
				}

				startBtn.addEventListener('click', () => {
					if (running) return;
					running = true;
					offset  = 0;
					appendLog('Starting bulk conversion...');
					runBatch();
				});

				stopBtn.addEventListener('click', () => {
					running = false;
					appendLog('Stopped by user.');
				});

				function runBatch() {
					if (!running) return;
					appendLog('Requesting batch offset ' + offset);
					const data = new FormData();
					data.append('action','swc_bulk_convert');
					data.append('nonce','<?php echo wp_create_nonce( 'swc_bulk_nonce' ); ?>');
					data.append('offset', offset);
					data.append('batch', batch);

					fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'})
					.then(r=>r.json())
					.then(d=>{
						if (d.error) {
							appendLog('Error: ' + d.error);
							running = false;
							return;
						}
						appendLog(d.message);
						if (d.more) {
							offset += batch;
							setTimeout(runBatch, 300);
						} else {
							appendLog('Bulk conversion complete.');
							running = false;
						}
					})
					.catch(err=>{
						appendLog('AJAX error: ' + err);
						running = false;
					});
				}
			})();
			</script>
			<?php
		}

		public function ajax_bulk_convert() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json( array( 'error' => 'unauthorized' ), 403 );
			}

			check_ajax_referer( 'swc_bulk_nonce', 'nonce' );

			$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
			$batch  = isset( $_POST['batch'] ) ? intval( $_POST['batch'] ) : 20;

			$args = array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => $batch,
				'offset'         => $offset,
				'post_status'    => 'inherit',
			);

			$q = new WP_Query( $args );
			if ( ! $q->have_posts() ) {
				wp_send_json(
					array(
						'message' => 'No more images found',
						'more'    => false,
					)
				);
			}

			$converted = 0;
			$tried     = 0;

			foreach ( $q->posts as $p ) {
				$tried++;
				$meta = wp_get_attachment_metadata( $p->ID );

				if ( empty( $meta ) ) {
					$meta = wp_generate_attachment_metadata( $p->ID, get_attached_file( $p->ID ) );
					if ( ! empty( $meta ) ) {
						wp_update_attachment_metadata( $p->ID, $meta );
					}
				}

				$file = get_attached_file( $p->ID );
				if ( $file && file_exists( $file ) ) {
					if ( $this->convert_if_supported( $file ) ) {
						$converted++;
					}
				}

				if ( ! empty( $meta['sizes'] ) ) {
					$dir = pathinfo( $file, PATHINFO_DIRNAME );
					foreach ( $meta['sizes'] as $sizeinfo ) {
						if ( ! empty( $sizeinfo['file'] ) ) {
							$size_path = $dir . '/' . $sizeinfo['file'];
							if ( $this->convert_if_supported( $size_path ) ) {
								$converted++;
							}
						}
					}
				}
			}

			wp_send_json(
				array(
					'message' => sprintf( 'Processed %d attachments, created/updated %d webp files.', $tried, $converted ),
					'more'    => ( $q->found_posts > ( $offset + $batch ) ),
				)
			);
		}

	}

	Poorvis_WebP_Converter::instance();
}

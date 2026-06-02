<?php
/**
 * Local fallback — the R2 read path for Stateless mode (SWR-307).
 *
 * In Stateless mode local copies are removed after offload, so WordPress can't
 * read a file off disk for operations like thumbnail regeneration or image
 * editing. Rather than back the whole uploads directory with an r2:// stream
 * wrapper (invasive, high-risk), this hooks the single chokepoint WordPress
 * uses to resolve a file's local path and transparently restores the bytes
 * from R2 on demand. The restored copy is transient working data; the offloader
 * re-uploads (and, in Stateless mode, re-removes) any derivatives produced.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Local_Fallback {

	/** @var R2_Client */
	private $client;

	/** @var Settings */
	private $settings;

	/**
	 * @param R2_Client $client
	 * @param Settings  $settings
	 */
	public function __construct( R2_Client $client, Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Hook the read path. Only active in Stateless mode — in CDN mode the local
	 * copies are still present, so nothing to restore.
	 */
	public function register() {
		if ( 'stateless' !== $this->settings->get( 'mode' ) ) {
			return;
		}
		add_filter( 'get_attached_file', array( $this, 'ensure_local' ), 10, 2 );
		add_filter( 'load_image_to_edit_path', array( $this, 'ensure_local_for_edit' ), 10, 3 );
	}

	/**
	 * Ensure an attachment's original exists locally, restoring it from R2 when
	 * it has been removed (Stateless mode).
	 *
	 * @param string $file          Expected local path.
	 * @param int    $attachment_id
	 * @return string
	 */
	public function ensure_local( $file, $attachment_id ) {
		if ( '' === (string) $file || file_exists( $file ) ) {
			return $file;
		}
		$key = $this->original_key( (int) $attachment_id );
		if ( false === $key ) {
			return $file;
		}
		$this->client->download_object( $key, $file );
		return $file;
	}

	/**
	 * Same guarantee for the image-editor load path.
	 *
	 * @param string $filepath
	 * @param int    $attachment_id
	 * @param string|int[] $size
	 * @return string
	 */
	public function ensure_local_for_edit( $filepath, $attachment_id, $size ) {
		if ( '' === (string) $filepath || file_exists( $filepath ) ) {
			return $filepath;
		}
		$original = $this->original_key( (int) $attachment_id );
		if ( false === $original ) {
			return $filepath;
		}
		// The editor path may be a size; restore the matching R2 object by
		// keeping the original's directory and the requested basename.
		$dir = dirname( $original );
		$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );
		$key = $dir . wp_basename( $filepath );
		$this->client->download_object( $key, $filepath );
		return $filepath;
	}

	/**
	 * Resolve an offloaded attachment's original R2 key, or false.
	 *
	 * @param int $attachment_id
	 * @return string|false
	 */
	private function original_key( $attachment_id ) {
		$key = (string) get_post_meta( $attachment_id, '_r2offload_key', true );
		if ( '' === $key ) {
			$synced = get_post_meta( $attachment_id, '_r2offload_synced', true );
			$file   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( $synced && '' !== $file ) {
				$key = $this->settings->object_key( $file );
			}
		}
		return ( '' === $key ) ? false : $key;
	}
}

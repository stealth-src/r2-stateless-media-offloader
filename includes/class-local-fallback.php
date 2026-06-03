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
	 * Temp files restored this request, removed on shutdown.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Within-request cache of R2 key => restored temp path, so multiple filter
	 * calls for the same object (e.g. get_attached_file + an image op) don't
	 * download it more than once.
	 *
	 * @var array<string,string>
	 */
	private $restored = array();

	/**
	 * Within-request cache of attachment_id => original R2 key (or false), to
	 * avoid repeated post-meta lookups for the same attachment.
	 *
	 * @var array<int,string|false>
	 */
	private $key_cache = array();

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
		if ( 'stateless' !== $this->settings->get( 'mode' ) || ! $this->settings->is_configured() ) {
			return;
		}
		add_filter( 'get_attached_file', array( $this, 'ensure_local' ), 10, 2 );
		add_filter( 'load_image_to_edit_path', array( $this, 'ensure_local_for_edit' ), 10, 3 );
		add_filter( 'wp_get_original_image_path', array( $this, 'ensure_local_original' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'cleanup' ) );
	}

	/**
	 * Restore a big-image upload's full-resolution original (the file named in
	 * metadata['original_image']) from R2 on demand — WordPress reads it through
	 * its own path filter, which the other hooks don't cover.
	 *
	 * @param string $path
	 * @param int    $attachment_id
	 * @return string
	 */
	public function ensure_local_original( $path, $attachment_id ) {
		return $this->restore_sibling( $path, $attachment_id );
	}

	/**
	 * Restore a file that lives in the same R2 "directory" as the attachment's
	 * resolved original key, matched by the requested basename — covers both an
	 * image-editor size path and the big-image full-resolution original.
	 *
	 * @param string $path          Expected (possibly removed) local path.
	 * @param int    $attachment_id
	 * @return string The temp restore path, or $path unchanged when not offloaded.
	 */
	private function restore_sibling( $path, $attachment_id ) {
		if ( '' === (string) $path || file_exists( $path ) ) {
			return $path;
		}
		$original = $this->original_key( (int) $attachment_id );
		if ( false === $original ) {
			return $path;
		}
		$dir = dirname( $original );
		$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );
		$key = $dir . wp_basename( $path );
		$tmp = $this->restore_to_temp( $key, wp_basename( $path ), (int) $attachment_id );
		return ( '' === $tmp ) ? $path : $tmp;
	}

	/**
	 * Provide a readable local path for an attachment's original, restoring it
	 * from R2 to a temporary file when it has been removed (Stateless mode).
	 *
	 * The container's uploads directory is not assumed writable at runtime, so
	 * the restore lands in the system temp dir and that path is returned —
	 * WordPress image functions accept any readable path as a source.
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
		$tmp = $this->restore_to_temp( $key, wp_basename( $file ), (int) $attachment_id );
		return ( '' === $tmp ) ? $file : $tmp;
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
		// The editor path may be a size; restore the matching R2 object by
		// keeping the original's directory and the requested basename.
		return $this->restore_sibling( $filepath, $attachment_id );
	}

	/**
	 * Download an R2 object to a temporary file and return its path.
	 *
	 * @param string $key
	 * @param string $basename Preserve the extension so image functions work.
	 * @param int    $attachment_id For error context.
	 * @return string Temp path on success, '' on failure.
	 */
	private function restore_to_temp( $key, $basename, $attachment_id ) {
		// Reuse a temp file already restored for this key this request (and only
		// if it still exists — shutdown cleanup may not have run, but a stray
		// unlink could have).
		if ( isset( $this->restored[ $key ] ) && file_exists( $this->restored[ $key ] ) ) {
			return $this->restored[ $key ];
		}
		$tmp = wp_tempnam( $basename );
		if ( ! $tmp ) {
			return '';
		}
		$restored = $this->client->download_object( $key, $tmp );
		if ( is_wp_error( $restored ) ) {
			wp_delete_file( $tmp );
			error_log( sprintf( 'r2offload: restore failed for %s (attachment %d): %s', $key, $attachment_id, $restored->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}
		$this->temp_files[]    = $tmp;
		$this->restored[ $key ] = $tmp;
		return $tmp;
	}

	/**
	 * Remove restored temp files at the end of the request.
	 */
	public function cleanup() {
		foreach ( $this->temp_files as $tmp ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
		$this->temp_files = array();
		$this->restored   = array();
		$this->key_cache  = array();
	}

	/**
	 * Resolve an offloaded attachment's original R2 key, or false. Cached per
	 * request — the read path may resolve the same attachment several times
	 * (get_attached_file → an image op → original-image path) and each lookup
	 * hits post meta.
	 *
	 * @param int $attachment_id
	 * @return string|false
	 */
	private function original_key( $attachment_id ) {
		if ( ! array_key_exists( $attachment_id, $this->key_cache ) ) {
			$this->key_cache[ $attachment_id ] = $this->settings->resolve_object_key( $attachment_id );
		}
		return $this->key_cache[ $attachment_id ];
	}
}

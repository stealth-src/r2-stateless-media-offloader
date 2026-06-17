<?php
/**
 * Local fallback — the R2 read path for Stateless mode (SWR-307).
 *
 * In Stateless mode local copies are removed after offload, so WordPress can't
 * read a file off disk for operations like thumbnail regeneration or image
 * editing. Rather than back the whole uploads directory with an r2:// stream
 * wrapper (invasive, high-risk), this hooks the single chokepoint WordPress
 * uses to resolve a file's local path and transparently restores the bytes
 * from R2 on demand. The restored copy is transient working data, removed on
 * shutdown.
 *
 * Restore location (SWR-332): when the attachment's canonical uploads directory
 * is writable, the file is restored THERE (atomic download-then-rename), so any
 * derivatives WordPress writes alongside it — `wp media regenerate`, the
 * in-admin image editor — land in uploads/ where the offloader picks them up and
 * re-removes them, and metadata['file'] stays correct. When the uploads dir is
 * read-only (truly ephemeral containers), it falls back to the system temp dir:
 * reads still work, but derivatives written off that path are not captured, so
 * regeneration/editing should be done in CDN mode there. In that read-only case a
 * `wp_update_attachment_metadata` guard (guard_temp_metadata) repairs the canonical
 * metadata pointer before it is saved, so a regenerate/edit fails visibly (logged,
 * new sizes not stored) instead of silently corrupting the attachment. The
 * `r2offload_restore_to_uploads` filter (default true) can force the temp-dir
 * behaviour. CDN mode and new uploads are unaffected either way.
 *
 * Tradeoffs of the canonical restore (accepted): the restored original is a real
 * file at the canonical uploads path, swept on shutdown — an ABNORMAL request
 * termination (uncaught fatal that skips shutdown) can leave it behind; the bytes
 * are correct, so it's a hygiene leak, not data loss. And if offload is frozen
 * mid-regeneration via `r2offload_offload_on_upload`, the newly generated sizes
 * stay in uploads/ (the next offload/migrate pass picks them up).
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
	 * Files restored this request (canonical uploads path or system temp),
	 * removed on shutdown. A canonical restore the offloader already cleaned up is
	 * skipped here via the file_exists() guard in cleanup().
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Within-request cache of R2 key => restored local path, so multiple filter
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
		// Runs BEFORE the offloader's own wp_update_attachment_metadata hook (10):
		// repairs a metadata['file'] that a regenerate/edit left pointing into the
		// system temp dir (read-only-uploads fallback) before it can be persisted or
		// offloaded. See guard_temp_metadata().
		add_filter( 'wp_update_attachment_metadata', array( $this, 'guard_temp_metadata' ), 5, 2 );
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
	 * @return string The restored path, or $path unchanged when not offloaded.
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
		$dir = ( '.' === $dir ) ? '' : trailingslashit( $dir );
		$key = $dir . wp_basename( $path );
		$restored = $this->restore( $key, $path, (int) $attachment_id );
		return ( '' === $restored ) ? $path : $restored;
	}

	/**
	 * Provide a readable local path for an attachment's original, restoring it
	 * from R2 when it has been removed (Stateless mode). Restores to the canonical
	 * uploads location when writable (so derivatives written alongside it are
	 * captured), else to the system temp dir — see restore().
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
		$restored = $this->restore( $key, $file, (int) $attachment_id );
		return ( '' === $restored ) ? $file : $restored;
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
	 * Download an R2 object to a readable local path and return it.
	 *
	 * Restores to the CANONICAL uploads path when that directory is writable, so
	 * derivatives WordPress writes alongside it (regeneration / image editing) are
	 * picked up by the offloader and metadata['file'] stays correct (SWR-332);
	 * else to the system temp dir. The canonical write is atomic: download into a
	 * unique temp file in the target directory, then rename into place so a
	 * concurrent reader never sees a half-written canonical file.
	 *
	 * @param string $key           R2 object key.
	 * @param string $local_path    The canonical local path WordPress expects.
	 * @param int    $attachment_id For error context.
	 * @return string Restored path on success, '' on failure.
	 */
	private function restore( $key, $local_path, $attachment_id ) {
		// Reuse a file already restored for this key this request (and only if it
		// still exists — shutdown cleanup may not have run, but a stray unlink or
		// the offloader's own cleanup could have removed it).
		if ( isset( $this->restored[ $key ] ) && file_exists( $this->restored[ $key ] ) ) {
			return $this->restored[ $key ];
		}

		$target = $this->restore_target( $local_path );
		if ( '' === $target['download_to'] ) {
			// Temp-dir exhaustion / non-writable temp affects every stateless
			// restore, so surface it rather than failing silently.
			error_log( sprintf( 'r2offload: could not create a temp file to restore %s (attachment %d)', $key, $attachment_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}

		$restored = $this->client->download_object( $key, $target['download_to'] );
		if ( is_wp_error( $restored ) ) {
			if ( file_exists( $target['download_to'] ) ) {
				wp_delete_file( $target['download_to'] );
			}
			error_log( sprintf( 'r2offload: restore failed for %s (attachment %d): %s', $key, $attachment_id, $restored->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}

		$path = $target['download_to'];
		if ( $target['publish_to'] !== $target['download_to'] ) {
			// Atomically publish into the canonical uploads location. On the
			// (near-impossible, same-directory) rename failure, fail clean rather
			// than hand WordPress a temp-basename source: that would derive wrong
			// derivative names and a wrong metadata['file']. The caller then falls
			// back to the original missing path, so the op fails visibly instead of
			// silently corrupting.
			if ( ! @rename( $target['download_to'], $target['publish_to'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort publish; failure is handled below.
				if ( file_exists( $target['download_to'] ) ) {
					wp_delete_file( $target['download_to'] );
				}
				error_log( sprintf( 'r2offload: could not publish restored %s into %s', $key, $target['publish_to'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return '';
			}
			$path = $target['publish_to'];
		}

		$this->temp_files[]     = $path;
		$this->restored[ $key ] = $path;
		return $path;
	}

	/**
	 * Decide where to restore a file: the canonical uploads location when that
	 * directory is writable (preferred — derivatives land where the offloader
	 * looks), else the system temp dir. Returns the path to DOWNLOAD to and the
	 * final path to PUBLISH to (equal for the temp-dir case = no rename).
	 *
	 * @param string $local_path Canonical local path WordPress expects.
	 * @return array{download_to:string,publish_to:string}
	 */
	private function restore_target( $local_path ) {
		// wp_tempnam() is defined in wp-admin/includes/file.php, which is NOT
		// loaded on front-end requests. This restore path runs during front-end
		// image rendering (via the get_attached_file filter), so load the file
		// on demand — otherwise the unqualified call resolves to the namespaced
		// R2Offload\wp_tempnam() (and falls back to a non-existent global),
		// throwing a fatal that takes down the whole page.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$basename = wp_basename( $local_path );
		$dir      = dirname( $local_path );

		// Only ever mkdir/rename/cleanup at the canonical location when it is
		// genuinely INSIDE the uploads basedir. $local_path comes from the
		// filterable get_attached_file / load_image_to_edit_path value, so a
		// hostile or odd attachment path (or an upstream filter) could otherwise
		// point us to write/delete under wp-content/plugins, themes, or elsewhere.
		// Anything outside uploads falls back to the system temp dir.
		$use_uploads = apply_filters( 'r2offload_restore_to_uploads', true, $local_path );
		if ( $use_uploads && $this->within_uploads_basedir( $local_path ) && '.' !== $dir && wp_mkdir_p( $dir ) && wp_is_writable( $dir ) ) {
			$tmp = \wp_tempnam( $basename, $dir ); // Unique temp file IN the canonical directory.
			if ( $tmp ) {
				return array( 'download_to' => $tmp, 'publish_to' => $local_path );
			}
		}

		// Read-only / ephemeral uploads dir, outside uploads, or filtered off:
		// system temp dir. Reads work; derivatives written off this path are not
		// captured (SWR-332).
		$tmp = (string) \wp_tempnam( $basename );
		return array( 'download_to' => $tmp, 'publish_to' => $tmp );
	}

	/**
	 * Whether a path is genuinely inside the uploads basedir, with no parent
	 * traversal. Guards the canonical restore so a filtered/hostile attachment
	 * path can't make the plugin write or delete outside uploads (e.g. under
	 * plugins/themes — which WP.org guidelines forbid writing to anyway).
	 *
	 * @param string $path
	 * @return bool
	 */
	private function within_uploads_basedir( $path ) {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}
		// Decode percent-encoding and reject null bytes BEFORE the traversal checks
		// — purely for this safety DECISION (the actual restore still uses the
		// original $path). A hostile/buggy get_attached_file filter could otherwise
		// hide a "/../" behind %2e%2e%2f, or embed a %00 that fatals PHP 8's
		// filesystem calls; decoding first lets the literal checks below catch it,
		// falling back to the system temp dir instead.
		$path = wp_normalize_path( rawurldecode( (string) $path ) );
		if ( false !== strpos( $path, "\0" ) ) {
			return false;
		}
		// Reject any parent-traversal segment: a plain string prefix check would
		// let "<basedir>/../../x" pass while the OS resolves it outside uploads.
		if ( false !== strpos( $path . '/', '/../' ) ) {
			return false;
		}
		return 0 === strpos( $path, wp_normalize_path( trailingslashit( $uploads['basedir'] ) ) );
	}

	/**
	 * Safety net for Stateless mode on a READ-ONLY uploads dir (SWR-332).
	 *
	 * When uploads can't be written, restore() falls back to the system temp dir,
	 * so a `wp media regenerate` / in-admin edit runs against a temp path. WordPress
	 * then derives metadata['file'] from that path — an ABSOLUTE path outside uploads
	 * — and is about to persist it. Left alone, the attachment's canonical pointer
	 * would be corrupted and the temp file vanishes on shutdown (broken original).
	 *
	 * This repairs metadata['file'] back to the canonical uploads-relative path from
	 * `_wp_attached_file` (set at upload, never temp-backed) before the value is
	 * saved or the offloader sees it, and logs the failed derivative write. The newly
	 * generated sizes still can't be persisted on a read-only uploads dir — that's the
	 * inherent limitation — but the existing attachment is no longer corrupted.
	 *
	 * The trigger is deliberately tight: a normal metadata['file'] is always
	 * uploads-RELATIVE, so an absolute path that is NOT under the uploads basedir is
	 * an unambiguous signal of the temp-backed case and can't false-positive on a
	 * healthy save.
	 *
	 * @param mixed $metadata      Attachment metadata about to be saved.
	 * @param int   $attachment_id
	 * @return mixed
	 */
	public function guard_temp_metadata( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}
		$file = (string) $metadata['file'];
		// Healthy saves use an uploads-relative path; only an absolute path outside
		// uploads indicates the read-only-uploads temp fallback.
		if ( ! path_is_absolute( $file ) || $this->within_uploads_basedir( $file ) ) {
			return $metadata;
		}
		$canonical = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		if ( '' === $canonical || path_is_absolute( $canonical ) ) {
			// No trustworthy canonical pointer to fall back to — leave metadata as-is
			// rather than guess, but still surface the failure below.
			error_log( sprintf( 'r2offload: regenerate/edit wrote attachment %d to the system temp dir (read-only uploads in Stateless mode) and no canonical _wp_attached_file is available to repair metadata; use CDN mode or make uploads writable.', (int) $attachment_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $metadata;
		}
		$metadata['file'] = $canonical;
		error_log( sprintf( 'r2offload: regenerate/edit of attachment %d ran against a system-temp restore (read-only uploads in Stateless mode); restored the canonical metadata pointer but the new derivatives could not be stored. Use CDN mode or make uploads writable for in-place edits.', (int) $attachment_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return $metadata;
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

	/**
	 * Drop the per-request key/restore caches. Hooked on `switch_blog` (see
	 * Plugin): the key cache is keyed by attachment ID (not network-unique), so
	 * it must not carry across a blog switch. Restored temp files stay tracked
	 * in $temp_files for shutdown cleanup.
	 */
	public function flush_request_cache() {
		$this->key_cache = array();
		$this->restored  = array();
	}
}

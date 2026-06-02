<?php
/**
 * Offloader — pushes new uploads (original + every size) to R2.
 *
 * Mode-aware: CDN keeps local copies as a fallback; Stateless removes them.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Offloader {

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
	 * Hook into the media pipeline.
	 */
	public function register() {
		// Fires after WordPress has generated every registered size.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'offload' ), 10, 2 );
		// Mirror deletions to R2.
		add_action( 'delete_attachment', array( $this, 'delete' ) );
	}

	/**
	 * Offload an attachment's original + all sizes to R2.
	 *
	 * @param array $metadata      Attachment metadata (passes through unchanged).
	 * @param int   $attachment_id
	 * @return array
	 */
	public function offload( $metadata, $attachment_id ) {
		if ( ! $this->settings->is_configured() ) {
			return $metadata;
		}

		$files = $this->collect_files( $metadata, $attachment_id );
		if ( empty( $files ) ) {
			return $metadata;
		}

		$original_relative = isset( $metadata['file'] )
			? $metadata['file']
			: (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$original_key = $this->settings->object_key( $original_relative );

		$cache_control = $this->settings->get( 'cache_control' );
		$headers       = ( '' !== $cache_control ) ? array( 'Cache-Control' => $cache_control ) : array();
		$uploaded_paths   = array();
		$original_uploaded = false;
		$all_present       = true;

		foreach ( $files as $local_path => $key ) {
			if ( ! is_readable( $local_path ) ) {
				// A size file is missing locally (e.g. another stateless plugin
				// already removed it). Don't claim the attachment is fully
				// offloaded — the URL rewriter would 404 on that size.
				$all_present = false;
				continue;
			}
			$result = $this->client->upload_file( $local_path, $key, '', $headers );
			if ( is_wp_error( $result ) ) {
				// Leave local copies in place if any upload fails — never strand media.
				return $metadata;
			}
			$uploaded_paths[] = $local_path;
			if ( $key === $original_key ) {
				$original_uploaded = true;
			}
		}

		$already_synced = (bool) get_post_meta( $attachment_id, Settings::META_SYNCED, true );

		// Only mark the attachment offloaded once the ORIGINAL and every size
		// are in R2 — a stray size upload (or a skipped, missing variant) must
		// not flag media that isn't fully present.
		if ( $original_uploaded && $all_present ) {
			update_post_meta( $attachment_id, Settings::META_SYNCED, 1 );
			update_post_meta( $attachment_id, Settings::META_SYNCED_AT, time() );
			// Store the original's actual R2 key so readers resolve it
			// independently of the current path_prefix setting.
			update_post_meta( $attachment_id, Settings::META_KEY, $original_key );
		}

		// Stateless mode: drop the local copies we just uploaded — each is
		// confirmed in R2. Gate on the attachment being served from R2 (synced
		// now or already), NOT on $all_present: on a re-offload (e.g. thumbnail
		// regeneration) the original lives only in R2, so $all_present is false,
		// but newly generated size files must still be cleaned up. Never strip
		// locals for media that isn't synced — it's still served from disk.
		//
		// Also require a public serving URL: without a custom domain the URL
		// rewriter stays off and WordPress emits local /uploads URLs, so
		// deleting the local files would 404 the media. Keep the local copies
		// (CDN-like) until a custom domain is configured.
		if (
			'stateless' === $this->settings->get( 'mode' )
			&& $this->settings->serves_public_url()
			&& ( ( $original_uploaded && $all_present ) || $already_synced )
		) {
			foreach ( $uploaded_paths as $local_path ) {
				if ( file_exists( $local_path ) ) {
					wp_delete_file( $local_path );
				}
			}
		}

		return $metadata;
	}

	/**
	 * Remove an attachment's original + all sizes from R2.
	 *
	 * @param int $attachment_id
	 */
	public function delete( $attachment_id ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}
		foreach ( $this->r2_keys_for( $attachment_id ) as $key ) {
			$this->client->delete_object( $key );
		}
	}

	/**
	 * All R2 keys for an attachment (original + every size), resolved from the
	 * stored `_r2offload_key` so deletes still hit the right objects even if the
	 * path_prefix setting changed since upload. Falls back to the current
	 * path_prefix when no stored key exists.
	 *
	 * Returns nothing for an attachment this plugin never offloaded: deleting
	 * such an attachment must not issue R2 DELETEs for computed keys, which
	 * could remove objects the plugin hasn't claimed — e.g. media bulk-copied
	 * into R2 (Super Slurper) but not yet adopted via `wp r2offload sync`. This
	 * mirrors the `_r2offload_synced` guard in Settings::resolve_object_key().
	 *
	 * @param int $attachment_id
	 * @return string[]
	 */
	private function r2_keys_for( $attachment_id ) {
		if ( ! get_post_meta( $attachment_id, Settings::META_SYNCED, true ) ) {
			return array();
		}

		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$original = (string) get_post_meta( $attachment_id, Settings::META_KEY, true );
		if ( '' === $original ) {
			if ( '' === $relative ) {
				return array();
			}
			$original = $this->settings->object_key( $relative );
		}

		// Keys live in the stored original's directory (path_prefix may have
		// changed since sync), but the filename set comes from the shared
		// Settings::enumerate_files() helper so the delete path can't drift
		// from the upload/migrate paths. Seed with $original so it's always
		// included even for the degenerate empty-_wp_attached_file case.
		$dir = dirname( $original );
		$dir = ( '' === $dir || '.' === $dir ) ? '' : trailingslashit( $dir );

		$keys     = array( $original );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		foreach ( Settings::enumerate_files( $metadata, $relative ) as $file ) {
			$keys[] = $dir . $file['filename'];
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Build a map of local-path => R2-key for the original and every size.
	 *
	 * The R2 key is the file's path relative to the uploads dir
	 * (i.e. the `_wp_attached_file` value), so it maps 1:1 to WordPress's
	 * canonical location and the URL rewriter can reconstruct it.
	 *
	 * @param array $metadata
	 * @param int   $attachment_id
	 * @return array<string,string>  local_path => r2_key
	 */
	private function collect_files( $metadata, $attachment_id ) {
		$relative = isset( $metadata['file'] )
			? $metadata['file']
			: get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( ! $relative ) {
			return array();
		}

		$uploads = wp_get_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );

		// Shared enumeration (original + every registered size). Local path is
		// the uploads-relative path; the R2 key routes through object_key() to
		// apply the configured path_prefix.
		$files = array();
		foreach ( Settings::enumerate_files( $metadata, $relative ) as $file ) {
			$files[ $basedir . $file['relative'] ] = $this->settings->object_key( $file['relative'] );
		}

		return $files;
	}
}

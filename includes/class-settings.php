<?php
/**
 * Settings store with dual credential model.
 *
 * Each value resolves from a wp-config constant first (e.g. R2OFFLOAD_ACCESS_KEY),
 * falling back to the DB-stored option from the admin UI. When a constant is
 * defined, the UI displays "Defined in wp-config.php" and disables the field.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION_KEY = 'r2offload_settings';

	// Post-meta keys — single source of truth shared by the offloader,
	// migrator, URL rewriter, stateless read path and delete path.
	const META_SYNCED    = '_r2offload_synced';
	const META_SYNCED_AT = '_r2offload_synced_at';
	const META_KEY       = '_r2offload_key';
	// Cumulative list of R2 object keys this attachment owns (every key it has
	// uploaded or adopted, across edit history and path_prefix changes). The
	// delete path prefers this manifest so it never deletes an object another
	// attachment owns and never misses one (SWR-333). Absent on attachments
	// offloaded before it existed — the delete path falls back to deriving keys.
	const META_OBJECTS   = '_r2offload_objects';

	/**
	 * Map of setting key => wp-config constant name.
	 *
	 * @var array<string,string>
	 */
	private $constants = array(
		'account_id'    => 'R2OFFLOAD_ACCOUNT_ID',
		'access_key'    => 'R2OFFLOAD_ACCESS_KEY',
		'secret_key'    => 'R2OFFLOAD_SECRET_KEY',
		'bucket'        => 'R2OFFLOAD_BUCKET',
		'custom_domain' => 'R2OFFLOAD_CUSTOM_DOMAIN',
		'mode'          => 'R2OFFLOAD_MODE',
		'cache_control' => 'R2OFFLOAD_CACHE_CONTROL',
		'path_prefix'   => 'R2OFFLOAD_PATH_PREFIX',
	);

	/**
	 * Default values for settings without a constant or stored value.
	 *
	 * @var array<string,string>
	 */
	private $defaults = array(
		'mode'          => 'cdn', // Safe on-ramp. Flip to 'stateless' once verified.
		'cache_control' => 'public, max-age=31536000',
		'custom_domain' => '',
		'path_prefix'   => '', // e.g. 'uploads/'. Governs NEW uploads only.
	);

	/** @var array|null */
	private $stored = null;

	/**
	 * In-memory overrides that take precedence over the stored option (but never
	 * over a wp-config constant). Used to evaluate a connection test against
	 * unsaved form values without persisting them.
	 *
	 * @var array<string,string>
	 */
	private $overrides = array();

	/**
	 * Resolve a setting: in-memory override first, then DB, then wp-config
	 * constant, then default.
	 *
	 * DB wins over constants so values entered in the admin UI always take
	 * effect. The constant acts as a fallback for wp-config.php-only setups
	 * where nothing has been saved via the UI.
	 *
	 * @param string $key
	 * @return string
	 */
	public function get( $key ) {
		// In-memory overrides (unsaved form values for connection tests) always win.
		if ( array_key_exists( $key, $this->overrides ) ) {
			return $this->overrides[ $key ];
		}
		$stored = $this->stored();
		if ( isset( $stored[ $key ] ) && '' !== $stored[ $key ] ) {
			$value = (string) $stored[ $key ];
			if ( 'secret_key' === $key ) {
				// Migration: blobs written by the old encrypt_secret() path start with
				// 'r2enc:'. Decrypt transparently; if decryption fails (rotated salt)
				// decrypt() returns '' — fall through so a R2OFFLOAD_SECRET_KEY constant
				// can still serve as the active credential rather than leaving the plugin
				// unconfigured.
				$decrypted = $this->decrypt( $value );
				if ( '' !== $decrypted ) {
					return $decrypted;
				}
				// Decryption failed — fall through to constant / default below.
			} else {
				return $value;
			}
		}
		// Constant fallback — used when no DB value has been entered.
		if ( isset( $this->constants[ $key ] ) && defined( $this->constants[ $key ] ) ) {
			return (string) constant( $this->constants[ $key ] );
		}
		return isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : '';
	}

	/**
	 * Decrypt a stored secret. Values without the 'r2enc:' marker are returned
	 * as-is (plaintext). Returns '' when the blob cannot be decrypted (e.g. the
	 * auth salt was rotated) — never falls back to constants or defaults.
	 * Public so callers that need decrypt-only (no constant fallback) can use it
	 * directly.
	 *
	 * @param string $stored
	 * @return string
	 */
	public function decrypt( $stored ) {
		// No marker → legitimate legacy plaintext, return as-is.
		if ( 0 !== strpos( $stored, 'r2enc:' ) ) {
			return $stored;
		}
		// Marked as encrypted but we can't decrypt → surface, don't silently blank.
		$fail = function ( $why ) {
			error_log( 'r2offload: could not decrypt stored secret (' . $why . '). The site auth salt may have rotated — re-enter the Secret Access Key.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		};
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $fail( 'OpenSSL unavailable' );
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );

		// v2 — AES-256-GCM: iv(12) | tag(16) | ciphertext.
		if ( 0 === strpos( $stored, 'r2enc:v2:' ) ) {
			$raw = base64_decode( substr( $stored, 9 ), true );
			// Minimum valid GCM blob is iv(12) + tag(16) + 0-byte ciphertext = 28
			// bytes, so reject only when SHORTER than 28 (a 28-byte blob decrypts to
			// an empty string, which is valid).
			if ( false === $raw || strlen( $raw ) < 28 ) {
				return $fail( 'corrupt ciphertext' );
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return ( false === $plain ) ? $fail( 'wrong key or tampered' ) : $plain;
		}

		// v1 — AES-256-CBC: iv(16) | ciphertext (backward compatibility).
		$raw = base64_decode( substr( $stored, 6 ), true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return $fail( 'corrupt ciphertext' );
		}
		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return ( false === $plain ) ? $fail( 'wrong key' ) : $plain;
	}

	/**
	 * Resolve an offloaded attachment's original R2 key, or false when it isn't
	 * offloaded. Single source of truth shared by the URL rewriter, the
	 * stateless read path, and deletes.
	 *
	 * @param int $attachment_id
	 * @return string|false
	 */
	public function resolve_object_key( $attachment_id ) {
		// Require the synced flag for BOTH key sources: a stored key left behind
		// after the flag was cleared (manual cleanup, partial write) must not
		// make the rewriter or stateless read path serve from R2 for media that
		// isn't fully offloaded. Mirrors the guard in Offloader::r2_keys_for().
		if ( ! get_post_meta( $attachment_id, self::META_SYNCED, true ) ) {
			return false;
		}
		$key = (string) get_post_meta( $attachment_id, self::META_KEY, true );
		if ( '' === $key ) {
			$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( '' !== $file ) {
				$key = $this->object_key( $file );
			}
		}
		return ( '' === $key ) ? false : $key;
	}

	/**
	 * Whether a setting is currently being served from a wp-config constant
	 * (i.e. no DB value is overriding it). Returns false when a DB value is
	 * present, even if a constant is also defined — the DB wins in that case.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function is_constant( $key ) {
		if ( ! isset( $this->constants[ $key ] ) || ! defined( $this->constants[ $key ] ) ) {
			return false;
		}
		$stored = $this->stored();
		return ! ( isset( $stored[ $key ] ) && '' !== (string) $stored[ $key ] );
	}

	/**
	 * Build the canonical R2 object key for a path relative to the uploads dir.
	 *
	 * Single source of truth for key construction — offloader, migrator, URL
	 * rewriter and stream wrapper all route through this so they can never
	 * disagree. The `path_prefix` setting governs NEW keys only; existing media
	 * is resolved from its stored `_r2offload_key` (see SWR-313).
	 *
	 * @param string $relative e.g. '2017/03/the-kitsches.jpg'
	 * @return string e.g. 'uploads/2017/03/the-kitsches.jpg'
	 */
	public function object_key( $relative ) {
		$prefix   = ltrim( $this->get( 'path_prefix' ), '/' );
		$relative = ltrim( (string) $relative, '/' );
		if ( '' !== $prefix ) {
			$prefix = trailingslashit( $prefix );
		}
		return $prefix . $relative;
	}

	/**
	 * Add R2 object keys to an attachment's ownership manifest (META_OBJECTS),
	 * merged with any already recorded (cumulative across re-offloads, edits and
	 * path_prefix changes). Shared by the offloader and migrator so the delete
	 * path can reap exactly the objects an attachment owns — no more, no less
	 * (SWR-333). No-op for an empty key set.
	 *
	 * @param int      $attachment_id
	 * @param string[] $keys
	 */
	public static function record_objects( $attachment_id, array $keys ) {
		$attachment_id = (int) $attachment_id;
		$keys          = self::normalize_object_keys( $keys );
		if ( empty( $keys ) ) {
			return;
		}
		$existing = get_post_meta( $attachment_id, self::META_OBJECTS, true );
		$existing = is_array( $existing ) ? $existing : array();
		$merged   = array_values( array_unique( array_merge( $existing, $keys ) ) );
		// Skip the write when nothing changed, to avoid churning post-meta on every
		// idempotent re-offload.
		if ( count( $merged ) === count( $existing ) && array() === array_diff( $merged, $existing ) ) {
			return;
		}
		update_post_meta( $attachment_id, self::META_OBJECTS, $merged );
	}

	/**
	 * Normalize an object-key list: cast every entry to a string, drop empties,
	 * de-duplicate, and re-index. Shared by record_objects() (write side) and the
	 * offloader's manifest read so both treat the manifest identically.
	 *
	 * @param mixed $keys
	 * @return string[]
	 */
	public static function normalize_object_keys( $keys ) {
		if ( ! is_array( $keys ) ) {
			return array();
		}
		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $keys ),
					static function ( $key ) {
						return '' !== $key;
					}
				)
			)
		);
	}

	/**
	 * Enumerate an attachment's files — the original plus every registered size
	 * — as uploads-relative paths. Single source of truth so the offloader,
	 * migrator and delete path can never disagree on which files an attachment
	 * comprises. The original is always first.
	 *
	 * @param array|false $metadata Attachment metadata (wp_get_attachment_metadata() returns false for missing/corrupt data, hence the is_array guards below).
	 * @param string      $relative Original `_wp_attached_file` path.
	 * @return array<int,array{relative:string,size:string,filename:string}>
	 */
	public static function enumerate_files( $metadata, $relative ) {
		$relative = (string) $relative;
		if ( '' === $relative ) {
			return array();
		}

		// dirname() returns '.' for a bare filename and never '' — only the '.'
		// arm is reachable.
		$dir = dirname( $relative );
		$dir = ( '.' === $dir ) ? '' : trailingslashit( $dir );

		$files = array(
			array(
				'relative' => $relative,
				'size'     => '',
				'filename' => wp_basename( $relative ),
			),
		);

		// Track files already listed so the same physical file isn't emitted twice:
		// two registered sizes with identical dimensions share one generated file,
		// and a size can coincide with the original / original_image. Duplicates
		// would mean redundant HEAD/upload work during migration.
		$seen = array( $relative => true );

		// Big-image uploads (WP 5.3+): the attachment points at the down-scaled
		// "-scaled" file via _wp_attached_file, while the untouched full-res
		// original is kept alongside it and named in metadata['original_image'].
		// Include it so it's offloaded/migrated/deleted with everything else.
		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) ) {
			$orig     = (string) $metadata['original_image'];
			$orig_rel = $dir . $orig;
			if ( ! isset( $seen[ $orig_rel ] ) ) {
				$seen[ $orig_rel ] = true;
				$files[]           = array(
					'relative' => $orig_rel,
					'size'     => 'original_image',
					'filename' => wp_basename( $orig ), // Key is a sibling basename; see the sizes loop.
				);
			}
		}

		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$size_file = (string) $size_data['file'];
				$rel       = $dir . $size_file;
				if ( isset( $seen[ $rel ] ) ) {
					continue;
				}
				$seen[ $rel ] = true;
				$files[]      = array(
					'relative' => $rel,
					'size'     => (string) $size_name,
					// R2 keys are always siblings in the original's directory (the
					// rewriter rebuilds them as <original dir>/<basename>), so a size
					// 'file' carrying a directory component — some third-party size
					// generators emit subpaths, and core supports them via
					// path_join(dirname(file), size['file']) — must collapse to its
					// basename for the key, or the upload key keeps the subdir while
					// the render/delete keys drop it (orphaned object + 404 in
					// Stateless). 'relative' keeps the subpath so the LOCAL file is
					// still found.
					'filename' => wp_basename( $size_file ),
				);
			}
		}

		return $files;
	}

	/**
	 * Is the plugin fully configured to talk to R2?
	 *
	 * @return bool
	 */
	public function is_configured() {
		foreach ( array( 'account_id', 'access_key', 'secret_key', 'bucket' ) as $required ) {
			if ( '' === $this->get( $required ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Can offloaded media be served over a public URL? Only when a custom
	 * domain is set: R2's S3 API endpoint requires SigV4 auth and 403s the
	 * unauthenticated GETs browsers make for <img>/srcset, so without a custom
	 * domain there is no public URL to rewrite to.
	 *
	 * @return bool
	 */
	public function serves_public_url() {
		return '' !== $this->public_base_url();
	}

	/**
	 * The normalized public base URL for the configured custom domain — scheme +
	 * host (+ any subpath), no trailing slash — or '' when no USABLE domain is set.
	 *
	 * Centralizes the messy ways a domain can be entered (scheme-less, http/https,
	 * trailing slash, protocol-relative `//host`) so serves_public_url() and the
	 * object-URL builder always agree. Crucially it rejects a value with no real
	 * host (e.g. a bare `https://`, or a stray/whitespace wp-config constant that
	 * bypasses UI sanitization): otherwise serves_public_url() would be true and
	 * the rewriter would emit host-less broken URLs for EVERY image — catastrophic
	 * in Stateless mode, where the local files have already been deleted.
	 *
	 * @return string e.g. 'https://cdn.example.com' or 'https://cdn.example.com/media', or ''.
	 */
	public function public_base_url() {
		$domain = trim( (string) $this->get( 'custom_domain' ) );
		if ( '' === $domain ) {
			return '';
		}
		$domain = (string) preg_replace( '#^/+#', '', $domain ); // Drop protocol-relative / stray leading slashes.
		if ( ! preg_match( '#^https?://#i', $domain ) ) {
			$domain = 'https://' . $domain;                      // Default to https when no scheme was given.
		}
		$domain = rtrim( $domain, '/' );
		$host   = wp_parse_url( $domain, PHP_URL_HOST );
		return ( is_string( $host ) && '' !== $host ) ? $domain : '';
	}

	/**
	 * True when a secret is stored in the DB but no longer decrypts (e.g. the
	 * site's auth salt was rotated). The plugin then behaves as unconfigured;
	 * this lets the admin UI say *why* instead of silently failing.
	 *
	 * @return bool
	 */
	public function secret_decrypt_failed() {
		if ( $this->is_constant( 'secret_key' ) ) {
			return false; // Constant is plaintext — never "undecryptable".
		}
		$stored = $this->stored();
		$raw    = isset( $stored['secret_key'] ) ? (string) $stored['secret_key'] : '';
		if ( '' === $raw ) {
			return false; // Nothing stored.
		}
		return '' === $this->decrypt( $raw ); // Stored, but the blob cannot be decrypted.
	}

	/**
	 * Drop the memoised option so the next read re-loads it. Hooked on
	 * `switch_blog` (see Plugin) — settings (bucket, custom_domain, path_prefix,
	 * mode, credentials) are per-site, so a request that switches blogs must not
	 * keep resolving against the first site's settings.
	 */
	public function flush_request_cache() {
		$this->stored = null;
	}

	/**
	 * Seed in-memory overrides for known setting keys. Use on a THROWAWAY Settings
	 * instance (never the shared one) to evaluate a connection test against unsaved
	 * form values. Unknown keys and non-scalars are ignored.
	 *
	 * @param array<string,mixed> $overrides key => value.
	 */
	public function set_overrides( array $overrides ) {
		foreach ( $overrides as $key => $value ) {
			if ( array_key_exists( $key, $this->constants ) && is_scalar( $value ) ) {
				$this->overrides[ $key ] = (string) $value;
			}
		}
	}

	/**
	 * Lazy-load the stored option.
	 *
	 * @return array
	 */
	private function stored() {
		if ( null === $this->stored ) {
			$this->stored = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $this->stored ) ) {
				$this->stored = array();
			}
		}
		return $this->stored;
	}

	/**
	 * Register the admin settings page (SWR-309).
	 */
	public function register() {
		( new Admin_Settings( $this ) )->register();
	}
}

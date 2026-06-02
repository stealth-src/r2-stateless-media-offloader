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
	 * Resolve a setting: constant first, then DB, then default.
	 *
	 * @param string $key
	 * @return string
	 */
	public function get( $key ) {
		if ( isset( $this->constants[ $key ] ) && defined( $this->constants[ $key ] ) ) {
			return (string) constant( $this->constants[ $key ] );
		}
		$stored = $this->stored();
		if ( isset( $stored[ $key ] ) && '' !== $stored[ $key ] ) {
			return (string) $stored[ $key ];
		}
		return isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : '';
	}

	/**
	 * Whether a setting is locked by a wp-config constant.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function is_constant( $key ) {
		return isset( $this->constants[ $key ] ) && defined( $this->constants[ $key ] );
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

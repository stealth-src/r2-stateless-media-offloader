<?php
/**
 * Main plugin orchestrator.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin's components together.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	private $settings;

	/** @var R2_Client */
	private $client;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Initialise components. Called on plugins_loaded.
	 */
	public function init() {
		$this->settings = new Settings();
		$this->client   = new R2_Client( $this->settings );

		// Admin UI.
		if ( is_admin() ) {
			$this->settings->register();
		}

		// Offload new uploads to R2 (original + all sizes).
		( new Offloader( $this->client, $this->settings ) )->register();

		// Serve offloaded media from R2 / the custom domain (render-time).
		( new URL_Rewriter( $this->client, $this->settings ) )->register();

		// Stateless read path: restore files from R2 on demand for image ops.
		( new Local_Fallback( $this->client, $this->settings ) )->register();

		// WP-CLI commands (loads its own guard).
		require_once R2OFFLOAD_PLUGIN_DIR . 'includes/class-cli.php';
	}

	/**
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * @return R2_Client
	 */
	public function client() {
		return $this->client;
	}
}

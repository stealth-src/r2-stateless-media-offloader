<?php
/**
 * WP-CLI commands.
 *
 * @package R2Offload
 */

namespace R2Offload;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage R2 Stateless Media Offload from the command line.
 */
class CLI {

	/**
	 * Verify the R2 connection and round-trip an object (upload, head, list, delete).
	 *
	 * This is the validation gate for the SigV4 client (SWR-304).
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2offload test
	 *
	 * @when after_wp_load
	 */
	public function test( $args, $assoc_args ) {
		$client   = Plugin::instance()->client();
		$settings = Plugin::instance()->settings();

		if ( ! $settings->is_configured() ) {
			\WP_CLI::error( 'R2 not configured. Set R2OFFLOAD_* constants in wp-config.php or via settings.' );
		}

		\WP_CLI::log( 'Bucket:   ' . $settings->get( 'bucket' ) );
		\WP_CLI::log( 'Endpoint: ' . $settings->get( 'account_id' ) . '.r2.cloudflarestorage.com' );
		\WP_CLI::log( '' );

		// 1. Connection.
		\WP_CLI::log( '1/5 test_connection ...' );
		$res = $client->test_connection();
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( 'Connection failed: ' . $res->get_error_message() );
		}
		\WP_CLI::success( 'Connected.' );

		// 2. Upload.
		$key = 'r2offload-test/' . gmdate( 'Ymd-His' ) . '.txt';
		$tmp = wp_tempnam( 'r2offload-test' );
		file_put_contents( $tmp, "r2offload round-trip test\n" ); // phpcs:ignore
		\WP_CLI::log( "2/5 upload  -> {$key}" );
		$res = $client->upload_file( $tmp, $key, 'text/plain', array( 'Cache-Control' => 'no-store' ) );
		wp_delete_file( $tmp );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( 'Upload failed: ' . $res->get_error_message() );
		}
		\WP_CLI::success( 'Uploaded.' );

		// 3. Exists.
		\WP_CLI::log( '3/5 head    (object_exists)' );
		if ( ! $client->object_exists( $key ) ) {
			\WP_CLI::error( 'object_exists returned false for an object we just uploaded.' );
		}
		\WP_CLI::success( 'Exists.' );

		// 4. List.
		\WP_CLI::log( '4/5 list    (prefix r2offload-test/)' );
		$list = $client->list_objects( 'r2offload-test/', 10 );
		if ( is_wp_error( $list ) ) {
			\WP_CLI::error( 'List failed: ' . $list->get_error_message() );
		}
		\WP_CLI::success( 'Listed ' . count( $list['keys'] ) . ' object(s).' );

		// 5. Delete.
		\WP_CLI::log( '5/5 delete' );
		$res = $client->delete_object( $key );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( 'Delete failed: ' . $res->get_error_message() );
		}
		\WP_CLI::success( 'Deleted.' );

		\WP_CLI::log( '' );
		\WP_CLI::success( 'R2_Client validation gate PASSED — SigV4 round-trip works. 🎉' );
		\WP_CLI::log( 'Public URL would be: ' . $client->get_object_url( $key ) );
	}
}

\WP_CLI::add_command( 'r2offload', __NAMESPACE__ . '\\CLI' );

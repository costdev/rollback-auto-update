<?php
/**
 * Rollback Auto Update
 *
 * @author  Andy Fragen, Colin Stewart
 * @license MIT
 * @link    https://github.com/afragen/rollback-auto-update
 * @package rollback-auto-update
 */

/**
 * Rollback an auto-update containing an activation error.
 *
 * @package Rollback_Auto_Update
 *
 * Plugin Name:       Rollback Auto-Update
 * Plugin URI:        https://github.com/afragen/rollback-auto-update
 * Description:       Rollback an auto-update containing an activation error.
 * Version:           0.7.0
 * Author:            WP Core Contributors
 * License:           MIT
 * Requires at least: 5.9
 * Requires PHP:      5.6
 * GitHub Plugin URI: https://github.com/afragen/rollback-auto-update
 * Primary Branch:    main
 */

namespace Fragen;

/*
 * Exit if called directly.
 * PHP version check and exit.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Auto_Update_Failure_Check
 */
class Rollback_Auto_Update {
	/**
	 * Constructor, let's get going.
	 */
	public function __construct() {
		add_filter( 'upgrader_install_package_result', [ $this, 'auto_update_check' ], 15, 2 );
	}

	/**
	 * Checks the validity of the updated plugin.
	 *
	 * @param array|WP_Error $result     Result from WP_Upgrader::install_package().
	 * @param array          $hook_extra Extra arguments passed to hooked filters.
	 *
	 * @return array|WP_Error The result from WP_Upgrader::install_package(), or a WP_Error object.
	 */
	public function auto_update_check( $result, $hook_extra ) {
		if ( is_wp_error( $result ) || ! wp_doing_cron() || ! isset( $hook_extra['plugin'] ) ) {
			return $result;
		}

		$plugin = $hook_extra['plugin'];

		if ( 'rollback-auto-update/rollback-auto-update.php' === $plugin ) {
			return $result;
		}
		
		$errors = $this->check_plugin_for_errors( $plugin );

		return is_wp_error( $errors ) ? $errors : $result;
	}

	/**
	 * Checks a new plugin version for errors.
	 * 
	 * If an error is found, the previously installed version will be reinstalled
	 * and an email will be sent to the site administrator.
	 *
	 * @param string $plugin The plugin to check.
	 * 
	 * @return WP_Error|true A WP_Error object if an error occured, otherwise true.
	 */
	private function check_plugin_for_errors( $plugin ) {
		$errors   = false;
		$nonce    = wp_create_nonce( 'plugin-activation-error_' . $plugin );
		$response = wp_remote_get(
			add_query_arg(
				array(
					'action'   => 'error_scrape',
					'plugin'   => $plugin,
					'_wpnonce' => $nonce,
				),
				admin_url( 'plugins.php' )
			)
		);

		if ( is_wp_error( $response ) ) {
			// If it isn't possible to run the check, assume all is well.
			return $errors;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( str_contains( $body, 'wp-die-message' ) ) {
			$this->cron_rollback( $plugin );
			$this->send_fatal_error_email( $plugin );

			$errors = new \WP_Error(
				'new_version_error',
				sprintf(
					/* translators: %s: The name of the plugin. */
					__( 'The new version of %s contains an error' ),
					\get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Name']
				)
			);
		}

		return $errors;
	}

	/**
	 * Rolls back during cron.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 * 
	 * @param $plugin The plugin that should be rolled back.
	 */
	private function cron_rollback( $plugin ) {
		global $wp_filesystem;

		$temp_backup = [
			'temp_backup' => [
				'dir'  => 'plugins',
				'slug' => dirname( $plugin ),
				'src'  => $wp_filesystem->wp_plugins_dir(),
			],
		];

		include_once $wp_filesystem->wp_plugins_dir() . 'rollback-update-failure/wp-admin/includes/class-wp-upgrader.php';
		$rollback_updater = new \Rollback_Update_Failure\WP_Upgrader();

		// Set private $temp_restores variable.
		$ref_temp_restores = new \ReflectionProperty( $rollback_updater, 'temp_restores' );
		$ref_temp_restores->setAccessible( true );
		$ref_temp_restores->setValue( $rollback_updater, $temp_backup );

		// Set private $temp_backups variable.
		$ref_temp_backups = new \ReflectionProperty( $rollback_updater, 'temp_backups' );
		$ref_temp_backups->setAccessible( true );
		$ref_temp_backups->setValue( $rollback_updater, $temp_backup );

		// Call Rollback's restore_temp_backup().
		$restore_temp_backup = new \ReflectionMethod( $rollback_updater, 'restore_temp_backup' );
		$restore_temp_backup->invoke( $rollback_updater );

		// Call Rollback's delete_temp_backup().
		$delete_temp_backup = new \ReflectionMethod( $rollback_updater, 'delete_temp_backup' );
		$delete_temp_backup->invoke( $rollback_updater );
	}

	/**
	 * Sends an email to the site administrator when a plugin
	 * new version contains a fatal error.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem subclass.
	 * 
	 * @param string $plugin The plugin that has an error in the new version.
	 */
	private function send_fatal_error_email( $plugin ) {
		global $wp_filesystem;

		$plugin_path = $wp_filesystem->wp_plugins_dir() . $plugin;
		$name        = \get_plugin_data( $plugin_path )['Name'];
		$subject     = sprintf(
			/* translators: %s: The site name. */
			__( '[%s] A plugin was rolled back to the previously installed version' ),
			get_bloginfo( 'name' )
		);
		$body        = sprintf(
			__( 'Howdy!' ) . "\n\n" .
			/* translators: 1: The name of the plugin or theme. 2: Home URL. */
			__( '%1$s was successfully updated on your site at %2$s.' ) . "\n\n" .
			/* translators: 1: The name of the plugin or theme. */
			__( 'However, due to a fatal error, %1$s, was reverted to the previously installed version. If a new version is released without fatal errors, it will be installed automatically.' ) . "\n\n" .
			$name,
			home_url()
		);

		$body .= "\n\n" . __( 'The WordPress Rollback Team' ) . "\n";

		wp_mail( get_bloginfo( 'admin_email' ), $subject, $body );
	}
}

new Rollback_Auto_Update();

<?php
/**
 * PSR-4 style autoloader for KonX Affiliate Dashboard classes.
 *
 * Maps class names with the Konx_ prefix to file paths following
 * WordPress naming convention: Konx_Some_Class -> class-konx-some-class.php
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Autoloader
 */
class Konx_Autoloader {

	/**
	 * Directories to search for class files.
	 *
	 * @var array
	 */
	private static $directories = array();

	/**
	 * Register the autoloader with spl_autoload_register.
	 */
	public static function register() {
		self::$directories = array(
			KONX_AFFILIATE_PLUGIN_DIR . 'includes/',
			KONX_AFFILIATE_PLUGIN_DIR . 'admin/',
			KONX_AFFILIATE_PLUGIN_DIR . 'public/',
		);

		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload a class file by class name.
	 *
	 * Only handles classes with the Konx_ prefix.
	 * Converts Konx_Some_Class to class-konx-some-class.php.
	 *
	 * @param string $class_name The fully qualified class name.
	 */
	public static function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Konx_' ) ) {
			return;
		}

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		foreach ( self::$directories as $directory ) {
			$file_path = $directory . $file_name;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}
	}
}

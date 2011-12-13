<?php

// Helium
// Core class

// Database:	Helium::db()
// Config:		Helium::conf([name])

// what does the core class do?
// - provide a global namespace to access essential singletons

final class Helium {
	const version = '0.3b';
	const build = 'helium';

	public static $autoload = true;

	// debug variables
	public static $production = false; // set to false to print out debug info on exceptions
	public static $output = true; // set to false to disable output -- currently not implemented, actually.

	private static $factory_cache = array();

	private static $conf;
	private static $router;

	private static $db;
	private static $db_handler_name = 'HeliumDB';

	public static $request = '';
	public static $controller = '';
	public static $action = '';
	public static $params = array();
	public static $controller_object;

	private function __construct() {}

	private function __clone() {}

	public static function init($routes_file = '') {
		static $initiated = false;
		if ($initiated)
			return;

		// fetch the config
		self::conf();

		// reset the router
		self::$router = new HeliumRouter;

		if (!$routes_file)
			$routes_file = self::conf('app_path') . '/routes.php';
		if (!self::$router->load_routes_file($routes_file))
			throw new HeliumException(HeliumException::no_routes_file, $routes_file);

		self::$router->parse_request();
		self::$request = &self::$router->request;
		self::$controller = &self::$router->controller;
		self::$action = &self::$router->action;
		self::$params = &self::$router->params;

		// load the controller and execute it
		$controller_object = self::factory('controller', self::$router->controller);
		$controller_object->action = self::$router->action;
		$controller_object->params = self::$router->params;
		self::$controller_object = $controller_object;

		self::sanitize_GPC();
		$controller_object();
	}

	// singletons

	public static function conf($var = '') {
		if (!self::$conf) {
			$config_file = HELIUM_APP_PATH . '/config.php';
			if (file_exists($config_file)) {
				require_once $config_file;
				self::$conf = new HeliumConfiguration;
			}
			else
				self::$conf = new HeliumDefaults;
		}
			

		if ($var)
			return self::$conf->$var;
		else
			return self::$conf;
	}

	public static function router() {
		return self::$router;
	}

	public static function db() {
		if (!self::$db) {
			self::$db = new self::$db_handler_name;
			self::$db->db_user = self::conf('db_user');
			self::$db->db_pass = self::conf('db_pass');
			self::$db->db_host = self::conf('db_host');
			self::$db->db_name = self::conf('db_name');
		}

		return self::$db;
	}

	public static function set_db_handler($dbh = 'HeliumDB') {
		if (is_object($dbh))
			self::$db = $dbh;
		elseif (is_string($dbh))
			self::$db_handler_name = $dbh;
	}

	// App-handling methods

	public static function get_app_file_path($directory, $filename) {
		$base_path = self::conf($directory . '_path');

		return $base_path . '/' . $filename . '.php';
	}

	// Load an app's file.
	// Since we're not in the global scope, this function is only useful
	// for files that only contain class definitions.
	public static function load_app_file($directory, $filename) {
		$full_path = self::get_app_file_path($directory, $filename);

		if (file_exists($full_path)) {
			require_once $full_path;
			return true;
		}
		else
			return false;
	}

	public static function load_helium_file($helium_component) {
		require_once HELIUM_PATH . '/' . $helium_component . '.php';
	}

	// Load the definition of a class by searching appropriate directories
	// Used for __autoload();
	public static function load_class_file($class_name, $extend_search = true) {
		if ($class_name == 'Inflector') {
			self::load_helium_file('inflector');
			return;
		}

		if (strtolower(substr($class_name, 0, 6)) == 'helium') {
			if ($class_name == 'HeliumConfiguration')
				self::conf();
			else {
				$helium_component = substr($class_name, 6);
				$filename = Inflector::underscore($helium_component);
				self::load_helium_file($filename);
			}
			return;
		}

		$filename = Inflector::underscore($class_name);
		$last_underscore = strrpos($filename, '_');
		$last_word = substr($filename, $last_underscore + 1);

		switch($last_word) {
			case 'controller':
			case 'component':
			case 'helper':
				// there can only be one instance of a controller, component, or helper at a time.
				// thus, we can use Helium::factory() instead.
				$dir = Inflector::pluralize($last_word);
				$success = self::load_app_file($dir, $filename);
				if (!$extend_search)
					break;
			default:
				$search = array('models', 'includes');
				foreach ($search as $dir) {
					$success = self::load_app_file($dir, $filename);
					if ($success)
						break;
				}
		}

		if ($success)
			return true;
		elseif ($extend_search)
			return false;
			// throw new HeliumException(HeliumException::no_class, $class_name);
		else
			return false;
	}

	// Generate an instance of an app class and throw an appropriate exception if it is not found.
	// This is different to just __autoload()ing which only throws a no_class exception.
	public static function factory($type, $name) {
		$joined = $name . '_' . $type;

		if ($object = self::$factory_cache[$joined])
			return $object;

		$class_name = Inflector::camelize($joined);
		$directory = Inflector::pluralize($type);

		// the second parameter is whether or not we should look for the class in other directories.
		// in this case, we shouldn't.
		$try = self::load_class_file($class_name, false);

		// If the class isn't defined anywhere it may be, then throw an exception
		if (!$try)
			throw new HeliumException(constant('HeliumException::no_' . $type), $name);

		$object = self::$factory_cache[$joined] = new $class_name;

		return $object;
	}

	// some useful, generic functions

	public static function numval($number) {
		$try = intval($number);
		if ($try >= 2147483647 || $try <= -2147483648) // php's limit
			$try = floatval($number);
		if ($try >= 1.0e+18 || $try <= -1.0e+18)
			$try = $number;
		return $try;
	}

	// recursively strip slashes.
	// taken from WordPress.
	public static function stripslashes_deep($value) {
		$value = is_array($value) ?
					array_map(__METHOD__, $value) :
					stripslashes($value);

		return $value;
	}
	
	public static function sanitize_GPC() {
		if (get_magic_quotes_gpc()) {
			$_GET = self::stripslashes_deep($_GET);
			$_POST = self::stripslashes_deep($_POST);
			$_COOKIE = self::stripslashes_deep($_COOKIE);
		}
	}

}
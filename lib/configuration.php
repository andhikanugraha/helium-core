<?php

// Helium framework
// class HeliumConfiguration
// global $conf;

if (!defined('CONF_PATH'))
	define('CONF_PATH', HE_PATH . '/conf');

class HeliumConfiguration {
	private $__loaded = array();
	private $__array_behaviour = array('paths' => 0,		// replace/append
									   'routes' => 1,		// prepend
									   'backroutes' => 0);	// append

	// execution flags
	public $output = true;
	public $production = true;
	public $canonize = true;

	public $db_type = 'mysql';

	public $db_user = '';
	public $db_pass = '';
	public $db_host = 'localhost';
	public $db_name = '';

	public $paths = array('views' => '/views',
						  'controllers' => '/controllers',
						  'models' => '/models',
						  'plugins' => '/plugins');

	public $routes = array();
	public $backroutes = array();
	public $default_controller = 'home';
	public $default_action = 'index';

	public $load_plugins = true;
	public $plugins = array();
	
	public function __construct() {
		foreach ($this->paths as $key => $value) {
			$this->paths[$key] = HE_PATH . $value;
		}
	}

	public function load($name) {
		if (strpos($name, '/') !== false)
			return;

		$path = CONF_PATH . "/$name.php";
		if (!file_exists($path))
			return;

		$this->load_file($path);
	}
	
	private function load_file($__path) {
		if (!file_exists($__path))
			return;
		
		require_once $__path;
		
		$this->__loaded[] = $__path;

		foreach (array_keys(get_object_vars($this)) as $__key) {
			if (!isset($$__key))
				continue;

			$value = $$__key;
			if (is_array($value)) {
				switch ($this->__array_behaviour[$__key]) {
					case 2:
						$this->$__key = array_merge($value, $this->$__key);
						break;
					default:
						$this->$__key = array_merge($this->$__key, $value);
				}
			}
			else
				$this->$__key = $$__key;
		}
	}
}
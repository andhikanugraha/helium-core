<?php

// HeliumController
// the C in Helium's MVC.

// The idea:
// A controller is a group of actions that relate to a particular group of data.
// An action is a set of program logic that deals with a certain aspect of data.
// Output to the user is handled through the use of views,
// of which the view that is going to be used is determined by the controller
// and also include()d by the controller.

// How Helium handles controllers:
// every *public*, non-magic method in a Controller represents an Action.
// when the user makes a request, Helium will call the controller class as a function (__invoke).
// __invoke will do two things:
// 1. call the method that corresponds to the function
// 2. include() the viewport, thus maintaining object scope.

// goal:
// to not pollute the controller object with public methods
// public methods should always be for actions and nothing else.

// share the scope of set() and vars
abstract class HeliumControllerSupport {
	
	protected $vars = array();

	protected function set($name, $value) {
		$this->vars[$name] = $value;
	}

}

abstract class HeliumController {

	public $components = array();
	public $helpers = array();

	private $component_objects = array();

	public $render = true; // true if view has been or should be loaded; false otherwise.

	public $action;
	public $params;

	public $default_action = 'index';

	private $view_path;

	public function __construct() {
		// load components
		foreach ($this->components as $component) {
			$this->$component = Helium::factory('component', $component);
			$this->$component->controller_object = $this;
			$this->$component->init($this);
		}

		$this->init();
	}

	protected function init() {}

	public function __invoke() {
		$action = $this->action();

		/* validation */

		$might_be_valid_action = !in_array($action, $invalid_actions) && ($action[0] != '_');
		if (method_exists($this, $action) && $might_be_valid_action) {
			$method_reflection = new ReflectionMethod($this, $action);
			$is_valid_action = $method_reflection->isPublic();
		}
		else
			$is_valid_action = false;

		if (!$is_valid_action)
			throw new HeliumException(HeliumException::no_action);

		/* execution */

		// the action and view exists. everything is safe.
		$this->$action($this->params);

		if ($this->render)
			$this->render();
	}

	public function __call($name, $arguments) {
		$function = $this->$name;

		if (is_callable($function))
			call_user_func_array($function, $arguments);
	}

	protected function render($view = '') {
		$controller_class_name = get_class($this);
		$controller_underscore_name = Inflector::underscore($controller_class_name);
		$controller = substr($controller_underscore_name, 0, strlen($controller_underscore_name) - 11); // cut off the _controller part.

		$action = $this->action();

		if (!$view)
			$view = $controller . '/' . $action;

		$view_path = Helium::get_app_file_path('views', $view);
		if (!file_exists($view_path))
			throw new HeliumException(HeliumException::no_view);

		$this->view_path = $view_path;

		// unset 'unnecessary' variables
		unset($controller_underscore_name, $controller_class_name, $view, $view_path);

		// load variables
		foreach ($this->vars as $var => $value)
			$$var = $value;

		// load helpers
		foreach ($this->helpers as $helper) {
			$$helper = Helium::factory('helper', $helper);
			$$helper->controller_object = $this;
			$$helper->init($this);
		}

		include_once $this->view_path; // include is enough, we don't want fatal errors here.

		$this->render = false;
	}

	protected function action() {
		return $this->action ? $this->action : $this->default_action;
	}

	public function index() {}

}
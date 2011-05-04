<?php

function Helium_autoload($class_name) {
	if (Helium::$autoload)
		return Helium::load_class_file($class_name, true);
}

spl_autoload_register('Helium_autoload');
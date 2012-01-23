<?php

// HeliumDateTime
// A wrapper class for DateTime with additional features:
// * year, month, date, hour, minute(s), second(s) properties
// * __toString() for string typecasting returns the mysql formatted string.
// * a mysql_datetime() property

class HeliumDateTime extends DateTime {
	const MYSQL = 'Y-m-d H:i:s';
	const zero_date = '0000-00-00';
	const zero_datetime = '0000-00-00 00:00:00';
	
	public static $locales = array('en' => array());
	public static $default_locale = 'en';
	public static $timezone = 'UTC';
	
	private $zero = false;

	public $translations = array();

	public function __construct($time = 'now', $timezone = null) {
		if (!$timezone) {
			$timezone = new DateTimeZone(self::$timezone);
		}
		elseif (is_string($timezone)) {
			$timezone = new DateTimeZone($timezone);
		}

		$this->set_locale(self::$default_locale);
		
		if ($time == self::zero_date || $time == self::zero_datetime)
			$this->zero = true;

		parent::__construct($time, $timezone);
	}
	
	public function setDate($year, $month, $day) {
		if ($this->zero && $month && $day)
			$this->zero = false;

		parent::setDate($year, $month, $day);
	}
	
	public function setISODate($year, $week, $day = 1) {
		if ($this->zero && $week && $day)
			$this->zero = false;

		parent::setISODate($year, $month, $day);
	}
	
	public function setTimestamp($unixtimestamp) {
		if ($this->zero)
			$this->zero = false;

		parent::setTimestamp($unixtimestamp);
	}

	public function mysql_datetime() {
		if ($this->zero)
			return self::zero_datetime;
		else
			// Using parent:: would be faster
			return parent::format(self::MYSQL);
	}

	public function __toString() {
		return $this->mysql_datetime();
	}

	public function __get($name) {
		switch ($name) {
			case 'year':
				return (int) $this->format('Y');
			case 'month':
				return (int) $this->format('m');
			case 'day':
				return (int) $this->format('d');
			case 'hour':
				return (int) $this->format('H');
			case 'minutes':
			case 'minute':
				return (int) $this->format('i');
			case 'seconds':
			case 'second':
				return (int) $this->format('s');
			default:
				return (int) $this->format($name);
		}
	}

	public function __set($name, $value) {
		switch ($name) {
			case 'year':
				return $this->setDate($value, $this->month, $this->day);
			case 'month':
				return $this->setDate($this->year, $value, $this->day);
			case 'day':
				return $this->setDate($this->year, $this->month, $value);
			case 'hour':
				return $this->setTime($value, $this->minute, $this->second);
			case 'minutes':
			case 'minute':
				return $this->setTime($this->hour, $value, $this->second);
			case 'seconds':
			case 'second':
				return $this->setTime($this->hour, $this->minute, $value);
		}
	}

	public function later_than($date = 'now') {
		if (is_string($date))
			$date = new HeliumDateTime($date);

		return ($this > $date);
	}

	public function earlier_than($date = 'now') {
		if (is_string($date))
			$date = new HeliumDateTime($date);

		return ($this < $date);
	}

	public function add_translation($search, $replace) {
		$this->translations[$search] = $replace;
	}
	
	public function add_translations($searches) {
		$this->translations = $this->translations + $searches;
	}
	
	public static function add_locale($locale, $translations) {
		self::$locales[$locale] = $translations;
	}
	
	public function set_locale($locale) {
		$this->translations = self::$locales[$locale];
	}
	
	public function set_default_locale($locale = 'en') {
		self::$default_locale = $locale;
	}

	public function format($format) {
		if (!$this->zero) {
			$original = parent::format($format);
			return str_replace(array_keys($this->translations), array_values($this->translations), $original);
		}
		else
			return '';
	}

	public static function set_default_timezone($timezone_string) {
		self::$timezone = $timezone_string;
		
		if ($this) {
			$tz = new DateTimeZone($timezone_string);
			$this->setTimezone($tz);
		}
	}
	
	public function setTimezone($timezone) {
		if (is_string($timezone))
			$timezone = new DateTimeZone($timezone);
			
		return parent::setTimezone($timezone);
	}
}
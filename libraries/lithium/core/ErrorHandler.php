<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2009, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\core;

use Exception;
use ErrorException;
use lithium\util\collection\Filters;

/**
 * The `ErrorHandler` class allows PHP errors and exceptions to be handled in a uniform way. Using
 * the `ErrorHandler`'s configuration, it is possible to have very broad but very tight control
 * over error handling in your application.
 *
 * {{{ embed:lithium\tests\cases\core\ErrorHandlerTest::testExceptionCatching(2-7) }}}
 *
 * Using a series of cascading rules and handlers, it is possible to capture and handle very
 * specific errors and exceptions.
 */
class ErrorHandler extends \lithium\core\StaticObject {

	/**
	 * Configuration parameters.
	 *
	 * @var array Config params
	 */
	protected static $_config = array();

	/**
	 * Error/exception handlers.
	 *
	 * @var array An array of closures that represent all invokable error/exception handlers.
	 */
	protected static $_handlers = array();

	/**
	 * Types of checks available for sorting & parsing errors/exceptions.
	 * Default checks are for `code`, `stack` and `message`.
	 *
	 * @var array Array of checks represented as closures, indexed by name.
	 */
	protected static $_checks = array();

	/**
	 * Currently registered exception handler.
	 *
	 * @var closure Closure representing exception handler.
	 */
	protected static $_exceptionHandler = null;

	/**
	 * State of error/exception handling.
	 *
	 * @var boolean True if custom error/exception handlers have been registered, false
	 *      otherwise.
	 */
	protected static $_isRunning = false;

	protected static $_runOptions = array();

	/**
	 * Setup basic error handling checks/types, as well as register the error and exception
	 * hanlders.
	 *
	 * Called on static class initialization (i.e. when first loaded).
	 *
	 * @return void
	 */
	public static function __init() {
		static::$_checks = array(
			'type'  => function($config, $info) {
				return (boolean) array_filter((array) $config['type'], function($type) use ($info) {
					return $type == $info['type'] || is_subclass_of($info['type'], $type);
				});
			},
			'code' => function($config, $info) {
				return ($config['code'] & $info['code']);
			},
			'stack' => function($config, $info) {
				return (boolean) array_intersect((array) $config['stack'], $info['stack']);
			},
			'message' => function($config, $info) {
				return preg_match($config['message'], $info['message']);
			}
		);
		$self = get_called_class();

		static::$_exceptionHandler = function($exception, $return = false) use ($self) {
			$info = compact('exception') + array(
				'type' => get_class($exception),
				'stack' => $self::trace($exception->getTrace())
			);
			foreach (array('message', 'file', 'line', 'trace') as $key) {
				$method = 'get' . ucfirst($key);
				$info[$key] = $exception->{$method}();
			}
			return $return ? $info : $self::handle($info);
		};
	}

	/**
	 * Getter & setter of error/exception handlers.
	 *
	 * @param array $handlers If set, the passed `$handlers` array will be merged with
	 *        the already defined handlers in the `ErrorHandler` static class.
	 * @return array Current set of handlers.
	 */
	public static function handlers($handlers = array()) {
		return (static::$_handlers = $handlers + static::$_handlers);
	}

	/**
	 * Configure the `ErrorHandler`.
	 *
	 * @param array $config Configuration directives.
	 * @return Current configuration set.
	 */
	public static function config($config = array()) {
		return (static::$_config = array_merge($config, static::$_config));
	}

	public static function handler($name, $info) {
		return static::$_handlers[$name]($info);
	}

	/**
	 * Register error and exception handlers.
	 *
	 * This method (`ErrorHandler::run()`) needs to be called as early as possible in the bootstrap
	 * cycle; immediately after `require`-ing `bootstrap/libraries.php` is your best bet.
	 *
	 * @return void
	 */
	public static function run(array $config = array()) {
		$defaults = array('trapErrors' => false, 'convertErrors' => true);

		if (static::$_isRunning) {
			return;
		}
		static::$_isRunning = true;
		static::$_runOptions = $config + $defaults;
		$self = get_called_class();

		$trap = function($code, $message, $file, $line = 0, $context = null) use ($self) {
			$trace = debug_backtrace();
			$trace = array_slice($trace, 1, count($trace));
			$self::handle(compact('type', 'code', 'message', 'file', 'line', 'trace', 'context'));
		};

		$convert = function($code, $message, $file, $line = 0, $context = null) use ($self) {
			throw new ErrorException($message, 500, $code, $file, $line);
		};

		if (static::$_runOptions['trapErrors']) {
			set_error_handler($trap);
		} elseif (static::$_runOptions['convertErrors']) {
			set_error_handler($convert);
		}
		set_exception_handler(static::$_exceptionHandler);
	}

	/**
	 * Returns the state of the `ErrorHandler`, indicating whether or not custom error/exception
	 * handers have been regsitered.
	 *
	 * @return void
	 */
	public static function isRunning() {
		return static::$_isRunning;
	}

	/**
	 * Unooks `ErrorHandler`'s exception and error handlers, and restores PHP's defaults. May have
	 * unexpected results if it is not matched with a prior call to `run()`, or if other error
	 * handlers are set after a call to `run()`.
	 *
	 * @return void
	 */
	public static function stop() {
		restore_error_handler();
		restore_exception_handler();
		static::$_isRunning = false;
	}

	/**
	 * Wipes out all configuration and resets the error handler to its initial state when loaded.
	 * Mainly used for testing.
	 *
	 * @return void
	 */
	public static function reset() {
		static::$_config = array();
		static::$_checks = array();
		static::$_handlers = array();
		static::$_exceptionHandler = null;
		static::__init();
	}

	/**
	 * Receives the handled errors and exceptions that have been caught, and processes them
	 * in a normalized manner.
	 *
	 * @param object|array $info
	 * @param array $scope
	 * @return boolean True if successfully handled, false otherwise.
	 */
	public static function handle($info, $scope = array()) {
		$checks = static::$_checks;
		$rules = $scope ?: static::$_config;
		$handler = static::$_exceptionHandler;
		$info = is_object($info) ? $handler($info, true) : $info;

		$defaults = array(
			'type' => null, 'code' => 0, 'message' => null, 'file' => null, 'line' => 0,
			'trace' => array(), 'context' => null, 'exception' => null
		);
		$info = (array) $info + $defaults;

		$info['stack'] = static::trace($info['trace']);
		$info['origin'] = static::_origin($info['trace']);

		foreach ($rules as $config) {
			foreach (array_keys($config) as $key) {
				if ($key == 'conditions' || $key == 'scope' || $key == 'handler') {
					continue;
				}
				if (!isset($info[$key])  || !isset($checks[$key])) {
					continue 2;
				}
				if (($check = $checks[$key]) && !$check($config, $info)) {
					continue 2;
				}
			}
			if (!isset($config['handler'])) {
				return false;
			}
			if ((isset($config['conditions']) && $call = $config['conditions']) && !$call($info)) {
				return false;
			}
			if ((isset($config['scope'])) && static::handle($info, $config['scope']) !== false) {
				return true;
			}
			$handler = $config['handler'];
			return $handler($info) !== false;
		}
		return false;
	}

	/**
	 * Determine frame from the stack trace where the error/exception was first generated.
	 *
	 * @param array $stack Stack trace from error/exception that was produced.
	 * @return string Class where error/exception was generated.
	 */
	protected static function _origin(array $stack) {
		foreach ($stack as $frame) {
			if (isset($frame['class'])) {
				return trim($frame['class'], '\\');
			}
		}
	}

	public static function apply($object, array $conditions, $handler) {
		$conditions = $conditions ?: array('type' => 'Exception');
		list($class, $method) = is_string($object) ? explode('::', $object) : $object;
		$wrap = static::$_exceptionHandler;
		$_self = get_called_class();

		$filter = function($self, $params, $chain) use ($_self, $conditions, $handler, $wrap) {
			try {
				return $chain->next($self, $params, $chain);
			} catch (Exception $e) {
				if (!$_self::matches($e, $conditions)) {
					throw $e;
				}
				return $handler($wrap($e, true), $params);
			}
		};

		if (is_string($class)) {
			Filters::apply($class, $method, $filter);
		} else {
			$class->applyFilter($method, $filter);
		}
	}

	public static function matches($info, $conditions) {
		$checks = static::$_checks;
		$handler = static::$_exceptionHandler;
		$info = is_object($info) ? $handler($info, true) : $info;

		foreach (array_keys($conditions) as $key) {
			if ($key == 'conditions' || $key == 'scope' || $key == 'handler') {
				continue;
			}
			if (!isset($info[$key]) || !isset($checks[$key])) {
				return false;
			}
			if (($check = $checks[$key]) && !$check($conditions, $info)) {
				return false;
			}
		}
		if ((isset($config['conditions']) && $call = $config['conditions']) && !$call($info)) {
			return false;
		}
		return true;
	}

	/**
	 * Trim down a typical stack trace to class & method calls.
	 *
	 * @param array $stack A `debug_backtrace()`-compatible stack trace output.
	 * @return array Returns a flat stack array containing class and method references.
	 */
	public static function trace(array $stack) {
		$result = array();

		foreach ($stack as $frame) {
			if (isset($frame['function'])) {
				if (isset($frame['class'])) {
					$result[] = trim($frame['class'], '\\') . '::' . $frame['function'];
				} else {
					$result[] = $frame['function'];
				}
			}
		}
		return $result;
	}
}

?>
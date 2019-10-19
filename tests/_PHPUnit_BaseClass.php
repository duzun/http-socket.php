<?php

// -----------------------------------------------------
/**
 *  @author Dumitru Uzun (DUzun.Me)
 */
// -----------------------------------------------------
define('PHPUNIT_DIR', dirname(__FILE__) . DIRECTORY_SEPARATOR);
define('ROOT_DIR', strtr(dirname(PHPUNIT_DIR), '\\', '/') . '/');
if (!class_exists('PHPUnit_Runner_Version')) {
    class_alias('PHPUnit\Runner\Version', 'PHPUnit_Runner_Version');
}

// -----------------------------------------------------
// require_once ROOT_DIR . 'autoload.php';
require_once ROOT_DIR . 'vendor/autoload.php';
// -----------------------------------------------------

// We have to make some adjustments for PHPUnit_BaseClass to work with
// PHPUnit 8.0 and still keep backward compatibility
if (version_compare(PHPUnit_Runner_Version::id(), '8.0.0') >= 0) {
    require_once PHPUNIT_DIR . '_PU8_TestCase.php';
} else {
    require_once PHPUNIT_DIR . '_PU7_TestCase.php';
}

// -----------------------------------------------------
// -----------------------------------------------------
/**
 * @backupGlobals disabled
 */
// -----------------------------------------------------
abstract class PHPUnit_BaseClass extends PU_TestCase
{
    /**
     * @var boolean
     */
    public static $log = true;

    /**
     * @var string
     */
    public static $testName;

    /**
     * @var string
     */
    public static $className;

    /**
     * @var string
     */
    public static $thausandsSeparator = "'";

    // -----------------------------------------------------
    // Before every test
    public function mySetUp()
    {
        self::$testName  = $this->getName();
        self::$className = get_class($this);

        // parent::mySetUp();
    }

    // -----------------------------------------------------
    // Helper methods:

    /**
     * @var int
     */
    private static $_idx = 0;

    /**
     * @var string
     */
    private static $_lastTest;

    /**
     * @var string
     */
    private static $_lastClass;

    /**
     * Log a message to console
     */
    public static function log()
    {
        if (empty(self::$log)) {
            return;
        }

        if (self::$_lastTest != self::$testName || self::$_lastClass != self::$className) {
            echo PHP_EOL, PHP_EOL, '### -> ', self::$className . '::' . self::$testName, ' ()', PHP_EOL;
            self::$_lastTest  = self::$testName;
            self::$_lastClass = self::$className;
            self::$_idx       = 0;
        }
        $args = func_get_args();
        foreach ($args as $k => $v) {
            is_string($v) or is_int($v) or is_float($v) or $args[$k] = var_export($v, true);
        }

        echo ''
        // , PHP_EOL
        , ''
        , str_pad(++self::$_idx, 3, ' ', STR_PAD_LEFT)
        , ')  '
        , implode(' ', $args)
        , PHP_EOL
        ;
    }

    // -----------------------------------------------------
    public static function deleteTestData() {}

    // -----------------------------------------------------
    // Helpers:

    /**
     * @param  float    $num
     * @param  int      $dec
     * @return string
     */
    public static function fmtNumber($num, $dec = 0)
    {
        return number_format($num, $dec, '.', self::$thausandsSeparator);
    }

    /**
     * @param  float    $mt
     * @return string
     */
    public static function fmtMicroTime($mt)
    {
        $v = (string) self::fmtNumber(round($mt * 1e6), 0);
        return str_pad($v, 7, ' ', STR_PAD_LEFT) . 'µs';
    }

    /**
     * @param  float    $mm
     * @return string
     */
    public static function fmtMem($mm)
    {
        return self::fmtNumber($mm / 1024, $mm > 1024 ? $mm > 100 * 1024 ? 0 : 1 : 2) . 'KiB';
    }

    /**
     * @param float   $timer
     * @param boolean $fmt
     */
    public static function timer($timer = null, $fmt = true)
    {
        $mt = microtime(true);
        return isset($timer) ? $fmt ? self::fmtMicroTime($mt - $timer) : ($mt - $timer) * 1e6 : $mt;
    }

    /**
     * @param  float   $memer
     * @param  boolean $fmt
     * @return mixed
     */
    public static function memer($memer = null, $fmt = true)
    {
        $mm = memory_get_usage();
        if (isset($memer)) {
            $mm -= $memer;
            if ($fmt) {
                $mm = self::fmtMem($mm);
            }
        }
        return $mm;
    }

    /**
     * mb_str_pad
     *
     * @source https://gist.github.com/nebiros/226350
     * @author Kari "Haprog" Sderholm
     *
     * @param  string   $input
     * @param  int      $pad_length
     * @param  string   $pad_string
     * @param  int      $pad_type
     * @return string
     */
    public static function pad($input, $pad_length, $pad_type = STR_PAD_RIGHT, $pad_string = ' ')
    {
        $diff = strlen($input) - mb_strlen($input);
        return str_pad($input, $pad_length + $diff, $pad_string, $pad_type);
    }

    // -----------------------------------------------------
}
// -----------------------------------------------------
// Delete the temp test user after all tests have fired
register_shutdown_function('PHPUnit_BaseClass::deleteTestData');
// -----------------------------------------------------

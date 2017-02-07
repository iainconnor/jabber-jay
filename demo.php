<?php

include(dirname(__FILE__) . "/vendor/autoload.php");

const MY_DOMAIN = "http://www.mydemo.com";
const MY_API_PATH = MY_DOMAIN . "/rest_api";

/**
 * A demo class.
 *
 * You can define a root path for your API.
 * @\IainConnor\GameMaker\Annotations\API(path=MY_API_PATH)
 *
 * You can define a root path for a specific controller.
 * You can optionally ignore merging this with the parent annotation above.
 * @\IainConnor\GameMaker\Annotations\Controller(path="/foo")
 */
class Foo {
    /**
     * A method.
     *
     * @\IainConnor\GameMaker\Annotations\GET(path="/sit")
     *
     * By default, inputs are sourced from the most likely place given the HTTP method.
     * For example, GET's come from query parameters, POST's come from post body, etc.
     * This can be overridden.
     * @\IainConnor\GameMaker\Annotations\Input(in="HEADER")
     * @param string $foo A string.
     *
     * The names the input referred to as by the HTTP call can also be customized.
     * For array types, you can customize how the multiple values are input.
     * @\IainConnor\GameMaker\Annotations\Input(name="custom_name", arrayFormat="CSV")
     * @param string[] $bar An array of strings.
     *
     * Inputs can be type-hinted as one of a set of possible values.
     * Inputs are required unless defaulted or type-hinted as null.
     * @\IainConnor\GameMaker\Annotations\Input(enum={"yes", "no"})
     * @param null|string $baz An optional stringey boolean.
     */
    public function bar($foo, array $bar, $baz = null) {

    }
}

$gameMaker = \IainConnor\GameMaker\GameMaker::instance();

// You should always set an AnnotationReader to improve performance.
// @see http://docs.doctrine-project.org/projects/doctrine-common/en/latest/reference/annotations.html
$gameMaker->setAnnotationReader(
    new \IainConnor\Cornucopia\CachedReader(
        new \IainConnor\Cornucopia\AnnotationReader(),
        new \Doctrine\Common\Cache\ArrayCache()
    ));


$controllers = [$gameMaker->parseController(Foo::class)];

$jabberJay = \IainConnor\JabberJay\JabberJay::instance($controllers);

var_dump ( $jabberJay );
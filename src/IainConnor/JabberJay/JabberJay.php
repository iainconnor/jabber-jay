<?php


namespace IainConnor\JabberJay;


use IainConnor\Cornucopia\AnnotationReader;
use IainConnor\Cornucopia\Annotations\TypeHint;
use IainConnor\GameMaker\Annotations\Input;
use IainConnor\GameMaker\ControllerInformation;
use IainConnor\GameMaker\Endpoint;
use IainConnor\GameMaker\GameMaker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class JabberJay
{
    /** @var Request */
    public $request;

    /** @var RouteCollection */
    public $routeCollection;

    /** @var JabberJay */
    public static $instance;


    /**
     * JabberJay constructor.
     * @param ControllerInformation[] $controllers
     * @param Request $request
     */
    public function __construct(array $controllers, Request $request)
    {
        $this->request = $request;
        $this->routeCollection = $this->generateRouteCollection($controllers, $request);
    }


    /**
     * Retrieve or boot an instance.
     * @param ControllerInformation[] $controllers
     * @return JabberJay
     */
    public static function instance(array $controllers) {
        if ( static::$instance == null ) {
            static::$instance = static::boot($controllers);
        }

        return static::$instance;
    }

    /**
     * @param ControllerInformation[] $controllers
     * @param Request $request
     * @return RouteCollection
     */
    protected function generateRouteCollection(array $controllers, Request $request) {
        $routeCollection = new RouteCollection();

        foreach ( $controllers as $controller ) {
            foreach ($controller->endpoints as $endpoint) {
                $pathParts = parse_url($endpoint->httpMethod->path);

                $routeCollection->add($this->getEndpointName($controller, $endpoint),
                    new Route(
                        $pathParts['path'],
                        $this->getDefaults($controller, $endpoint, $request),
                        [],
                        [],
                        $pathParts['host'],
                        [$pathParts['scheme']],
                        [GameMaker::getAfterLastSlash(get_class($endpoint->httpMethod))]
                    )
                );
            }
        }

        return $routeCollection;
    }

    protected function getDefaults(ControllerInformation $controller, Endpoint $endpoint, Request $request) {
        $defaults = [
            '_controller' => $controller->class . '::' . $endpoint->method
        ];

        foreach ( $endpoint->inputs as $input ) {
            $inputData = $this->getInputDataFromRequest($input, $request);
            if ( !is_null($inputData) ) {
                if ($input->typeHint->type == TypeHint::ARRAY_TYPE) {
                    $inputData = $this->parseInputDataBasedOnArrayFormat($inputData, $input->arrayFormat);
                }

                $defaults[$input->variableName] = $inputData;
            }
        }

        return $defaults;
    }

    /**
     * @param $inputData
     * @param $arrayFormat
     * @return array
     */
    protected function parseInputDataBasedOnArrayFormat($inputData, $arrayFormat) {
        if ( is_array($inputData) ) {

            return $inputData;
        }

        switch ($arrayFormat) {
            case "CSV":

                return array_map('trim', explode(',', $inputData));
                break;
            case "SSV":

                return array_map('trim', explode(' ', $inputData));
                break;
            case "TSV":

                return array_map('trim', explode("\t", $inputData));
                break;
            case "PIPES":

                return array_map('trim', explode('|', $inputData));
                break;
        }

        return [$inputData];
    }

    /**
     * @param Input $input
     * @param Request $request
     * @return array|mixed|null|string
     */
    protected function getInputDataFromRequest(Input $input, Request $request) {
        switch ($input->in) {
            case "PATH":
                // @TODO IAIN?

                break;
            case "QUERY":

                return $request->query->get($input->name);
                break;
            case "FORM":

                return $request->request->get($input->name);
                break;
            case "BODY":
                $jsonBody = json_decode($request->getContent(), true);
                return is_array($jsonBody) && array_key_exists($input->name, $jsonBody) ? $jsonBody[$input->name] : null;
                break;
            case "HEADER":

                return $request->headers->get($input->name);
                break;
        }

        return null;
    }

    /**
     * @param ControllerInformation $controller
     * @param Endpoint $endpoint
     * @return string
     */
    protected function getEndpointName(ControllerInformation $controller, Endpoint $endpoint) {
        if ( $endpoint->httpMethod->friendlyName ) {

            return strtolower(str_replace(' ', '_', $endpoint->httpMethod->friendlyName));
        }

        return GameMaker::getAfterLastSlash(get_class($endpoint->httpMethod)) . ":" . $controller->class . "@" . $endpoint->method;
    }

    /**
     * @param array $controllers
     * @return JabberJay
     * @internal param GameMaker $gameMaker
     */
    protected static function boot(array $controllers) {
        return new JabberJay($controllers, Request::createFromGlobals());
    }
}
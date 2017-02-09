<?php


namespace IainConnor\JabberJay;


use IainConnor\Cornucopia\AnnotationReader;
use IainConnor\Cornucopia\Annotations\TypeHint;
use IainConnor\Cornucopia\Type;
use IainConnor\GameMaker\Annotations\Input;
use IainConnor\GameMaker\Annotations\Output;
use IainConnor\GameMaker\ControllerInformation;
use IainConnor\GameMaker\Endpoint;
use IainConnor\GameMaker\GameMaker;
use IainConnor\GameMaker\Utils\HttpStatusCodes;
use IainConnor\MockingJay\MockingJay;
use Psr\Log\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadataFactory;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class JabberJay
{
    /** @var RouteCollection */
    protected $routeCollection;

    /** @var JabberJay */
    protected static $instance;

    /** @var GameMaker */
    protected $gameMaker;

    /** @var MockingJay */
    protected $mockingJay;

    /**
     * JabberJay constructor.
     * @param GameMaker $gameMaker
     * @param MockingJay $mockingJay
     * @param ControllerInformation[] $controllers
     */
    public function __construct(GameMaker $gameMaker, MockingJay $mockingJay, array $controllers = [])
    {
        $this->gameMaker = $gameMaker;
        $this->mockingJay = $mockingJay;
        $this->routeCollection = new RouteCollection();
        $this->addControllers($controllers);
    }


    /**
     * Retrieve or boot an instance.
     *
     * @param GameMaker $gameMaker
     * @param MockingJay $mockingJay
     * @param ControllerInformation[] $controllers
     * @return JabberJay
     */
    public static function instance(GameMaker $gameMaker, MockingJay $mockingJay = null, array $controllers = []) {
        if ( static::$instance == null ) {
            static::$instance = static::boot($gameMaker, $mockingJay, $controllers);
        }

        return static::$instance;
    }

    /**
     * Add a list of Controllers.
     *
     * @param array $controllers
     */
    public function addControllers(array $controllers) {
        array_walk($controllers, [$this, 'addController']);
    }

    /**
     * Add a Controller.
     *
     * @param ControllerInformation $controller
     */
    public function addController(ControllerInformation $controller) {
        foreach ($controller->endpoints as $endpoint) {
            $pathParts = parse_url($endpoint->httpMethod->path);

            $this->routeCollection->add($this->getEndpointName($controller, $endpoint),
                new Route(
                    $pathParts['path'],
                    [
                        '_controller' => $controller->class . '::' . $endpoint->method,
                        '_controllerInformation' => $controller,
                        '_endpoint' => $endpoint
                    ],
                    [],
                    [],
                    array_key_exists('host', $pathParts) ? $pathParts['host'] : '',
                    array_key_exists('scheme', $pathParts) ? [$pathParts['scheme']]: [],
                    [GameMaker::getAfterLastSlash(get_class($endpoint->httpMethod))]
                )
            );
        }
    }

    /**
     * Retrieve the parsed Symfony ResultCollection.
     *
     * @return RouteCollection
     */
    public function getRouteCollection()
    {
        return $this->routeCollection;
    }

    /**
     * Resolves the given Request, calls the underlying method, and returns its response encoded as JSON.
     *
     * @param Request $request
     * @param bool $allowMockResponse Allow a mocked response if the resolved method returns null.
     * @param bool $forceMockResponse Force a mocked response, even if the resolved method doesn't return null.
     *
     * @return Response
     */
    public function performRequest(Request $request, $allowMockResponse = true, $forceMockResponse = false) {
        $resolvedRequest = $this->resolveRequest($request);

        $responseData = null;
        if ( !$forceMockResponse ) {
            $responseData = call_user_func_array($resolvedRequest->callableController, $resolvedRequest->callableInputs);
        }

        if ( $forceMockResponse || ($allowMockResponse && is_null($responseData)) ) {
            $responseData = $this->getMockResponseForEndpoint($resolvedRequest->endpoint);
        }

        if ( !$responseData instanceof JsonResponse ) {
            $responseData = new Response($responseData, $this->getResponseCodeForEndpointResponseData($resolvedRequest->endpoint, $responseData));
        }

        return $responseData;
    }

    /**
     * Resolves the Controller, Endpoint, and callable the Request was for, and extracts the input data for the callable.
     *
     * @param Request $request
     * @return ResolvedRequest
     */
    public function resolveRequest(Request $request) {
        $urlMatcher = new UrlMatcher($this->routeCollection, new RequestContext());
        $matchedRequest = $urlMatcher->matchRequest($request);

        $request->attributes->add(['_controller' => $matchedRequest['_controller']]);

        $controllerResolver = new ControllerResolver();

        $resolutionData = new ResolvedRequest();
        $resolutionData->callableController = $controllerResolver->getController($request);
        $resolutionData->controller = $matchedRequest['_controllerInformation'];
        $resolutionData->endpoint = $matchedRequest['_endpoint'];
        $resolutionData->callableInputs = $this->getInputsForEndpointFromRequest($matchedRequest['_endpoint'], array_filter($matchedRequest, function($key) {
            return substr($key, 0, 1) != '_';
        }, ARRAY_FILTER_USE_KEY), $request);

        return $resolutionData;
    }

    /**
     * Finds the response code associated with the given type of data for the given endpoint.
     * Defaults to 200/OK.
     *
     * @param Endpoint $endpoint
     * @param $responseData
     * @return int
     */
    protected function getResponseCodeForEndpointResponseData(Endpoint $endpoint, $responseData) {
        if ( is_object($responseData) ) {
            $responseDataType = get_class($responseData);
        } else {
            $responseDataType = gettype($responseData);
        }

        foreach ( $endpoint->outputs as $output ) {
            foreach ( $output->typeHint->types as $type ) {
                if ( $type->type == $responseDataType ) {

                    return $output->statusCode;
                }
            }
        }

        return HttpStatusCodes::OK;
    }

    /**
     * Gets a mock response for the given endpoint.
     * Prefers the given HTTP status code, but will an output at random if none are found.
     *
     * @param Endpoint $endpoint
     * @param int $preferredHttpStatusCode
     * @return Response
     */
    public function getMockResponseForEndpoint(Endpoint $endpoint, $preferredHttpStatusCode = HttpStatusCodes::OK) {
        if ( count($endpoint->outputs) == 0 ) {
            return new Response(null, HttpStatusCodes::NO_CONTENT);
        }

        foreach ( $endpoint->outputs as $output ) {
            if ( $output->statusCode == $preferredHttpStatusCode ) {

                return $this->getMockResponseForOutput($output);
            }
        }

        return $this->getMockResponseForOutput($endpoint->outputs[rand(0, count($endpoint->outputs) - 1)]);
    }

    /**
     * Gets a mock response for the given output.
     *
     * @param Output $output
     * @return Response
     */
    public function getMockResponseForOutput(Output $output) {
        /** @var Type $type */
        foreach ( $output->typeHint->types as $type ) {
            if ( $type->type != null ) {
                if ( $type->type == TypeHint::ARRAY_TYPE ) {
                    $responseData = [];
                    for ( $i = 0; $i < rand(0, 10); $i++ ) {
                        $responseData[] = $this->mockIncludingWrappers($type->genericType);
                    }

                    return new JsonResponse($responseData, $output->statusCode);
                } else {

                    return new JsonResponse($this->mockIncludingWrappers($type->type), $output->statusCode);
                }
            }
        }
    }

    /**
     * Mock and fills in the wrappers for the specific type.
     *
     * @param $type
     * @return object
     */
    protected function mockIncludingWrappers($type) {
        $mock = $this->mockingJay->mock($this->gameMaker->getActualClassForType($type));

        foreach ( $this->gameMaker->getUniqueObjects() as $object ) {
            if ( $object->uniqueName == $type ) {
                foreach ( $object->properties as $property ) {
                    if ( property_exists($mock, $property->variableName) ) {
                        $mock->{$property->variableName} = $this->mockingJay->generateMockValueForTypeHint(new TypeHint([$property->types[0]], $property->variableName));

                        break;
                    }
                }

                break;
            }
        }

        return $mock;
    }

    /**
     * Gets an array where keys are the method's inputs and values are mocked values that satisfy them.
     * Presented in order of the method signature.
     *
     * @param Endpoint $endpoint
     * @return array
     */
    public function getMockInputsForMethodForEndpoint(Endpoint $endpoint) {
        $mockedInputs = [];

        foreach ( $endpoint->inputs as $input ) {
            if ( $input->typeHint->defaultValue ) {
                $mockedValue = $input->typeHint->defaultValue;
            } else {
                $typeToMock = $input->typeHint->types[rand(0, count($input->typeHint->types) - 1)];

                if ($typeToMock->type == null) {
                    $mockedValue = null;
                } else if ($typeToMock->type == TypeHint::ARRAY_TYPE) {
                    $mockedValue = [];
                    for ($i = 0; $i < rand(0, 10); $i++) {
                        $mockedValue[] = $this->mockingJay->mock($this->gameMaker->getActualClassForType($typeToMock->genericType));
                    }
                } else {
                    $mockedValue = $this->mockingJay->mock($this->gameMaker->getActualClassForType($typeToMock->type));
                }
            }

            $mockedInputs[$input->variableName] = $mockedValue;
        }

        return $mockedInputs;
    }

    /**
     * Creates a mock request for the given endpoint.
     *
     * @param Endpoint $endpoint
     * @return Request
     */
    public function getMockRequestForEndpoint(Endpoint $endpoint) {
        $mockedInputs = $this->getMockInputsForMethodForEndpoint($endpoint);

        $path = $endpoint->httpMethod->path;
        foreach ( $endpoint->inputs as $input ) {
            if ( $input->in == "PATH" ) {
                $path = str_replace("{" . $input->name . "}", $mockedInputs[$input->variableName], $path);
            }
        }

        $request = Request::create($endpoint->httpMethod->path, GameMaker::getAfterLastSlash(get_class($endpoint->httpMethod)));

        foreach ( $endpoint->inputs as $input ) {
            switch ($input->in) {
                case "QUERY":
                    $request->query->set($input->name, $mockedInputs[$input->variableName]);

                    break;
                case "FORM":
                    $request->request->set($input->name, $mockedInputs[$input->variableName]);

                    break;
                case "BODY":
                    $existingContent = $request->getContent();
                    $existingContentJson = json_decode($existingContent, true);
                    if ( !$existingContentJson ) {
                        $existingContentJson = [];
                    }

                    $existingContentJson[$input->name] = $mockedInputs[$input->variableName];

                    $request = new Request($request->query->all(), $request->request->all(), $request->attributes->all(), $request->cookies->all(), $request->files->all(), $request->server->all(), json_encode($existingContentJson));

                    break;
                case "HEADER":
                    $request->headers->set($input->name, $mockedInputs[$input->variableName]);

                    break;
            }
        }

        return $request;
    }

    /**
     * Retrieves the inputs for the given endpoint from the HTTP request and the parameters already extracted from the
     * path.
     *
     * The inputs are returned in the order necissary to call the underlying function of the endpoint. Arrays are
     * parsed based on the convention specified.
     * @see call_user_func()
     *
     * @param Endpoint $endpoint
     * @param array $pathParams
     * @param Request $request
     *
     * @return array
     */
    public function getInputsForEndpointFromRequest(Endpoint $endpoint, array $pathParams, Request $request) {
        $inputs = [];

        foreach ( $endpoint->inputs as $input ) {
            $inputData = $this->getInputDataFromRequest($input, $request, $pathParams);
            foreach ($input->typeHint->types as $type) {
                if ( $type->type == TypeHint::ARRAY_TYPE ) {
                    $inputData = $this->parseInputDataBasedOnArrayFormat($inputData, $input->arrayFormat);
                    break;
                }
            }

            $inputs[$input->variableName] = $inputData;
        }

        return $inputs;
    }

    /**
     * Parses the given input data based on the given format of an array.
     * If null or already an array, returned untouched.
     *
     * @param $inputData
     * @param $arrayFormat
     * @return array
     */
    protected function parseInputDataBasedOnArrayFormat($inputData, $arrayFormat) {
        if ( is_null($inputData) || is_array($inputData) ) {

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
     * Retrieves the raw inputs for the given endpoint from the HTTP request and the parameters already extracted
     * from the path.
     *
     * The inputs are returned in the order necessary to call the underlying function of the endpoint, however, array
     * values are not parsed.
     * @see call_user_func()
     *
     * @param Input $input
     * @param Request $request
     * @param array $pathParams
     * @return array|mixed|null|string
     */
    protected function getInputDataFromRequest(Input $input, Request $request, array $pathParams) {
        switch ($input->in) {
            case "PATH":
                if ( array_key_exists($input->name, $pathParams) ) {

                    return $pathParams[$input->name];
                }

                break;
            case "QUERY":
                if ( $request->query->has($input->name) ) {

                    return $request->query->get($input->name);
                }

                break;
            case "FORM":
                if ( $request->request->has($input->name) ) {

                    return $request->request->get($input->name);
                }

                break;
            case "BODY":
                $jsonBody = json_decode($request->getContent(), true);
                if ( is_array($jsonBody) && array_key_exists($input->name, $jsonBody) ) {

                    return $jsonBody[$input->name];
                }

                break;
            case "HEADER":
                if ( $request->headers->has($input->name) ) {

                    return $request->headers->get($input->name);
                }

                break;
        }

        /** @var TypeHint $typeHint */
        $typeHint = $input->typeHint;
        if ( $typeHint->defaultValue ) {

            return $typeHint->defaultValue;
        }

        return null;
    }

    /**
     * Gets a friendly, human-readable representation of an endpoint's name.
     *
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
     * Boots the static instance.
     *
     * @param GameMaker $gameMaker
     * @param MockingJay $mockingJay
     * @param array $controllers
     * @return JabberJay
     */
    protected static function boot(GameMaker $gameMaker, MockingJay $mockingJay = null, array $controllers) {
        if ( $mockingJay == null ) {
            $mockingJay = MockingJay::instance();
        }

        return new JabberJay($gameMaker, $mockingJay, $controllers);
    }
}
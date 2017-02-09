<?php


namespace IainConnor\JabberJay;


use IainConnor\GameMaker\ControllerInformation;
use IainConnor\GameMaker\Endpoint;

class ResolvedRequest
{
    /** @var callable */
    public $callableController;

    /** @var string[] */
    public $callableInputs;

    /** @var ControllerInformation */
    public $controller;

    /** @var Endpoint */
    public $endpoint;
}
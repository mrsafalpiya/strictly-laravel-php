<?php

// The framework's response classes, for the tests of the rule about JSON endpoint responses: the root dev environment
// has no laravel/framework or symfony/http-foundation. Its composer.json classmaps this file, since PHPStan's rule-test
// kit finds classes only through the autoloader.

namespace Symfony\Component\HttpFoundation;

class Response {}

class JsonResponse extends Response {}

class BinaryFileResponse extends Response {}

namespace Illuminate\Http;

class Response extends \Symfony\Component\HttpFoundation\Response {}

class JsonResponse extends \Symfony\Component\HttpFoundation\JsonResponse {}

class RedirectResponse extends \Symfony\Component\HttpFoundation\Response {}

<?php

// The framework's request classes, for the tests of the rules about request input: the root dev environment has no
// laravel/framework or symfony/http-foundation. Its composer.json classmaps this file, since PHPStan's rule-test kit
// finds classes only through the autoloader.

namespace Symfony\Component\HttpFoundation;

class Request {}

namespace Illuminate\Http;

class Request extends \Symfony\Component\HttpFoundation\Request
{
    /** @var object */
    public $attributes;

    /** @var object */
    public $request;

    /** @var object */
    public $query;

    /** @var object */
    public $server;

    /** @var object */
    public $files;

    /** @var object */
    public $cookies;

    /** @var object */
    public $headers;
}

namespace Illuminate\Foundation\Http;

use Illuminate\Http\Request;

abstract class FormRequest extends Request {}

namespace Illuminate\Foundation\Bus;

trait Dispatchable
{
    public static function dispatch(mixed ...$arguments): mixed
    {
        return new static(...$arguments);
    }
}

<?php

// The framework's request classes, for the tests of the rules about request input: the root dev environment has no
// laravel/framework. Its composer.json classmaps this file, since PHPStan's rule-test kit finds classes only through
// the autoloader.

namespace Illuminate\Http;

class Request
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

<?php

namespace App\Data\RequestHandOffs;

use StrictlyLaravel\Data\InputData;

final class StoreProjectData extends InputData
{
    public function __construct(
        public string $name,
    ) {}
}

namespace App\Actions\RequestHandOffs;

use App\Data\RequestHandOffs\StoreProjectData;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

final class CreateProject
{
    public function __construct(
        public ?Request $request = null,
    ) {}

    public function handle(Request $request): mixed
    {
        return $request;
    }

    public static function run(SymfonyRequest $request): mixed
    {
        return $request;
    }

    public function fromData(StoreProjectData $data, mixed $user = null): mixed
    {
        return [$data, $user];
    }
}

namespace App\Jobs\RequestHandOffs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;

final class ImportProjects
{
    use Dispatchable;

    public function __construct(
        public Request $request,
    ) {}
}

namespace App\Http\Requests\RequestHandOffs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

final class TwoFactorRequest extends FormRequest
{
    public static function fromRequest(Request $request): self
    {
        return new self;
    }
}

namespace App\Http\Middleware\RequestHandOffs;

use Illuminate\Http\Request;

final class SetLocale
{
    public function handle(Request $request): mixed
    {
        return $request;
    }
}

namespace Vendor\RequestHandOffs;

use Illuminate\Http\Request;

final class Responder
{
    public function toResponse(Request $request): mixed
    {
        return $request;
    }

    public static function authenticateUsing(Request $request): mixed
    {
        return $request;
    }
}

namespace App\Http\Controllers\RequestHandOffs;

use App\Actions\RequestHandOffs\CreateProject;
use App\Data\RequestHandOffs\StoreProjectData;
use App\Http\Middleware\RequestHandOffs\SetLocale;
use App\Http\Requests\RequestHandOffs\TwoFactorRequest;
use App\Jobs\RequestHandOffs\ImportProjects;
use Illuminate\Http\Request;
use Vendor\RequestHandOffs\Responder;

final class ProjectController
{
    public function __construct(
        private CreateProject $create,
    ) {}

    public function store(Request $request, CreateProject $create): mixed
    {
        return $create->handle($request);
    }

    public function service(?Request $request): mixed
    {
        return [$this->create?->handle($request), new CreateProject($request), CreateProject::run($request)];
    }

    public function job(Request $request): mixed
    {
        return ImportProjects::dispatch($request);
    }

    public function closure(Request $request): \Closure
    {
        return fn (): mixed => $this->create->handle($request);
    }

    private function helper(Request $request): mixed
    {
        return $this->create->handle($request);
    }

    // These pass: the code they hand the request to is the framework's, a vendor's, the request's own, middleware or
    // the controller itself, or they pass what the code needs instead of the request.
    public function framework(Request $request, Responder $responder, SetLocale $locale): mixed
    {
        return [
            $responder->toResponse($request), Responder::authenticateUsing($request), $locale->handle($request),
            TwoFactorRequest::fromRequest($request), $this->helper($request),
        ];
    }

    public function values(Request $request, StoreProjectData $data): mixed
    {
        return $this->create->fromData($data, $request->user());
    }

    // An action that takes an input class may: the Contract its requests record checks every key read from the
    // request, wherever it is read (P-024).
    public function typed(StoreProjectData $data, Request $request): mixed
    {
        return [$this->create->handle($request), new CreateProject($request)];
    }
}

namespace App\RequestHandOffs;

use App\Actions\RequestHandOffs\CreateProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('projects', fn (Request $request, CreateProject $create): mixed => $create->handle($request));

// These pass: code outside a controller and a closure route.
final class ProjectService
{
    public function __construct(
        private CreateProject $create,
    ) {}

    public function create(Request $request): mixed
    {
        return $this->create->handle($request);
    }
}

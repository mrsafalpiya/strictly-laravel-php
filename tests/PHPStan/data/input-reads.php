<?php

namespace App\Data\InputReads;

use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

final class StoreProjectData extends InputData
{
    public function __construct(
        public string $name,
    ) {}
}

final class ProjectQueryData extends QueryData
{
    public function __construct(
        public string $search = '',
    ) {}
}

namespace App\Http\Requests\InputReads;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest {}

namespace App\Http\Controllers\InputReads;

use App\Data\InputReads\ProjectQueryData;
use App\Data\InputReads\StoreProjectData;
use App\Http\Requests\InputReads\StoreProjectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;

final class ProjectController
{
    public function store(Request $request): mixed
    {
        return $request->input('name');
    }

    public function accessors(Request $request): mixed
    {
        return [
            $request->all(), $request->keys(), $request->only('name'), $request->except('name'), $request->get('name'),
            $request->post('name'), $request->json('name'), $request->str('name'), $request->string('name'),
            $request->integer('size'), $request->float('size'), $request->boolean('public'), $request->date('due'),
            $request->enum('status', \stdClass::class), $request->array('tags'), $request->collect('tags'),
            $request->has('name'), $request->filled('name'), $request->missing('name'), $request->file('logo'),
            $request->hasFile('logo'), $request->allFiles(), $request->validate(['name' => 'required']),
        ];
    }

    public function properties(Request $request): mixed
    {
        return [$request->name, $request['name'], $request->request->get('name'), $request->files->get('logo')];
    }

    public function helpers(): mixed
    {
        return [RequestFacade::input('name'), request('name')];
    }

    public function metadata(Request $request): mixed
    {
        return [
            $request->user(), $request->route('workspace'), $request->header('Accept'), $request->ip(), $request->session(),
            $request->cookie('sidebar'), $request->method(), $request->fullUrl(), $request->expectsJson(),
            $request->headers->get('Accept'), $request->attributes->get('tenant'), $request->server->get('HTTP_HOST'),
            $request->query('tab'), request(), RequestFacade::ip(),
        ];
    }

    public function typed(StoreProjectData $data, Request $request): mixed
    {
        return [$data->name, $request->boolean('notify')];
    }

    public function index(ProjectQueryData $query, Request $request): mixed
    {
        return [$query->search, $request->input('search')];
    }

    public function formRequest(StoreProjectRequest $request): mixed
    {
        return $request->validated();
    }

    public function closure(Request $request): \Closure
    {
        return fn (): mixed => $request->input('name');
    }

    private function filter(Request $request): mixed
    {
        return $request->input('name');
    }
}

namespace App\Http\Middleware;

use Illuminate\Http\Request;

final class SetLocale
{
    public function handle(Request $request): mixed
    {
        return $request->input('locale');
    }
}

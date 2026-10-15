<?php

namespace App\Data\FormRequests;

use StrictlyLaravel\Data\InputData;

final class StoreProjectData extends InputData
{
    public function __construct(
        public string $name,
    ) {}
}

namespace App\Http\Requests\FormRequests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest {}

abstract class ProjectRequest extends FormRequest {}

final class UpdateProjectRequest extends ProjectRequest {}

namespace App\Http\Controllers\FormRequests;

use App\Data\FormRequests\StoreProjectData;
use App\Http\Requests\FormRequests\StoreProjectRequest;
use App\Http\Requests\FormRequests\UpdateProjectRequest;
use Illuminate\Http\Request;

final class ProjectController
{
    public function store(StoreProjectRequest $request): void {}

    public function update(
        int $project,
        ?UpdateProjectRequest $request,
    ): void {}

    public function typed(StoreProjectData $data, Request $request): void {}

    private function validate(StoreProjectRequest $request): void {}
}

namespace App\Http\Middleware;

use App\Http\Requests\FormRequests\StoreProjectRequest;

final class Authenticate
{
    public function handle(StoreProjectRequest $request): void {}
}

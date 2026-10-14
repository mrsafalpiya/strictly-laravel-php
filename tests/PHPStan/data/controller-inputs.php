<?php

namespace App\Data\Inputs;

use Spatie\LaravelData\Data;
use StrictlyLaravel\Data\InputData;

final class StoreProjectData extends InputData {}

final class ProjectData extends Data {}

namespace App\Http\Controllers\Inputs;

use App\Data\Inputs\ProjectData;
use App\Data\Inputs\StoreProjectData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ProjectController
{
    public function store(StoreProjectData $data): void {}

    public function update(Request $request, ProjectData $data): void {}

    public function index(Request $request): void {}

    public function destroy(StoreProjectData $data): void
    {
        Gate::authorize('delete', $data);
    }

    public function archive(StoreProjectData $data): void
    {
        $this->authorize('archive', $data);
    }

    public function restore(StoreProjectData $data, bool $allowed): void
    {
        abort_unless($allowed, 403);
    }

    public function show(ProjectData $data): void
    {
        Gate::authorize('view', $data);
    }

    public function touch(StoreProjectData $data, bool $missing): void
    {
        abort_if($missing, 404);
    }

    private function authorize(string $ability, object $subject): void {}

    private function helper(ProjectData $data): void {}
}

namespace App\Support\Inputs;

use App\Data\Inputs\ProjectData;

final class ProjectFormatter
{
    public function format(ProjectData $data): void {}
}

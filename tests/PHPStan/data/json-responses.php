<?php

namespace App\Data\JsonResponses;

use Spatie\LaravelData\Data;
use StrictlyLaravel\Inertia\Page;

final class ProjectData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}

final class RecentProjectsData extends Data
{
    /**
     * @param  list<ProjectData>  $projects
     */
    public function __construct(
        public array $projects,
    ) {}
}

final class ProjectPage extends Page {}

namespace App\Http\Resources\JsonResponses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, string>
 */
final class ProjectResource implements Arrayable, JsonSerializable
{
    public function toArray(): array
    {
        return [];
    }

    public function jsonSerialize(): mixed
    {
        return [];
    }
}

namespace App\Http\Controllers\JsonResponses;

use App\Data\JsonResponses\ProjectData;
use App\Data\JsonResponses\ProjectPage;
use App\Data\JsonResponses\RecentProjectsData;
use App\Http\Resources\JsonResponses\ProjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\PaginatedDataCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ProjectController
{
    /**
     * @return list<ProjectData>
     */
    public function recent(): array {}

    /**
     * @return iterable<ProjectData>
     */
    public function feed(): iterable {}

    /**
     * @return Collection<int, ProjectData>
     */
    public function collection(): Collection {}

    /**
     * @return DataCollection<int, ProjectData>
     */
    public function dataCollection(): DataCollection {}

    /**
     * @return PaginatedDataCollection<int, ProjectData>
     */
    public function paginated(): PaginatedDataCollection {}

    /**
     * @return CursorPaginatedDataCollection<int, ProjectData>
     */
    public function cursor(): CursorPaginatedDataCollection {}

    public function resource(): ProjectResource {}

    public function find(): ?ProjectData {}

    public function findOrRedirect(): ProjectData|RedirectResponse {}

    public function listOrRedirect(): array|RedirectResponse {}

    public function show(): ProjectData {}

    public function wrapped(): RecentProjectsData {}

    public function page(): ProjectPage {}

    public function pageOrRedirect(): ProjectPage|RedirectResponse {}

    public function redirect(): RedirectResponse {}

    public function response(): Response {}

    public function json(): JsonResponse {}

    public function download(): BinaryFileResponse {}

    public function destroy(): void {}

    /**
     * @return list<ProjectData>
     */
    private function helper(): array {}

    /**
     * @return list<ProjectData>
     */
    public static function factory(): array {}
}

namespace App\Services\JsonResponses;

use App\Data\JsonResponses\ProjectData;

final class ProjectFinder
{
    /**
     * @return list<ProjectData>
     */
    public function recent(): array {}
}

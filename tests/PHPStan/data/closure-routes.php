<?php

namespace App\Data\ClosureRoutes;

use Spatie\LaravelData\Data;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

final class StoreProjectData extends InputData
{
    public function __construct(
        public string $name,
    ) {}
}

final class ProjectFiltersData extends QueryData
{
    public function __construct(
        public string $sort = 'newest',
    ) {}
}

final class StatsData extends Data
{
    public function __construct(
        public int $projects,
    ) {}
}

namespace App\Pages\ClosureRoutes;

use StrictlyLaravel\Inertia\Page;

final class WelcomePage extends Page {}

namespace App\Http\Requests\ClosureRoutes;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest {}

namespace App\Http\Controllers\ClosureRoutes;

final class ProjectController
{
    public function store(): void {}
}

namespace App\ClosureRoutes;

use App\Data\ClosureRoutes\ProjectFiltersData;
use App\Data\ClosureRoutes\StatsData;
use App\Data\ClosureRoutes\StoreProjectData;
use App\Http\Controllers\ClosureRoutes\ProjectController;
use App\Http\Requests\ClosureRoutes\StoreProjectRequest;
use App\Pages\ClosureRoutes\WelcomePage;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

Route::post('projects', fn (StoreProjectData $data): string => $data->name)->name('projects.store');
Route::get('projects', function (ProjectFiltersData $filters): string {
    return $filters->sort;
});
Route::patch('stats', fn (?StatsData $stats, Request $request): mixed => $stats);
Route::post('imports', fn (StoreProjectRequest $request): mixed => null);
Route::get('stats', fn (): StatsData => new StatsData(projects: 3))->name('stats');
Route::any('nullable-stats', fn (): ?StatsData => null);
Route::get('inferred-stats', fn () => new StatsData(projects: 4));
Route::match(['get', 'post'], 'search', fn (StoreProjectData $data): StatsData => new StatsData(projects: 1));

Route::middleware('auth')->group(function () {
    Route::middleware('verified')->put('projects', fn (StoreProjectData $data): mixed => null);
    Route::get('home', fn (): WelcomePage => new WelcomePage);
});

function routes(Router $router): void
{
    $router->get('totals', fn (): StatsData => new StatsData(projects: 2));
}

// These pass: a closure route without Data classes, a controller route, and closures that don't run a route.
Route::get('/', fn (): WelcomePage => new WelcomePage)->name('home');
Route::get('dashboard', fn (Request $request): mixed => $request->user());
Route::post('projects', [ProjectController::class, 'store']);
Route::bind('project', fn (string $value): StatsData => new StatsData(projects: (int) $value));
Route::fallback(fn (): WelcomePage => new WelcomePage);
array_map(fn (StoreProjectData $data): StatsData => new StatsData(projects: 1), []);

namespace Tests\Feature\ClosureRoutes;

use App\Data\ClosureRoutes\StatsData;
use App\Data\ClosureRoutes\StoreProjectData;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\TestCase;

// A route a test registers exists only while it runs: no route function is generated for it.
final class ProjectInputTest extends TestCase
{
    public function test_the_input_is_validated(): void
    {
        Route::post('projects', fn (StoreProjectData $data): StatsData => new StatsData(projects: 1));
    }
}

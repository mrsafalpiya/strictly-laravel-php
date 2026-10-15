<?php

namespace App\ClosureRouteReads;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('projects', fn (Request $request): mixed => $request->input('name'));
Route::get('projects', function (Request $request): mixed {
    return $request->query('tab');
});
Route::middleware('auth')->post('imports', fn (): mixed => request('file'));
Route::post('tags', fn (Request $request): mixed => array_map(fn (int $tag): mixed => $request->string('name'), [1]));

// These pass: reading what isn't the payload, and closures that don't run a route.
Route::get('dashboard', fn (Request $request): mixed => $request->user());
Route::middleware('auth')->group(function (): void {
    request('locale');
});
array_map(fn (Request $request): mixed => [$request->input('name'), $request->query('tab')], []);

namespace Tests\Feature\ClosureRouteReads;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\TestCase;

// A route a test registers exists only while it runs: no route function is generated for it.
final class ProjectInputTest extends TestCase
{
    public function test_the_input_is_read(): void
    {
        Route::post('projects', fn (Request $request): mixed => [$request->input('name'), $request->query('tab')]);
    }
}

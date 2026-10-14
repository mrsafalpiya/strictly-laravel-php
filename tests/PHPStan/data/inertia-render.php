<?php

namespace App\Http\Controllers;

use App\Pages\Projects\ShowPage;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\ResponseFactory;

final class ProjectController
{
    public function show(): mixed
    {
        return Inertia::render('projects/show', ['title' => 'Tracklane']);
    }

    public function edit(): mixed
    {
        return Inertia::render('projects/edit');
    }

    public function create(): mixed
    {
        return inertia('projects/create', ['title' => 'New project']);
    }

    public function index(ResponseFactory $inertia): mixed
    {
        return $inertia->render('projects/index', ['projects' => []]);
    }

    public function typed(): ShowPage
    {
        // Rendering through a Page class, and Inertia's other APIs, are fine.
        Inertia::flash('toast', ['type' => 'success']);
        inertia()->share('sidebarOpen', true);

        return new ShowPage(title: 'Tracklane');
    }
}

function routes(Router $router): void
{
    Route::inertia('about', 'about');
    $router->inertia('contact', 'contact', ['email' => 'hello@example.com']);
    Route::get('projects', [ProjectController::class, 'index']);
}

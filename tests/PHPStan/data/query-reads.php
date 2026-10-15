<?php

namespace App\Data\Queries;

use StrictlyLaravel\Data\QueryData;

final class DashboardQueryData extends QueryData
{
    public function __construct(
        public string $tab = 'overview',
    ) {}
}

namespace App\Http\Controllers\Queries;

use App\Data\Queries\DashboardQueryData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;

final class DashboardController
{
    public function index(Request $request): mixed
    {
        return $request->query('tab');
    }

    public function all(Request $request): mixed
    {
        return [$request->query(), $request->query->get('tab'), $request?->query('tab')];
    }

    public function facade(): mixed
    {
        return RequestFacade::query('tab');
    }

    public function typed(DashboardQueryData $query, Request $request): mixed
    {
        return [$query->tab, $request->user(), $request->route('workspace'), $request->header('Accept'), $request->fullUrlWithQuery(['tab' => 'stats'])];
    }

    public function closure(Request $request): \Closure
    {
        return fn (): mixed => $request->query('tab');
    }

    private function filter(Request $request): mixed
    {
        return $request->query('tab');
    }
}

namespace App\Http\Middleware;

use Illuminate\Http\Request;

final class SetLocale
{
    public function handle(Request $request): mixed
    {
        return $request->query('locale');
    }
}

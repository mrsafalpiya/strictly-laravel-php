{{-- Strictly Laravel: Wayfinder's own method template, with each route function's return type branded by its action (StrictlyLaravel\Routes\WayfinderTemplates). --}}
{!! \StrictlyLaravel\Routes\WayfinderTemplates::method(\Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path'])) !!}

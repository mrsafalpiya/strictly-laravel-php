<?php

/*
|--------------------------------------------------------------------------
| Strictly Laravel: the package's defaults
|--------------------------------------------------------------------------
|
| Merged under the app's config/strictly.php, which `strictly:install`
| publishes from stubs/strictly.php. There are no feature toggles: the
| Overlay is all-or-nothing (ADR-0007). The only settings are declarations
| for code the app doesn't own (StrictlyLaravel\Routes\VendorDeclarations).
|
*/

return [
    'vendor_inputs' => [],
    'vendor_queries' => [],
    'vendor_responses' => [],
];

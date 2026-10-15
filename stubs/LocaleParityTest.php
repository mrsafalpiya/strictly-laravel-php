<?php

use StrictlyLaravel\Testing\LocaleParity;

// Published by `php artisan strictly:install`, which restores it if it is deleted. t() is typed by the default
// locale's lang files, so every other locale must have exactly its keys and :params.
test('every locale has the default locale\'s translation keys and params', function () {
    LocaleParity::assert();
});

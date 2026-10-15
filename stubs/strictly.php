<?php

use App\Data\Auth\ConfirmPasswordData;
use App\Data\Auth\EnableTwoFactorData;
use App\Data\Auth\ForgotPasswordData;
use App\Data\Auth\LoginData;
use App\Data\Auth\RegisterData;
use App\Data\Auth\ResetPasswordData;
use App\Data\Auth\ResetPasswordQueryData;
use App\Data\Auth\TwoFactorChallengeData;
use App\Data\Auth\TwoFactorCodeData;
use App\Data\Auth\TwoFactorSecretKeyData;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

/*
|--------------------------------------------------------------------------
| Strictly Laravel
|--------------------------------------------------------------------------
|
| There are no feature toggles here: the Overlay is all-or-nothing
| (ADR-0007). This file declares what the type generator can't read from
| code this app doesn't own: the payloads, query strings and JSON responses
| of vendor controllers such as Fortify's. Each is keyed by the action,
| `Controller::class.'@method'` (`@__invoke` for an invokable controller).
|
| The declarations are verified, not trusted. Every key the vendor
| validates, reads or reports an error for in a feature test must be a
| field of the declared input (the test's input Contract fails otherwise),
| every response a test gets must match the declared response, and
| `strictly:coverage` reports a declared field the vendor never uses.
|
*/

return [

    /*
    | The payload of a vendor action, an input class (a subclass of
    | StrictlyLaravel\Data\InputData): its route is branded with it, so
    | `<TypedForm {...store.form()}>` and `submit(store(), data)` take its
    | fields from the route.
    */

    'vendor_inputs' => [
        AuthenticatedSessionController::class.'@store' => LoginData::class,
        RegisteredUserController::class.'@store' => RegisterData::class,
        PasswordResetLinkController::class.'@store' => ForgotPasswordData::class,
        NewPasswordController::class.'@store' => ResetPasswordData::class,
        ConfirmablePasswordController::class.'@store' => ConfirmPasswordData::class,
        TwoFactorAuthenticatedSessionController::class.'@store' => TwoFactorChallengeData::class,
        TwoFactorAuthenticationController::class.'@store' => EnableTwoFactorData::class,
        ConfirmedTwoFactorAuthenticationController::class.'@store' => TwoFactorCodeData::class,
    ],

    /*
    | The query string of a vendor GET, a query class (a subclass of
    | StrictlyLaravel\Data\QueryData): its route function's `query` option is
    | typed by it.
    */

    'vendor_queries' => [
        NewPasswordController::class.'@create' => ResetPasswordQueryData::class,
    ],

    /*
    | The response of a vendor JSON endpoint: a Data class, or a TypeScript
    | type for JSON that isn't one object. `useRouteHttp(route)` types its
    | result with it. A TypeScript type is checked against every recorded
    | response, but Contract Coverage can't see its shapes (Problem P-051).
    */

    'vendor_responses' => [
        TwoFactorQrCodeController::class.'@show' => '{ svg: string; url: string } | []',
        TwoFactorSecretKeyController::class.'@show' => TwoFactorSecretKeyData::class,
        RecoveryCodeController::class.'@index' => 'string[]',
    ],

    /*
    | The app's public broadcast channels, which need no definition in
    | routes/channels.php: a name (`'status'`), or a pattern whose
    | `{parameters}` the client fills (`'projects.{project}.public'`). Key a
    | pattern by itself with the models its parameters hold the route key
    | of (`'projects.{project}.public' => ['project' => Project::class]`)
    | to type them as that key, as `channel()` types a bound parameter.
    | `publicChannel()` from `@/generated/broadcasting` takes only these,
    | and an event broadcast on any other public channel fails its Contract.
    */

    'public_channels' => [],

];

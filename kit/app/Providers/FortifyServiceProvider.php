<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Pages\Auth\ConfirmPasswordPage;
use App\Pages\Auth\ForgotPasswordPage;
use App\Pages\Auth\LoginPage;
use App\Pages\Auth\RegisterPage;
use App\Pages\Auth\ResetPasswordPage;
use App\Pages\Auth\TwoFactorChallengePage;
use App\Pages\Auth\VerifyEmailPage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => new LoginPage(
            canResetPassword: Features::enabled(Features::resetPasswords()),
            status: self::status($request),
        ));

        Fortify::resetPasswordView(fn (Request $request) => new ResetPasswordPage(
            email: $request->string('email')->toString(),
            token: self::routeParameter($request, 'token'),
            passwordRules: Password::defaults()->toPasswordRulesString(),
        ));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => new ForgotPasswordPage(
            status: self::status($request),
        ));

        Fortify::verifyEmailView(fn (Request $request) => new VerifyEmailPage(
            status: self::status($request),
        ));

        Fortify::registerView(fn () => new RegisterPage(
            passwordRules: Password::defaults()->toPasswordRulesString(),
        ));

        Fortify::twoFactorChallengeView(fn () => new TwoFactorChallengePage);

        Fortify::confirmPasswordView(fn () => new ConfirmPasswordPage);
    }

    /**
     * The status message a redirect flashed to the session, if any.
     */
    private static function status(Request $request): ?string
    {
        $status = $request->session()->get('status');

        return is_string($status) ? $status : null;
    }

    private static function routeParameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->string(Fortify::username())->toString()).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credential = $request->string('credential.id')->toString();

            return Limit::perMinute(10)->by(
                ($credential !== '' ? $credential : $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}

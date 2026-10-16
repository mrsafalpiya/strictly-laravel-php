<?php

namespace StrictlyLaravel\Inertia;

use Inertia\Inertia;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * The app's flash data: what Inertia sends once, with the next response, such as a toast after a form is saved
 * (Problem P-047). The app has exactly one concrete subclass, and flashes through it rather than through
 * `Inertia::flash()`, which the PHPStan rule `strictly.inertiaFlash` rejects:
 *
 *     (new FlashData(toast: new ToastData(ToastType::Success, __('Profile updated.'))))->flash();
 *
 * `strictly:generate` writes its type into Inertia's global config typing (`InertiaConfig['flashDataType']`, with
 * every key optional, InertiaConfigProvider), so `usePage().flash` and the router's `flash` event are typed without a
 * cast. Every feature test records what was flashed as a Contract, including before a redirect (ContractRecorder).
 */
#[Hidden]
abstract class Flash extends Data
{
    /**
     * Flash this data to the next response the browser receives, which is the next page after a redirect. Keys
     * flashed earlier in the same request are kept unless this data sets them again.
     */
    public function flash(): void
    {
        /** @var array<string, mixed> $data Data objects always transform to string-keyed arrays. */
        $data = $this->toArray();

        // @phpstan-ignore strictly.inertiaFlash (the one flash the rule sends apps to: the data is this Flash class's, which has a generated type)
        Inertia::flash($data);
    }
}

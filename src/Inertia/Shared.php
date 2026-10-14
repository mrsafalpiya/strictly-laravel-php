<?php

namespace StrictlyLaravel\Inertia;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * The app's shared props: the props every page receives besides its own (Problems P-012, P-013). The app has exactly
 * one concrete subclass, built in the Inertia middleware's share step:
 *
 *     public function share(Request $request): array
 *     {
 *         return [
 *             ...parent::share($request),
 *             ...(new SharedProps(name: config()->string('app.name'), …))->props(),
 *         ];
 *     }
 *
 * `strictly:generate` writes its type into Inertia's global config typing (`InertiaConfig['sharedPageProps']`,
 * InertiaConfigProvider), so `usePage().props` is typed on every page with no hand-written global type. A value guests
 * see as missing is nullable in the class (`?UserData $user`), so it is nullable in TypeScript too. Every feature test
 * records the shared props each page received as a Contract (ContractRecorder), so a key shared beside this class,
 * or a value of the wrong type, fails `tsc` (Problem P-088).
 */
#[Hidden]
abstract class Shared extends Data
{
    /**
     * The props as the share step returns them, keyed by their output names.
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        /** @var array<string, mixed> Data objects always transform to string-keyed arrays. */
        return $this->toArray();
    }
}

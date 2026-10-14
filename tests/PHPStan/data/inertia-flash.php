<?php

namespace App\Http\Controllers;

use App\Data\FlashData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\ResponseFactory;

final class ProfileController
{
    public function update(): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profile updated.']);
    }

    public function destroy(ResponseFactory $inertia): void
    {
        $inertia->flash(['toast' => ['type' => 'success', 'message' => 'Profile deleted.']]);
    }

    public function show(Response $page): Response
    {
        return $page->flash('toast', ['type' => 'info', 'message' => 'Welcome back.']);
    }

    public function typed(FlashData $flash, Request $request): void
    {
        // Flashing through the flash Data class, and Laravel's own session flash, are fine.
        $flash->flash();
        $request->session()->flash('status', 'profile-updated');
    }
}

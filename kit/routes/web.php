<?php

use App\Pages\DashboardPage;
use App\Pages\WelcomePage;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): WelcomePage => new WelcomePage)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn (): DashboardPage => new DashboardPage)->name('dashboard');
});

require __DIR__.'/settings.php';

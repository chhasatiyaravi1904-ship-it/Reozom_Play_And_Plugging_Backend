<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\Geography\CityController;
use App\Http\Controllers\Api\Geography\CountyController;
use App\Http\Controllers\Api\Geography\StateController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');

    // Opened directly from the verification email — no bearer token exists
    // for this request, so it deliberately sits outside auth:sanctum.
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');
    Route::post('email/resend', [EmailVerificationController::class, 'resendForEmail'])
        ->name('verification.resend-for-email');

    // Browser-redirect OAuth flow, not a JSON call — see SocialAuthController.
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['facebook', 'linkedin-openid', 'twitter'])
        ->name('auth.social.redirect');
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['facebook', 'linkedin-openid', 'twitter'])
        ->name('auth.social.callback');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->name('verification.send');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('listings', [ListingController::class, 'index'])->name('listings.index');
    Route::post('listings', [ListingController::class, 'store'])->name('listings.store');
    Route::get('listings/{listing}', [ListingController::class, 'show'])->name('listings.show');
    Route::put('listings/{listing}', [ListingController::class, 'update'])->name('listings.update');
    Route::post('listings/{listing}/submit', [ListingController::class, 'submit'])->name('listings.submit');

  
    Route::get('states', [StateController::class, 'index'])->name('counties.index');
    Route::get('states/{state_id}', [StateController::class, 'show'])->name('counties.show');
     Route::get('counties', [CountyController::class, 'index'])->name('counties.index');
    Route::get('counties/{county}', [CountyController::class, 'show'])->name('counties.show');
    Route::get('cities', [CityController::class, 'index'])->name('cities.index');
    Route::get('cities/{city}', [CityController::class, 'show'])->name('cities.show');

    Route::middleware('permission:manage-states')->group(function () {
        Route::post('states', [StateController::class, 'store'])->name('states.store');
        Route::put('states/{state}', [StateController::class, 'update'])->name('states.update');
        Route::delete('states/{state}', [StateController::class, 'destroy'])->name('states.destroy');
    });
    Route::middleware('permission:manage-counties')->group(function () {
        Route::post('counties', [CountyController::class, 'store'])->name('counties.store');
        Route::put('counties/{county}', [CountyController::class, 'update'])->name('counties.update');
        Route::delete('counties/{county}', [CountyController::class, 'destroy'])->name('counties.destroy');
    });
    Route::middleware('permission:manage-cities')->group(function () {
        Route::post('cities', [CityController::class, 'store'])->name('cities.store');
        Route::put('cities/{city}', [CityController::class, 'update'])->name('cities.update');
        Route::delete('cities/{city}', [CityController::class, 'destroy'])->name('cities.destroy');
    });
});

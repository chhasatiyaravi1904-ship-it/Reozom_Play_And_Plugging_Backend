<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
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
});

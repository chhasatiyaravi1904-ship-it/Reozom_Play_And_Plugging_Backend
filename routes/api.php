<?php

use App\Http\Controllers\Api\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\Geography\CityController;
use App\Http\Controllers\Api\Geography\CountyController;
use App\Http\Controllers\Api\Geography\StateController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\Mls\MlsDirectoryController;
use App\Http\Controllers\Api\Mls\MlsInfoController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PublicGeographyController;
use App\Http\Controllers\Api\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::controller(PublicGeographyController::class)->prefix('public')->group(function () {
    Route::get('states', 'states')->name('public.states');
    Route::get('cities', 'cities')->name('public.cities');
});

// Public so the registration form can show package options before a
// session exists. Selecting/switching a package still requires auth below.
Route::get('packages', [PackageController::class, 'index'])->name('packages.index');

Route::prefix('auth')->group(function () {
    Route::controller(AuthController::class)->group(function () {
        Route::post('register', 'register')->name('auth.register');
        Route::post('login', 'login')->name('auth.login');
    });

    Route::controller(EmailVerificationController::class)->group(function () {
        // Opened directly from the verification email — no bearer token exists
        // for this request, so it deliberately sits outside auth:sanctum.
        Route::get('email/verify/{id}/{hash}', 'verify')
            ->middleware('signed')
            ->name('verification.verify');
        Route::post('email/resend', 'resendForEmail')->name('verification.resend-for-email');
    });

    // Browser-redirect OAuth flow, not a JSON call — see SocialAuthController.
    Route::controller(SocialAuthController::class)->group(function () {
        Route::get('{provider}/redirect', 'redirect')
            ->whereIn('provider', ['facebook', 'linkedin-openid', 'twitter'])
            ->name('auth.social.redirect');
        Route::get('{provider}/callback', 'callback')
            ->whereIn('provider', ['facebook', 'linkedin-openid', 'twitter'])
            ->name('auth.social.callback');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::controller(AuthController::class)->group(function () {
            Route::post('logout', 'logout')->name('auth.logout');
            Route::get('me', 'me')->name('auth.me');
        });
        Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->name('verification.send');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::controller(ListingController::class)->prefix('listings')->group(function () {
        Route::get('/', 'index')->name('listings.index');
        Route::post('/', 'store')->name('listings.store');
        Route::get('{listing}', 'show')->name('listings.show');
        Route::put('{listing}', 'update')->name('listings.update');
        Route::post('{listing}/submit', 'submit')->name('listings.submit');
    });

    Route::controller(StateController::class)->prefix('states')->group(function () {
        Route::get('/', 'index')->name('states.index');
        Route::get('{state_id}', 'show')->name('states.show');

        Route::middleware('permission:manage-states')->group(function () {
            Route::post('/', 'store')->name('states.store');
            Route::put('{state}', 'update')->name('states.update');
            Route::delete('{state}', 'destroy')->name('states.destroy');
        });
    });

    Route::controller(CountyController::class)->prefix('counties')->group(function () {
        Route::get('/', 'index')->name('counties.index');
        Route::get('{county}', 'show')->name('counties.show');

        Route::middleware('permission:manage-counties')->group(function () {
            Route::post('/', 'store')->name('counties.store');
            Route::put('{county}', 'update')->name('counties.update');
            Route::delete('{county}', 'destroy')->name('counties.destroy');
        });
    });

    Route::controller(CityController::class)->prefix('cities')->group(function () {
        Route::get('/', 'index')->name('cities.index');
        Route::get('{city}', 'show')->name('cities.show');

        Route::middleware('permission:manage-cities')->group(function () {
            Route::post('/', 'store')->name('cities.store');
            Route::put('{city}', 'update')->name('cities.update');
            Route::delete('{city}', 'destroy')->name('cities.destroy');
        });
    });

    Route::controller(MlsDirectoryController::class)->prefix('mls-directories')->group(function () {
        Route::get('/', 'index')->name('mls-directories.index');
        Route::get('{mlsDirectory}', 'show')->name('mls-directories.show');

        Route::middleware('permission:manage-mls')->group(function () {
            Route::post('/', 'store')->name('mls-directories.store');
            Route::put('{mlsDirectory}', 'update')->name('mls-directories.update');
            Route::delete('{mlsDirectory}', 'destroy')->name('mls-directories.destroy');
        });
    });

    Route::controller(MlsInfoController::class)->prefix('mls-infos')->group(function () {
        Route::get('/', 'index')->name('mls-infos.index');
        Route::get('{mlsInfo}', 'show')->name('mls-infos.show');

        Route::middleware('permission:manage-mls')->group(function () {
            Route::post('/', 'store')->name('mls-infos.store');
            Route::put('{mlsInfo}', 'update')->name('mls-infos.update');
            Route::delete('{mlsInfo}', 'destroy')->name('mls-infos.destroy');
        });
    });

    Route::controller(PackageController::class)->prefix('packages')->group(function () {
        Route::post('{package}/select', 'select')->name('packages.select');
    });

    Route::prefix('admin')->group(function () {
        Route::controller(UserController::class)->prefix('users')->group(function () {
            Route::get('/', 'index')->name('users.index');
            Route::get('{user}', 'show')->name('users.show');

            Route::middleware('permission:manage-users')->group(function () {
                Route::post('/', 'store')->name('users.store');
                Route::put('{user}', 'update')->name('users.update');
                Route::delete('{user}', 'destroy')->name('users.destroy');
            });
        });

        Route::controller(AdminPackageController::class)->prefix('packages')->group(function () {
            Route::get('/', 'index')->name('admin.packages.index');
            Route::get('{package}', 'show')->name('admin.packages.show');

            Route::middleware('permission:manage-packages')->group(function () {
                Route::post('/', 'store')->name('admin.packages.store');
                Route::put('{package}', 'update')->name('admin.packages.update');
                Route::delete('{package}', 'destroy')->name('admin.packages.destroy');
            });
        });
    });
});

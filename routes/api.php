<?php

use App\Http\Controllers\Api\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\Geography\CityController;
use App\Http\Controllers\Api\Geography\ZipCodeController;
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

Route::controller(\App\Http\Controllers\Api\PublicServicePackageController::class)->prefix('public')->group(function () {
    Route::get('service-packages/search', 'search')->name('public.service-packages.search');
});

Route::controller(\App\Http\Controllers\Api\PublicListingController::class)->prefix('public')->group(function () {
    Route::get('listings', 'index')->name('public.listings.index');
    Route::get('listings/{listing}', 'show')->name('public.listings.show');
});

// Public so the registration form can show package options before a
// session exists. Selecting/switching a package still requires auth below.
Route::get('packages', [PackageController::class, 'index'])->name('packages.index');

Route::prefix('auth')->group(function () {
    Route::controller(AuthController::class)->group(function () {
        Route::post('register', 'register')->name('auth.register');
        Route::post('login', 'login')->name('auth.login');
        Route::post('verify-2fa', 'verify2fa')->name('auth.verify-2fa');
        Route::post('resend-2fa', 'resend2fa')->name('auth.resend-2fa');
    });

    Route::controller(\App\Http\Controllers\Api\PasswordResetController::class)->group(function () {
        Route::post('forgot-password', 'forgotPassword')->name('password.email');
        Route::post('reset-password', 'resetPassword')->name('password.update');
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
        
        Route::put('profile', [\App\Http\Controllers\Api\ProfileController::class, 'update'])->name('profile.update');
        
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

    Route::controller(\App\Http\Controllers\Api\ListingImageController::class)->prefix('listings/{listing}/images')->group(function () {
        Route::get('/', 'index')->name('listings.images.index');
        Route::post('/', 'store')->name('listings.images.store');
        Route::post('reorder', 'reorder')->name('listings.images.reorder');
        Route::put('{image}', 'update')->name('listings.images.update');
        Route::post('{image}/primary', 'setPrimary')->name('listings.images.primary');
        Route::delete('{image}', 'destroy')->name('listings.images.destroy');
    });

    Route::controller(\App\Http\Controllers\Api\ListingWorkflowController::class)->prefix('listings')->group(function () {
        Route::get('{listing}/workflow', 'show')->name('listings.workflow.show');
        Route::post('{listing}/steps/{step}', 'submitStep')->name('listings.workflow.step');
        Route::get('{listing}/disclosures', 'getDisclosures')->name('listings.disclosures.get');
        Route::post('{listing}/disclosures', 'saveDisclosures')->name('listings.disclosures.save');
    });

    Route::controller(\App\Http\Controllers\Api\ListingStatusController::class)->prefix('listings')->group(function () {
        Route::get('{listing}/status-history', 'history')->name('listings.status-history');
        Route::post('{listing}/transition', 'transition')->name('listings.transition');
    });

    Route::controller(\App\Http\Controllers\Api\ListingDisclosureController::class)->prefix('listings')->group(function () {
        Route::get('{listing}/disclosures', 'index')->name('listings.disclosures.index');
        Route::post('{listing}/disclosures', 'store')->name('listings.disclosures.store');
    });

    Route::controller(\App\Http\Controllers\Api\ListingDocumentController::class)->prefix('listings')->group(function () {
        Route::get('{listing}/documents', 'index')->name('listings.documents.index');
        Route::post('{listing}/documents', 'store')->name('listings.documents.store');
        Route::delete('{listing}/documents/{document}', 'destroy')->name('listings.documents.destroy');
        Route::get('{listing}/documents/{document}/download', 'download')->name('listings.documents.download');
        Route::get('{listing}/documents/{document}/preview', 'preview')->name('listings.documents.preview');
        Route::post('{listing}/documents/{document}/sign', 'sign')->name('listings.documents.sign');
        Route::put('{listing}/documents/{document}/review', 'review')->name('listings.documents.review');
        Route::get('{listing}/documents-download-all', 'downloadAll')->name('listings.documents.download-all');
    });

    Route::controller(\App\Http\Controllers\Api\OfferController::class)->group(function () {
        // Buyer offer actions
        Route::post('listings/{listing}/offers', 'store')->name('listings.offers.store');
        Route::get('buyer/offers', 'myOffers')->name('buyer.offers.index');
        Route::get('buyer/offers/{offer}', 'showBuyerOffer')->name('buyer.offers.show');
        Route::post('offers/{offer}/withdraw', 'withdraw')->name('offers.withdraw');

        // Seller & participant negotiation actions
        Route::get('listings/{listing}/offers', 'listingOffers')->name('listings.offers.index');
        Route::get('seller/offers/{offer}', 'showSellerOffer')->name('seller.offers.show');
        Route::post('offers/{offer}/counter', 'counter')->name('offers.counter');
        Route::post('offers/{offer}/accept', 'accept')->name('offers.accept');
        Route::post('offers/{offer}/reject', 'reject')->name('offers.reject');

        // Offer document download
        Route::get('offers/{offer}/documents/{document}/download', 'downloadDocument')->name('offers.documents.download');
    });

    Route::controller(\App\Http\Controllers\ListingProcessController::class)->prefix('listing-processes')->group(function () {
        Route::get('/', 'index')->name('listing-processes.index');
        Route::get('{listingProcess}', 'show')->name('listing-processes.show');
        Route::post('/', 'store')->name('listing-processes.store');
        Route::put('{listingProcess}', 'update')->name('listing-processes.update');
        Route::delete('{listingProcess}', 'destroy')->name('listing-processes.destroy');
    });

    Route::controller(StateController::class)->prefix('states')->group(function () {
        Route::get('/', 'index')->name('states.index');
        Route::get('{state}', 'show')->name('states.show');

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

    Route::controller(ZipCodeController::class)->prefix('zip-codes')->group(function () {
        Route::get('/', 'index')->name('zip-codes.index');
        Route::get('{code}/lookup', 'lookup')->name('zip-codes.lookup');
        Route::get('{zip_code}', 'show')->name('zip-codes.show');

        Route::middleware('permission:manage-zip-codes')->group(function () {
            Route::post('/', 'store')->name('zip-codes.store');
            Route::put('{zip_code}', 'update')->name('zip-codes.update');
            Route::delete('{zip_code}', 'destroy')->name('zip-codes.destroy');
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

    Route::prefix('admin')->middleware('user.role:admin')->group(function () {
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
            Route::get('permissions', 'permissions')->name('admin.packages.permissions');
            Route::get('/', 'index')->name('admin.packages.index');
            Route::get('{package}', 'show')->name('admin.packages.show');

            Route::middleware('permission:manage-packages')->group(function () {
                Route::post('/', 'store')->name('admin.packages.store');
                Route::put('{package}', 'update')->name('admin.packages.update');
                Route::delete('{package}', 'destroy')->name('admin.packages.destroy');
            });
        });

        Route::controller(\App\Http\Controllers\Api\Admin\AdminListingController::class)->prefix('listings')->group(function () {
            Route::get('/', 'index')->name('admin.listings.index');
            Route::get('{listing}', 'show')->name('admin.listings.show');
            Route::post('{listing}/publish', 'publish')->name('admin.listings.publish');
            Route::post('{listing}/unpublish', 'unpublish')->name('admin.listings.unpublish');
            Route::post('{listing}/reject', 'reject')->name('admin.listings.reject');
        });

        Route::get('offers', [\App\Http\Controllers\Api\OfferController::class, 'adminIndex'])->name('admin.offers.index');
        Route::get('offers/{offer}', [\App\Http\Controllers\Api\OfferController::class, 'adminShow'])->name('admin.offers.show');
    });

    Route::prefix('agent')->middleware('user.role:agent,admin')->group(function () {
        Route::controller(\App\Http\Controllers\Api\ServicePackageController::class)->prefix('service-packages')->group(function () {
            Route::get('/', 'index')->name('agent.service-packages.index');
            Route::get('{servicePackage}', 'show')->name('agent.service-packages.show');
            Route::post('/', 'store')->name('agent.service-packages.store');
            Route::put('{servicePackage}', 'update')->name('agent.service-packages.update');
            Route::delete('{servicePackage}', 'destroy')->name('agent.service-packages.destroy');
        });
    });

    // EunoDoc E-Signature Flows
    Route::controller(\App\Http\Controllers\Api\EunoSignController::class)->group(function () {
        Route::post('listings/{listing}/euno-sign/initiate', 'initiateListingSigning')->name('listings.euno-sign.initiate');
        Route::get('listings/{listing}/euno-sign/status', 'getListingSigningStatus')->name('listings.euno-sign.status');
        Route::post('offers/{offer}/euno-sign/initiate', 'initiateOfferSigning')->name('offers.euno-sign.initiate');
        Route::get('offers/{offer}/euno-sign/status', 'getOfferSigningStatus')->name('offers.euno-sign.status');
        Route::get('euno-agreements/{agreement}/signed-doc', 'downloadSignedDocument')->name('euno-agreements.signed-doc');
        Route::get('euno-agreements/{agreement}/audit', 'downloadAuditReport')->name('euno-agreements.audit');
    });
});

// EunoDoc Webhook callback (unauthenticated)
Route::post('euno/webhook', [\App\Http\Controllers\Api\EunoSignController::class, 'webhook'])->name('euno.webhook');

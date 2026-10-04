<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\Request;

class PublicServicePackageController extends Controller
{
    public function search(Request $request, \App\Services\PackageAvailabilityService $packageService)
    {
        $request->validate([
            'zipcode' => 'required|string',
        ]);

        $zipcode = $request->zipcode;

        $packages = $packageService->getAvailablePackages($zipcode);

        return response()->json($packages);
    }
}

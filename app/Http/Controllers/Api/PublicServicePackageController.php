<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\Request;

class PublicServicePackageController extends Controller
{
    public function search(Request $request)
    {
        $request->validate([
            'zipcode' => 'required|string',
        ]);

        $zipcode = $request->zipcode;

        $packages = ServicePackage::with('agent:id,first_name,last_name,email')
            ->where('is_active', true)
            ->whereHas('zipCodes', function ($query) use ($zipcode) {
                $query->where('code', 'like', '%' . $zipcode . '%');
            })
            ->get();

        return response()->json($packages);
    }
}

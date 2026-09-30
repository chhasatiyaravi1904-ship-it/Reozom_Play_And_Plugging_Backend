<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'phone'    => 'nullable|string|max:50',
        ]);
        
        $nameParts = explode(' ', $validated['fullName'], 2);
        
        $user->update([
            'name' => $validated['fullName'],
            'first_name' => $nameParts[0],
            'last_name' => $nameParts[1] ?? '',
            'phone'    => $validated['phone'] ?? null,
        ]);
        
        return api_success(new \App\Http\Resources\UserResource($user), 'Profile updated successfully');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ListingImageController extends Controller
{
    public function index(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $images = $listing->images()->orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'data' => $images
        ]);
    }

    public function store(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        // Allow up to 20 images in total.
        if ($listing->images()->count() >= 20) {
            return response()->json(['success' => false, 'message' => 'Maximum of 20 images allowed.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'images' => 'required|array|max:10',
            'images.*' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $uploadedImages = [];
        $currentMaxSort = $listing->images()->max('sort_order') ?? 0;

        foreach ($request->file('images') as $index => $file) {
            $path = $file->store('listing-images', 'public');
            
            $url = Storage::disk('public')->url($path);
            
            $image = $listing->images()->create([
                'url' => $url,
                'thumbnail_url' => $url,
                'alt_text' => 'Property image',
                'sort_order' => $currentMaxSort + $index + 1,
                'is_public' => true,
            ]);

            $uploadedImages[] = $image;
        }

        return response()->json([
            'success' => true,
            'data' => $uploadedImages,
            'message' => 'Images uploaded successfully.'
        ], 201);
    }

    public function update(Request $request, Listing $listing, ListingImage $image): JsonResponse
    {
        Gate::authorize('update', $listing);

        if ($image->listing_id !== $listing->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'alt_text' => 'nullable|string|max:255',
            'is_public' => 'sometimes|boolean',
        ]);

        $image->update($validated);

        return response()->json([
            'success' => true,
            'data' => $image,
            'message' => 'Image updated successfully.'
        ]);
    }

    public function reorder(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'image_ids' => 'required|array',
            'image_ids.*' => 'required|integer|exists:listing_images,id',
        ]);

        foreach ($validated['image_ids'] as $index => $imageId) {
            $listing->images()->where('id', $imageId)->update([
                'sort_order' => $index + 1
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Images reordered successfully.'
        ]);
    }

    public function setPrimary(Request $request, Listing $listing, ListingImage $image): JsonResponse
    {
        Gate::authorize('update', $listing);

        if ($image->listing_id !== $listing->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Unset any existing primary images
        $listing->images()->update(['is_primary' => false]);
        
        // Set the requested one
        $image->update(['is_primary' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Primary image updated successfully.'
        ]);
    }

    public function destroy(Request $request, Listing $listing, ListingImage $image): JsonResponse
    {
        Gate::authorize('update', $listing);

        if ($image->listing_id !== $listing->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Delete from storage
        $path = str_replace(Storage::disk('public')->url(''), '', $image->url);
        Storage::disk('public')->delete($path);

        $image->delete();

        return response()->json([
            'success' => true,
            'message' => 'Image deleted successfully.'
        ]);
    }
}

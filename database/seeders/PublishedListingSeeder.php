<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\ListingImage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PublishedListingSeeder extends Seeder
{
    public function run(): void
    {
        $seller = User::where('email', 'seller@gmail.com')->first() ?? User::first();
        if (!$seller) {
            return;
        }

        $standardWorkflowSnapshot = [
            [
                'id' => 'system_public_info',
                'order' => 1,
                'name' => 'Public Listing Information',
                'type' => 'public_listing',
                'isSystem' => true,
                'visible' => true,
                'sections' => [
                    [
                        'id' => 'public_basic_information',
                        'order' => 1,
                        'name' => 'Basic Information',
                        'fields' => [
                            [
                                'id' => 'listing_title',
                                'name' => 'listing_title',
                                'label' => 'Listing Title',
                                'type' => 'text',
                                'required' => true,
                                'active' => true,
                                'public_display' => true,
                                'show_on_card' => true,
                            ],
                            [
                                'id' => 'listing_price',
                                'name' => 'listing_price',
                                'label' => 'Price',
                                'type' => 'number',
                                'required' => true,
                                'active' => true,
                                'public_display' => true,
                                'show_on_card' => true,
                            ],
                            [
                                'id' => 'property_type',
                                'name' => 'property_type',
                                'label' => 'Property Type',
                                'type' => 'select',
                                'required' => true,
                                'active' => true,
                                'public_display' => true,
                                'show_on_card' => true,
                                'options' => [
                                    ['label' => 'Single Family', 'value' => 'single_family'],
                                    ['label' => 'Condo', 'value' => 'condo'],
                                    ['label' => 'Townhouse', 'value' => 'townhouse'],
                                    ['label' => 'Multi-Family', 'value' => 'multi_family'],
                                ],
                            ],
                            [
                                'id' => 'bedrooms',
                                'name' => 'bedrooms',
                                'label' => 'Bedrooms',
                                'type' => 'number',
                                'required' => true,
                                'active' => true,
                                'public_display' => true,
                                'show_on_card' => true,
                            ],
                            [
                                'id' => 'bathrooms',
                                'name' => 'bathrooms',
                                'label' => 'Bathrooms',
                                'type' => 'number',
                                'required' => true,
                                'active' => true,
                                'public_display' => true,
                                'show_on_card' => true,
                            ],
                            [
                                'id' => 'square_feet',
                                'name' => 'square_feet',
                                'label' => 'Square Feet',
                                'type' => 'number',
                                'required' => true,
                                'active' => true,
                                'public_display' => true,
                                'show_on_card' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $properties = [
            [
                'title' => 'Modern Family Home with Pool',
                'price' => 425000,
                'property_type' => 'single_family',
                'bedrooms' => 4,
                'bathrooms' => 3,
                'square_feet' => 2850,
                'address' => '1428 Congress Ave',
                'city' => 'Austin',
                'state' => 'TX',
                'zip' => '78701',
                'image' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Modern contemporary family home exterior in Austin Texas',
            ],
            [
                'title' => 'Luxury Waterfront Villa',
                'price' => 1850000,
                'property_type' => 'single_family',
                'bedrooms' => 5,
                'bathrooms' => 4.5,
                'square_feet' => 4200,
                'address' => '320 Horizon Crest Way',
                'city' => 'Bellevue',
                'state' => 'WA',
                'zip' => '98004',
                'image' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Luxury waterfront villa with lake views and glass architecture',
            ],
            [
                'title' => 'Urban Loft Penthouse',
                'price' => 680000,
                'property_type' => 'condo',
                'bedrooms' => 2,
                'bathrooms' => 2,
                'square_feet' => 1450,
                'address' => '950 Brickell Bay Dr #420',
                'city' => 'Miami',
                'state' => 'FL',
                'zip' => '33131',
                'image' => 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'High-rise urban penthouse loft overlooking Miami skyline',
            ],
            [
                'title' => 'Modern Mountain Alpine Retreat',
                'price' => 920000,
                'property_type' => 'single_family',
                'bedrooms' => 3,
                'bathrooms' => 3,
                'square_feet' => 2800,
                'address' => '740 Chautauqua Alpine Rd',
                'city' => 'Boulder',
                'state' => 'CO',
                'zip' => '80302',
                'image' => 'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Scenic alpine mountain home with timber and stone finishes',
            ],
            [
                'title' => 'Historic Georgetown Townhouse',
                'price' => 1250000,
                'property_type' => 'townhouse',
                'bedrooms' => 3,
                'bathrooms' => 3.5,
                'square_feet' => 2600,
                'address' => '2804 O St NW',
                'city' => 'Washington',
                'state' => 'DC',
                'zip' => '20007',
                'image' => 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Renovated historic Georgetown townhouse with private brick terrace',
            ],
            [
                'title' => 'Sunlit Mediterranean Residence',
                'price' => 750000,
                'property_type' => 'single_family',
                'bedrooms' => 4,
                'bathrooms' => 3,
                'square_feet' => 3100,
                'address' => '1250 Coronado Bay Way',
                'city' => 'San Diego',
                'state' => 'CA',
                'zip' => '92101',
                'image' => 'https://images.unsplash.com/photo-1600566753376-12c8ab7fb75b?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Sunlit Mediterranean coastal estate with private courtyard',
            ],
        ];

        foreach ($properties as $index => $prop) {
            $ref = 'RZ-' . strtoupper(Str::random(6));

            $listing = Listing::firstOrCreate(
                ['address' => $prop['address']],
                [
                    'user_id' => $seller->id,
                    'reference_code' => $ref,
                    'address' => $prop['address'],
                    'city' => $prop['city'],
                    'state' => $prop['state'],
                    'zip' => $prop['zip'],
                    'status' => 'published',
                    'moderation_status' => 'approved',
                    'moderated_by' => 1,
                    'moderated_at' => now(),
                    'listing_process_id' => 1,
                    'workflow_snapshot' => $standardWorkflowSnapshot,
                    'steps_total' => 3,
                    'steps_completed' => 3,
                ]
            );

            // Update status to published if existing
            $listing->update([
                'status' => 'published',
                'moderation_status' => 'approved',
                'workflow_snapshot' => $standardWorkflowSnapshot,
                'city' => $prop['city'],
                'state' => $prop['state'],
                'zip' => $prop['zip'],
            ]);

            // Save public answers
            $listing->answers()->updateOrCreate(
                ['step_id' => 'system_public_info'],
                [
                    'values' => [
                        'listing_title' => $prop['title'],
                        'listing_price' => (string) $prop['price'],
                        'property_type' => $prop['property_type'],
                        'bedrooms' => (string) $prop['bedrooms'],
                        'bathrooms' => (string) $prop['bathrooms'],
                        'square_feet' => (string) $prop['square_feet'],
                    ],
                ]
            );

            // Attach photo
            if ($listing->images()->count() === 0) {
                $listing->images()->create([
                    'url' => $prop['image'],
                    'thumbnail_url' => $prop['image'],
                    'alt_text' => $prop['alt'],
                    'is_primary' => true,
                    'is_public' => true,
                    'sort_order' => 1,
                ]);
            }
        }
    }
}

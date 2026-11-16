<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\ListingProcess;
use App\Models\ListingStatusHistory;
use App\Models\ServicePackage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StateSampleListingSeeder extends Seeder
{
    /**
     * Seed published sample listings for the Florida and Michigan listing processes.
     */
    public function run(): void
    {
        $seller = User::where('email', 'seller@example.com')->first()
            ?? User::where('role', \App\Enums\UserRole::SellerBuyer)->first()
            ?? User::first();

        if (! $seller) {
            return;
        }

        $listings = [
            [
                'process' => 'Florida Residential Listing Process',
                'package' => 'Florida Full Service MLS Package',
                'reference_code' => 'REO-FLMIA1',
                'address' => '1450 Brickell Bay Drive',
                'city' => 'Miami', 'state' => 'FL', 'zip' => '33131',
                'title' => 'Waterfront Brickell Condo with Bay Views',
                'price' => 865000, 'property_type' => 'condo',
                'bedrooms' => 2, 'bathrooms' => 2, 'square_feet' => 1420,
                'image' => 'photo-1512917774080-9991f1c4c750',
            ],
            [
                'process' => 'Florida Residential Listing Process',
                'package' => 'Florida Full Service MLS Package',
                'reference_code' => 'REO-FLORL1',
                'address' => '318 Lake Eola Lane',
                'city' => 'Orlando', 'state' => 'FL', 'zip' => '32801',
                'title' => 'Updated Pool Home Near Lake Eola',
                'price' => 529000, 'property_type' => 'single_family',
                'bedrooms' => 4, 'bathrooms' => 3, 'square_feet' => 2310,
                'image' => 'photo-1568605114967-8130f3a36994',
            ],
            [
                'process' => 'Florida Residential Listing Process',
                'package' => 'Florida Full Service MLS Package',
                'reference_code' => 'REO-FLTPA1',
                'address' => '2207 Bayshore Boulevard',
                'city' => 'Tampa', 'state' => 'FL', 'zip' => '33606',
                'title' => 'Hyde Park Townhome Steps from Bayshore',
                'price' => 649000, 'property_type' => 'townhouse',
                'bedrooms' => 3, 'bathrooms' => 3, 'square_feet' => 1980,
                'image' => 'photo-1570129477492-45c003edd2be',
            ],
            [
                'process' => 'Michigan Residential Listing Process',
                'package' => 'Michigan Full Service MLS Package',
                'reference_code' => 'REO-MIDET1',
                'address' => '845 Woodward Heights',
                'city' => 'Detroit', 'state' => 'MI', 'zip' => '48226',
                'title' => 'Restored Brick Colonial in Midtown Detroit',
                'price' => 389000, 'property_type' => 'single_family',
                'bedrooms' => 4, 'bathrooms' => 2, 'square_feet' => 2480,
                'image' => 'photo-1600596542815-ffad4c1539a9',
            ],
            [
                'process' => 'Michigan Residential Listing Process',
                'package' => 'Michigan Full Service MLS Package',
                'reference_code' => 'REO-MIGRR1',
                'address' => '1120 Cherry Street SE',
                'city' => 'Grand Rapids', 'state' => 'MI', 'zip' => '49506',
                'title' => 'East Hills Craftsman with Finished Basement',
                'price' => 459000, 'property_type' => 'single_family',
                'bedrooms' => 3, 'bathrooms' => 2, 'square_feet' => 2105,
                'image' => 'photo-1580587771525-78b9dba3b914',
            ],
            [
                'process' => 'Michigan Residential Listing Process',
                'package' => 'Michigan Full Service MLS Package',
                'reference_code' => 'REO-MITRY1',
                'address' => '5600 Long Lake Road',
                'city' => 'Troy', 'state' => 'MI', 'zip' => '48098',
                'title' => 'Spacious Troy Townhouse in Top School District',
                'price' => 334000, 'property_type' => 'townhouse',
                'bedrooms' => 3, 'bathrooms' => 3, 'square_feet' => 1760,
                'image' => 'photo-1576941089067-2de3c901e126',
            ],
        ];

        DB::transaction(function () use ($listings, $seller) {
            foreach ($listings as $data) {
                $process = ListingProcess::where('name', $data['process'])->first();
                $package = ServicePackage::where('name', $data['package'])->first();

                if (! $process) {
                    continue;
                }

                $snapshot = $process->config ?? [];
                $publishedAt = Carbon::now()->subDays(rand(2, 20));

                $listing = Listing::updateOrCreate(
                    ['reference_code' => $data['reference_code']],
                    [
                        'user_id' => $seller->id,
                        'address' => $data['address'],
                        'city' => $data['city'],
                        'state' => $data['state'],
                        'zip' => $data['zip'],
                        'status' => 'published',
                        'moderation_status' => 'auto_approved',
                        'moderated_at' => $publishedAt,
                        'service_package_id' => $package?->id,
                        'listing_process_id' => $process->id,
                        'workflow_snapshot' => $snapshot,
                        'steps_completed' => count($snapshot),
                        'steps_total' => count($snapshot),
                        'status_changed_date' => $publishedAt,
                    ]
                );

                $listing->answers()->updateOrCreate(
                    ['step_id' => 'system_public_info'],
                    [
                        'values' => [
                            'listing_title' => $data['title'],
                            'listing_price' => $data['price'],
                            'property_type' => $data['property_type'],
                            'bedrooms' => $data['bedrooms'],
                            'bathrooms' => $data['bathrooms'],
                            'square_feet' => $data['square_feet'],
                        ],
                    ]
                );

                if ($listing->images()->count() === 0) {
                    $base = 'https://images.unsplash.com/'.$data['image'].'?auto=format&fit=crop';
                    ListingImage::create([
                        'listing_id' => $listing->id,
                        'url' => $base.'&w=1200&q=80',
                        'thumbnail_url' => $base.'&w=300&q=80',
                        'alt_text' => 'Front Exterior View',
                        'is_primary' => true,
                        'is_public' => true,
                        'sort_order' => 1,
                    ]);
                }

                if ($listing->statusHistories()->count() === 0) {
                    ListingStatusHistory::create([
                        'listing_id' => $listing->id,
                        'from_status' => 'in_progress',
                        'to_status' => 'published',
                        'actor_id' => $seller->id,
                        'actor_role' => 'seller',
                        'comment' => 'Sample '.$data['state'].' listing seeded and auto-approved.',
                    ]);
                }
            }
        });
    }
}

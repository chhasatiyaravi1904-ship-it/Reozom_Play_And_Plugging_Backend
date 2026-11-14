<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\ListingOffer;
use App\Models\ListingOfferAction;
use App\Models\ListingProcess;
use App\Models\ListingStatusHistory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PublishedListingSeeder extends Seeder
{
    public function run(): void
    {
        $seller = User::where('email', 'seller@example.com')->first()
            ?? User::where('email', 'seller@gmail.com')->first()
            ?? User::where('role', \App\Enums\UserRole::Seller)->first()
            ?? User::first();

        if (!$seller) {
            return;
        }

        $admin = User::where('email', 'admin@example.com')->first()
            ?? User::where('role', \App\Enums\UserRole::Admin)->first()
            ?? User::first();

        $buyer = User::where('email', 'buyer@example.com')->first()
            ?? User::where('role', \App\Enums\UserRole::Buyer)->first();

        $miProcess = ListingProcess::where('name', 'Michigan Residential Listing Process')->first();
        $defaultProcess = ListingProcess::where('name', 'Residential Property Listing Process')->first()
            ?? $miProcess
            ?? ListingProcess::first();

        $miPackage = ServicePackage::where('name', 'Michigan Full Service MLS Package')->first()
            ?? ServicePackage::first();

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
                'title' => 'Luxury Downtown Detroit Loft with Skyline Views',
                'price' => 485000,
                'property_type' => 'condo',
                'bedrooms' => 2,
                'bathrooms' => 2,
                'square_feet' => 1650,
                'address' => '220 W Congress St',
                'city' => 'Detroit',
                'state' => 'MI',
                'zip' => '48226',
                'status' => 'published',
                'moderation_status' => 'approved',
                'image' => 'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Luxury urban loft penthouse in downtown Detroit',
                'is_michigan' => true,
            ],
            [
                'title' => 'Classic Craftsman Near Central Campus',
                'price' => 595000,
                'property_type' => 'single_family',
                'bedrooms' => 4,
                'bathrooms' => 3,
                'square_feet' => 2400,
                'address' => '812 S State St',
                'city' => 'Ann Arbor',
                'state' => 'MI',
                'zip' => '48104',
                'status' => 'published',
                'moderation_status' => 'approved',
                'image' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Beautiful craftsman single-family home in Ann Arbor',
                'is_michigan' => true,
            ],
            [
                'title' => 'Historic Heritage Hill Victorian Manor',
                'price' => 675000,
                'property_type' => 'single_family',
                'bedrooms' => 5,
                'bathrooms' => 3.5,
                'square_feet' => 3800,
                'address' => '450 Heritage Hill Way',
                'city' => 'Grand Rapids',
                'state' => 'MI',
                'zip' => '49503',
                'status' => 'published',
                'moderation_status' => 'approved',
                'image' => 'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Stately historic Victorian estate in Grand Rapids',
                'is_michigan' => true,
            ],
            [
                'title' => 'Modern Contemporary Home with Pool',
                'price' => 825000,
                'property_type' => 'single_family',
                'bedrooms' => 4,
                'bathrooms' => 3,
                'square_feet' => 2850,
                'address' => '1428 Congress Ave',
                'city' => 'Austin',
                'state' => 'TX',
                'zip' => '78701',
                'status' => 'published',
                'moderation_status' => 'approved',
                'image' => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Modern contemporary family home exterior in Austin Texas',
                'is_michigan' => false,
            ],
            [
                'title' => 'Urban Waterfront Loft Penthouse',
                'price' => 920000,
                'property_type' => 'condo',
                'bedrooms' => 2,
                'bathrooms' => 2,
                'square_feet' => 1450,
                'address' => '950 Brickell Bay Dr #420',
                'city' => 'Miami',
                'state' => 'FL',
                'zip' => '33131',
                'status' => 'published',
                'moderation_status' => 'approved',
                'image' => 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'High-rise waterfront penthouse loft in Miami',
                'is_michigan' => false,
            ],
            [
                'title' => 'Sunlit California Modern Villa',
                'price' => 1150000,
                'property_type' => 'single_family',
                'bedrooms' => 4,
                'bathrooms' => 3.5,
                'square_feet' => 3100,
                'address' => '1240 S Grand Ave',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'zip' => '90001',
                'status' => 'published',
                'moderation_status' => 'approved',
                'image' => 'https://images.unsplash.com/photo-1600566753376-12c8ab7fb75b?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Modern California architectural estate with private courtyard',
                'is_michigan' => false,
            ],
            [
                'title' => 'Magnificent Mile Luxury High-Rise',
                'price' => 740000,
                'property_type' => 'condo',
                'bedrooms' => 3,
                'bathrooms' => 2,
                'square_feet' => 2100,
                'address' => '180 E Pearson St',
                'city' => 'Chicago',
                'state' => 'IL',
                'zip' => '60611',
                'status' => 'published',
                'moderation_status' => 'approved',
                'image' => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'High rise condominium near downtown Chicago',
                'is_michigan' => false,
            ],
            [
                'title' => 'Executive Suburban Estate with Private Grounds',
                'price' => 890000,
                'property_type' => 'single_family',
                'bedrooms' => 5,
                'bathrooms' => 4,
                'square_feet' => 4200,
                'address' => '3250 W Big Beaver Rd',
                'city' => 'Troy',
                'state' => 'MI',
                'zip' => '48084',
                'status' => 'under_contract',
                'moderation_status' => 'approved',
                'image' => 'https://images.unsplash.com/photo-1580587771525-78b9dba3b914?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Spacious executive home in Troy Michigan',
                'is_michigan' => true,
            ],
            [
                'title' => 'Charming Brick Colonial Residence',
                'price' => 349000,
                'property_type' => 'single_family',
                'bedrooms' => 3,
                'bathrooms' => 2,
                'square_feet' => 1850,
                'address' => '1600 Michigan Ave',
                'city' => 'Dearborn',
                'state' => 'MI',
                'zip' => '48126',
                'status' => 'submitted',
                'moderation_status' => 'pending',
                'image' => 'https://images.unsplash.com/photo-1568605117036-5fe5e7bab0b7?auto=format&fit=crop&w=1200&q=80',
                'alt' => 'Classic brick colonial home in Dearborn',
                'is_michigan' => true,
            ],
        ];

        foreach ($properties as $index => $prop) {
            $ref = 'RZ-' . strtoupper(Str::random(6));
            $processId = $prop['is_michigan'] ? ($miProcess?->id ?? $defaultProcess?->id) : ($defaultProcess?->id ?? $miProcess?->id);
            $servicePackageId = $prop['is_michigan'] ? $miPackage?->id : null;

            $listing = Listing::firstOrCreate(
                ['address' => $prop['address']],
                [
                    'user_id' => $seller->id,
                    'reference_code' => $ref,
                    'address' => $prop['address'],
                    'city' => $prop['city'],
                    'state' => $prop['state'],
                    'zip' => $prop['zip'],
                    'status' => $prop['status'],
                    'moderation_status' => $prop['moderation_status'],
                    'moderated_by' => $prop['moderation_status'] === 'approved' ? $admin?->id : null,
                    'moderated_at' => $prop['moderation_status'] === 'approved' ? now() : null,
                    'service_package_id' => $servicePackageId,
                    'listing_process_id' => $processId,
                    'workflow_snapshot' => $standardWorkflowSnapshot,
                    'steps_total' => 4,
                    'steps_completed' => $prop['status'] === 'in_progress' ? 1 : 4,
                ]
            );

            // Update attributes to match current spec
            $listing->update([
                'user_id' => $seller->id,
                'city' => $prop['city'],
                'state' => $prop['state'],
                'zip' => $prop['zip'],
                'status' => $prop['status'],
                'moderation_status' => $prop['moderation_status'],
                'moderated_by' => $prop['moderation_status'] === 'approved' ? $admin?->id : null,
                'moderated_at' => $prop['moderation_status'] === 'approved' ? now() : null,
                'service_package_id' => $servicePackageId,
                'listing_process_id' => $processId,
                'workflow_snapshot' => $standardWorkflowSnapshot,
                'steps_total' => 4,
                'steps_completed' => $prop['status'] === 'in_progress' ? 1 : 4,
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

            // Create initial status history entry
            if ($listing->statusHistories()->count() === 0) {
                $listing->statusHistories()->create([
                    'from_status' => 'draft',
                    'to_status' => $prop['status'],
                    'actor_id' => $seller->id,
                    'actor_role' => 'seller',
                    'comment' => 'Initial setup during database seed.',
                    'metadata' => ['seed' => true],
                ]);
            }

            // For the under_contract listing, seed a sample accepted offer if buyer exists
            if ($prop['status'] === 'under_contract' && $buyer && $listing->offers()->count() === 0) {
                $offer = ListingOffer::create([
                    'listing_id' => $listing->id,
                    'buyer_id' => $buyer->id,
                    'reference_code' => 'OFF-' . strtoupper(Str::random(6)),
                    'status' => ListingOffer::STATUS_ACCEPTED,
                    'offer_amount' => 880000,
                    'earnest_money_deposit' => 25000,
                    'down_payment_amount' => 176000,
                    'payment_method' => 'conventional_mortgage',
                    'closing_date' => now()->addDays(30)->toDateString(),
                    'occupancy_type' => 'at_closing',
                    'contingencies' => ['inspection', 'appraisal', 'financing'],
                    'deadline' => now()->addDays(3),
                    'buyer_name' => $buyer->name,
                    'buyer_email' => $buyer->email,
                    'buyer_phone' => '248-555-0188',
                ]);

                ListingOfferAction::create([
                    'listing_offer_id' => $offer->id,
                    'user_id' => $seller->id,
                    'actor_type' => 'seller',
                    'action_type' => 'accepted',
                    'offer_amount' => 880000,
                    'comment' => 'Offer accepted by seller.',
                ]);
            }
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\ListingDocument;
use App\Models\ListingEunoAgreement;
use App\Models\ListingImage;
use App\Models\ListingOffer;
use App\Models\ListingOfferAction;
use App\Models\ListingOfferDocument;
use App\Models\ListingOfferPurchaser;
use App\Models\ListingProcess;
use App\Models\ListingSoldDetails;
use App\Models\ListingStatusHistory;
use App\Models\ServicePackage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ReozomFullWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Seeding Reozom Full Workflow (Listings, Document Signing, and Offer Management)...');

        // 1. Ensure Standard Role Users Exist
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin User', 'password' => Hash::make('password'), 'role' => 'admin']
        );

        $agent = User::firstOrCreate(
            ['email' => 'agent@example.com'],
            ['name' => 'Agent Justin', 'password' => Hash::make('password'), 'role' => 'agent']
        );

        $seller = User::firstOrCreate(
            ['email' => 'seller@example.com'],
            ['name' => 'Seller Sarah', 'password' => Hash::make('password'), 'role' => 'seller']
        );

        $buyer1 = User::firstOrCreate(
            ['email' => 'buyer@example.com'],
            ['name' => 'Buyer Michael', 'password' => Hash::make('password'), 'role' => 'buyer']
        );

        $buyer2 = User::firstOrCreate(
            ['email' => 'buyer2@example.com'],
            ['name' => 'Buyer Emily', 'password' => Hash::make('password'), 'role' => 'buyer']
        );

        // 2. Ensure Service Package & Agent Custom Dynamic Process
        $package = ServicePackage::firstOrCreate(
            ['name' => 'Michigan Full Service MLS Package'],
            [
                'agent_id' => $agent->id,
                'description' => 'Comprehensive MLS Flat-Fee listing package with licensed agent support.',
                'price' => 295.00,
                'is_active' => true,
            ]
        );

        // Only the Michigan and Florida processes exist (original Reozom), so these demo listings
        // (Ann Arbor, MI) use the Michigan one instead of creating an extra agent process.
        $agentCustomProcess = ListingProcess::where('name', 'Michigan Residential Listing Process')->first()
            ?? ListingProcess::where('status', 'active')->whereNotNull('config')->firstOrFail();
        $snapshot = $agentCustomProcess->config ?? [];

        $package->update(['listing_process_id' => $agentCustomProcess->id]);

        // =========================================================================
        // SCENARIO 1: LIVE PUBLISHED LISTING WITH ACTIVE OFFERS & EUNODOC AGREEMENT
        // =========================================================================
        $listing1 = Listing::updateOrCreate(
            ['address' => '742 Evergreen Terrace'],
            [
                'user_id' => $seller->id,
                'reference_code' => 'REO-EVG742',
                'city' => 'Ann Arbor',
                'state' => 'MI',
                'zip' => '48104',
                'status' => 'published',
                'moderation_status' => 'auto_approved',
                'moderated_at' => Carbon::now()->subDays(5),
                'service_package_id' => $package->id,
                'listing_process_id' => $agentCustomProcess->id,
                'workflow_snapshot' => $snapshot,
                'steps_completed' => count($snapshot),
                'steps_total' => count($snapshot),
                'status_changed_date' => Carbon::now()->subDays(5),
            ]
        );

        // Answers
        $listing1->answers()->updateOrCreate(
            ['step_id' => 'system_public_info'],
            [
                'values' => [
                    'listing_title' => 'Charming Craftsman Estate in Central Ann Arbor',
                    'listing_price' => 540000,
                    'property_type' => 'single_family',
                    'bedrooms' => 4,
                    'bathrooms' => 3,
                    'square_feet' => 2650,
                ],
            ]
        );

        // Photos
        if ($listing1->images()->count() === 0) {
            ListingImage::create([
                'listing_id' => $listing1->id,
                'url' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=80',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=300&q=80',
                'alt_text' => 'Front Exterior View',
                'is_primary' => true,
                'is_public' => true,
                'sort_order' => 1,
            ]);

            ListingImage::create([
                'listing_id' => $listing1->id,
                'url' => 'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?auto=format&fit=crop&w=1200&q=80',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?auto=format&fit=crop&w=300&q=80',
                'alt_text' => 'Gourmet Kitchen',
                'is_primary' => false,
                'is_public' => true,
                'sort_order' => 2,
            ]);
        }

        // Statutory Signed Documents (Listing Agreement, Disclosures, Deed, ID)
        $docTypes = [
            'listing_agreement' => 'Exclusive Right to Sell Listing Agreement',
            'sellers_disclosure' => "Seller's Disclosure Statement",
            'lead_disclosure' => 'Lead-Based Paint Hazard Disclosure',
            'agency_disclosure' => 'Agency Disclosure Statement',
            'deed' => 'Recorded Property Deed / Title',
            'id_proof' => 'Government Photo ID',
        ];

        foreach ($docTypes as $type => $label) {
            ListingDocument::updateOrCreate(
                ['listing_id' => $listing1->id, 'type' => $type],
                [
                    'user_id' => $seller->id,
                    'original_name' => "{$label}.pdf",
                    'path' => "listings/{$listing1->id}/docs/{$type}.pdf",
                    'mime_type' => 'application/pdf',
                    'size' => 125000,
                    'status' => 'signed',
                    'signed_at' => Carbon::now()->subDays(5),
                    'signed_by' => $seller->name,
                    'admin_notes' => 'Digitally signed and verified via EunoDoc e-sign.',
                ]
            );
        }

        // EunoDoc Agreement Record
        ListingEunoAgreement::updateOrCreate(
            ['listing_id' => $listing1->id, 'document_type' => 'disclosures_bundle'],
            [
                'initiated_by_user_id' => $seller->id,
                'document_name' => "Disclosures Packet - {$listing1->reference_code}",
                'agreement_id' => 'euno_agr_' . Str::random(12),
                'signing_url' => 'https://eunodoc.reozom.com/sign/' . Str::random(16),
                'status' => 'completed',
                'signers' => [
                    ['name' => $seller->name, 'email' => $seller->email, 'status' => 'signed'],
                ],
                'sent_at' => Carbon::now()->subDays(5),
                'completed_at' => Carbon::now()->subDays(5),
            ]
        );

        // OFFERS ON LISTING 1:
        // Offer A: Submitted Offer with Net Calculations & Contingencies
        $offerA = ListingOffer::updateOrCreate(
            ['listing_id' => $listing1->id, 'buyer_id' => $buyer1->id],
            [
                'reference_code' => 'OFF-MCH101',
                'status' => ListingOffer::STATUS_SUBMITTED,
                'offer_amount' => 530000.00,
                'earnest_money_deposit' => 15000.00,
                'down_payment_amount' => 106000.00,
                'payment_method' => 'conventional',
                'closing_date' => Carbon::now()->addDays(40)->toDateString(),
                'occupancy_type' => 'at_closing',
                'seller_concessions' => 6000.00,
                'commission' => 3.00,
                'commission_type' => 1, // Percent
                'home_warranty_included' => true,
                'home_warranty_price' => 525.00,
                'contingencies' => [
                    'property_inspection' => true,
                    'property_inspection_days' => 7,
                    'appraisal' => true,
                    'financing' => true,
                    'sale_of_home' => false,
                ],
                'deadline' => Carbon::now()->addHours(36),
                'buyer_name' => $buyer1->name,
                'buyer_email' => $buyer1->email,
                'buyer_phone' => '(734) 555-0144',
                'has_agent_representation' => true,
                'agent_name' => 'Keller Realty (Jane Smith)',
                'additional_terms' => 'Buyer requests washer and dryer to stay with home.',
            ]
        );

        // Purchasers on Offer A
        ListingOfferPurchaser::updateOrCreate(
            ['listing_offer_id' => $offerA->id, 'first_name' => 'Michael', 'last_name' => 'Brown'],
            [
                'email' => $buyer1->email,
                'street_address' => '120 S Main St',
                'city' => 'Ann Arbor',
                'zip' => '48104',
                'is_main' => true,
            ]
        );

        // Document on Offer A
        ListingOfferDocument::updateOrCreate(
            ['listing_offer_id' => $offerA->id, 'type' => 'pre_approval_letter'],
            [
                'user_id' => $buyer1->id,
                'original_name' => 'Chase_Bank_PreApproval_Letter.pdf',
                'path' => "offers/{$offerA->id}/pre_approval.pdf",
                'mime_type' => 'application/pdf',
                'size' => 245000,
            ]
        );

        // Action on Offer A
        ListingOfferAction::updateOrCreate(
            ['listing_offer_id' => $offerA->id, 'action_type' => 'submitted'],
            [
                'user_id' => $buyer1->id,
                'actor_type' => 'buyer',
                'offer_amount' => 530000.00,
                'comment' => 'Official purchase agreement submitted per Reozom terms.',
            ]
        );

        // Offer B: Active Counter-Offer Negotiation (Seller Countered Buyer 2)
        $offerB = ListingOffer::updateOrCreate(
            ['listing_id' => $listing1->id, 'buyer_id' => $buyer2->id],
            [
                'reference_code' => 'OFF-EML202',
                'status' => ListingOffer::STATUS_COUNTERED_BY_SELLER,
                'offer_amount' => 535000.00, // Countered price
                'earnest_money_deposit' => 20000.00,
                'down_payment_amount' => 150000.00,
                'payment_method' => 'conventional',
                'closing_date' => Carbon::now()->addDays(30)->toDateString(),
                'occupancy_type' => 'days_after_closing',
                'occupancy_days' => 14,
                'fee_per_day' => 75.00,
                'seller_concessions' => 2000.00, // Seller lowered concessions
                'commission' => 2.50,
                'commission_type' => 1,
                'contingencies' => [
                    'property_inspection' => true,
                    'appraisal' => true,
                    'financing' => true,
                    'sale_of_home' => false,
                ],
                'deadline' => Carbon::now()->addHours(24),
                'buyer_name' => $buyer2->name,
                'buyer_email' => $buyer2->email,
                'has_agent_representation' => false,
                'additional_terms' => 'Seller counters: 14 days post-closing occupancy at $75/day.',
            ]
        );

        ListingOfferAction::updateOrCreate(
            ['listing_offer_id' => $offerB->id, 'action_type' => 'counter_sent_by_seller'],
            [
                'user_id' => $seller->id,
                'actor_type' => 'seller',
                'offer_amount' => 535000.00,
                'comment' => 'Seller counters price to $535,000 and requires 14 days post-closing occupancy.',
            ]
        );

        // =========================================================================
        // SCENARIO 2: UNDER CONTRACT LISTING (Accepted Offer with Sold Details)
        // =========================================================================
        $listing2 = Listing::updateOrCreate(
            ['address' => '1024 Woodward Ave'],
            [
                'user_id' => $seller->id,
                'reference_code' => 'REO-WDW102',
                'city' => 'Detroit',
                'state' => 'MI',
                'zip' => '48226',
                'status' => 'under_contract',
                'moderation_status' => 'approved',
                'service_package_id' => $package->id,
                'listing_process_id' => $agentCustomProcess->id,
                'workflow_snapshot' => $snapshot,
                'steps_completed' => count($snapshot),
                'steps_total' => count($snapshot),
                'status_changed_date' => Carbon::now()->subDays(2),
            ]
        );

        $listing2->answers()->updateOrCreate(
            ['step_id' => 'system_public_info'],
            [
                'values' => [
                    'listing_title' => 'Renovated Midtown Historic Brownstone',
                    'listing_price' => 495000,
                    'property_type' => 'single_family',
                    'bedrooms' => 3,
                    'bathrooms' => 2.5,
                    'square_feet' => 2200,
                ],
            ]
        );

        $offerAccepted = ListingOffer::updateOrCreate(
            ['listing_id' => $listing2->id, 'buyer_id' => $buyer1->id],
            [
                'reference_code' => 'OFF-ACC450',
                'status' => ListingOffer::STATUS_ACCEPTED,
                'offer_amount' => 490000.00,
                'earnest_money_deposit' => 15000.00,
                'payment_method' => 'conventional',
                'closing_date' => Carbon::now()->addDays(28)->toDateString(),
                'buyer_name' => $buyer1->name,
                'has_agent_representation' => true,
                'agent_name' => 'Signature Sotheby (David Lee)',
            ]
        );

        ListingSoldDetails::updateOrCreate(
            ['listing_id' => $listing2->id],
            [
                'anticipated_closed_date' => Carbon::now()->addDays(28),
                'sales_price' => 490000.00,
                'sellers_concessions' => 0,
                'finance_type' => 'conventional',
                'agent' => 1,
                'agent_name' => 'Signature Sotheby (David Lee)',
            ]
        );

        // =========================================================================
        // SCENARIO 3: CCS LISTING (Contingent Continue to Show - Home Sale Clause)
        // =========================================================================
        $listing3 = Listing::updateOrCreate(
            ['address' => '350 Lakeshore Drive'],
            [
                'user_id' => $seller->id,
                'reference_code' => 'REO-LKS350',
                'city' => 'Grosse Pointe Farms',
                'state' => 'MI',
                'zip' => '48236',
                'status' => 'ccs',
                'moderation_status' => 'approved',
                'service_package_id' => $package->id,
                'listing_process_id' => $agentCustomProcess->id,
                'workflow_snapshot' => $snapshot,
                'steps_completed' => count($snapshot),
                'steps_total' => count($snapshot),
                'status_changed_date' => Carbon::now()->subDays(3),
            ]
        );

        $listing3->answers()->updateOrCreate(
            ['step_id' => 'system_public_info'],
            [
                'values' => [
                    'listing_title' => 'Waterfront Colonial Estate on Lake St. Clair',
                    'listing_price' => 785000,
                    'property_type' => 'single_family',
                    'bedrooms' => 5,
                    'bathrooms' => 4,
                    'square_feet' => 3800,
                ],
            ]
        );

        $offerCcs = ListingOffer::updateOrCreate(
            ['listing_id' => $listing3->id, 'buyer_id' => $buyer2->id],
            [
                'reference_code' => 'OFF-CCS770',
                'status' => ListingOffer::STATUS_ACCEPTED,
                'offer_amount' => 775000.00,
                'earnest_money_deposit' => 25000.00,
                'payment_method' => 'conventional',
                'closing_date' => Carbon::now()->addDays(60)->toDateString(),
                'contingencies' => [
                    'sale_of_home' => true,
                    'property_inspection' => true,
                ],
                'buyer_name' => $buyer2->name,
                'has_agent_representation' => true,
                'agent_name' => 'Real Estate One',
            ]
        );

        ListingSoldDetails::updateOrCreate(
            ['listing_id' => $listing3->id],
            [
                'anticipated_closed_date' => Carbon::now()->addDays(60),
                'sales_price' => 775000.00,
                'finance_type' => 'conventional',
                'agent' => 1,
                'agent_name' => 'Real Estate One',
            ]
        );

        // =========================================================================
        // SCENARIO 4: SOLD LISTING (Fully Closed Transaction with Closing Statement)
        // =========================================================================
        $listing4 = Listing::updateOrCreate(
            ['address' => '500 Forest Ave'],
            [
                'user_id' => $seller->id,
                'reference_code' => 'REO-SLD500',
                'city' => 'Plymouth',
                'state' => 'MI',
                'zip' => '48170',
                'status' => 'sold',
                'moderation_status' => 'approved',
                'service_package_id' => $package->id,
                'listing_process_id' => $agentCustomProcess->id,
                'workflow_snapshot' => $snapshot,
                'steps_completed' => count($snapshot),
                'steps_total' => count($snapshot),
                'status_changed_date' => Carbon::now()->subMonths(1),
            ]
        );

        $listing4->answers()->updateOrCreate(
            ['step_id' => 'system_public_info'],
            [
                'values' => [
                    'listing_title' => 'Charming Downtown Plymouth Victorian',
                    'listing_price' => 420000,
                    'property_type' => 'single_family',
                    'bedrooms' => 3,
                    'bathrooms' => 2,
                    'square_feet' => 1950,
                ],
            ]
        );

        ListingSoldDetails::updateOrCreate(
            ['listing_id' => $listing4->id],
            [
                'closed_date' => Carbon::now()->subMonths(1),
                'sales_price' => 415000.00,
                'sellers_concessions' => 1,
                'amount' => 4500.00,
                'finance_type' => 'conventional',
                'agent' => 1,
                'agent_name' => 'Berkshire Hathaway HomeServices',
                'closing_document' => 'Plymouth_Closing_Settlement_Statement.pdf',
            ]
        );

        // Audit Trail Histories
        ListingStatusHistory::create([
            'listing_id' => $listing1->id,
            'from_status' => 'in_progress',
            'to_status' => 'published',
            'actor_id' => $seller->id,
            'actor_role' => 'seller',
            'comment' => 'Listing requirements completed and auto-approved for public marketplace.',
        ]);

        $this->command?->info('✓ Seeded Users: Admin, Agent, Seller, Buyer 1, Buyer 2');
        $this->command?->info("✓ Seeded Listing 1 (#{$listing1->id} - Published): 742 Evergreen Terrace (with 2 offers & EunoDoc agreements)");
        $this->command?->info("✓ Seeded Listing 2 (#{$listing2->id} - Under Contract): 1024 Woodward Ave");
        $this->command?->info("✓ Seeded Listing 3 (#{$listing3->id} - CCS): 350 Lakeshore Drive");
        $this->command?->info("✓ Seeded Listing 4 (#{$listing4->id} - Sold): 500 Forest Ave");
    }
}


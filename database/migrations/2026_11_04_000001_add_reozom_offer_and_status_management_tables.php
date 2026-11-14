<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add Reozom financial & negotiation columns to listing_offers
        Schema::table('listing_offers', function (Blueprint $table) {
            if (!Schema::hasColumn('listing_offers', 'closing_amount_type')) {
                $table->tinyInteger('closing_amount_type')->default(2)->after('seller_concessions'); // 1 - %, 2 - $
            }
            if (!Schema::hasColumn('listing_offers', 'fee_per_day')) {
                $table->decimal('fee_per_day', 8, 2)->default(0)->after('occupancy_days');
            }
            if (!Schema::hasColumn('listing_offers', 'commission')) {
                $table->decimal('commission', 8, 2)->default(0)->after('fee_per_day');
            }
            if (!Schema::hasColumn('listing_offers', 'commission_type')) {
                $table->tinyInteger('commission_type')->default(1)->after('commission'); // 1 - %, 2 - $
            }
            if (!Schema::hasColumn('listing_offers', 'home_warranty_included')) {
                $table->boolean('home_warranty_included')->default(false)->after('commission_type');
            }
            if (!Schema::hasColumn('listing_offers', 'home_warranty_price')) {
                $table->decimal('home_warranty_price', 8, 2)->nullable()->after('home_warranty_included');
            }
            if (!Schema::hasColumn('listing_offers', 'buyers_home_address_under_contract')) {
                $table->string('buyers_home_address_under_contract', 500)->nullable()->after('home_warranty_price');
            }
            if (!Schema::hasColumn('listing_offers', 'mortgage_application_provided_by')) {
                $table->date('mortgage_application_provided_by')->nullable()->after('buyers_home_address_under_contract');
            }
            if (!Schema::hasColumn('listing_offers', 'loan_approval_provided_by')) {
                $table->date('loan_approval_provided_by')->nullable()->after('mortgage_application_provided_by');
            }
        });

        // Add status_changed_date to listings if missing
        Schema::table('listings', function (Blueprint $table) {
            if (!Schema::hasColumn('listings', 'status_changed_date')) {
                $table->timestamp('status_changed_date')->nullable()->after('status');
            }
        });

        // Create listing_sold_details table (stores pending/sold closing details and documents)
        if (!Schema::hasTable('listing_sold_details')) {
            Schema::create('listing_sold_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
                $table->dateTime('anticipated_closed_date')->nullable();
                $table->dateTime('closed_date')->nullable();
                $table->decimal('sales_price', 12, 2)->nullable();
                $table->tinyInteger('sellers_concessions')->nullable(); // 1 - Yes, 2 - No
                $table->decimal('amount', 12, 2)->nullable();
                $table->string('finance_type', 64)->nullable();
                $table->tinyInteger('agent')->nullable(); // 1 - Yes, 2 - No
                $table->string('agent_name', 150)->nullable();
                $table->string('company', 150)->nullable();
                $table->string('purchase_agreement', 500)->nullable();
                $table->string('closing_document', 500)->nullable();
                $table->timestamps();
            });
        }

        // Create listing_offer_purchasers table
        if (!Schema::hasTable('listing_offer_purchasers')) {
            Schema::create('listing_offer_purchasers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('listing_offer_id')->constrained('listing_offers')->cascadeOnDelete();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('email', 150);
                $table->string('street_address', 255)->nullable();
                $table->string('city', 100)->nullable();
                $table->string('unit_designator', 50)->nullable();
                $table->string('unit_number', 50)->nullable();
                $table->string('state_id', 50)->nullable();
                $table->string('zip', 20)->nullable();
                $table->tinyInteger('same_address')->default(0);
                $table->tinyInteger('non_us_citizen')->default(0);
                $table->boolean('is_main')->default(false);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_offer_purchasers');
        Schema::dropIfExists('listing_sold_details');

        Schema::table('listings', function (Blueprint $table) {
            if (Schema::hasColumn('listings', 'status_changed_date')) {
                $table->dropColumn('status_changed_date');
            }
        });

        Schema::table('listing_offers', function (Blueprint $table) {
            $columns = [
                'closing_amount_type',
                'fee_per_day',
                'commission',
                'commission_type',
                'home_warranty_included',
                'home_warranty_price',
                'buyers_home_address_under_contract',
                'mortgage_application_provided_by',
                'loan_approval_provided_by',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('listing_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};


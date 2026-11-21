<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual withdrawals: sellers pay the platform, agents request their share, and an admin
     * pays it by bank transfer and marks the request paid (or rejects it).
     */
    public function up(): void
    {
        // One bank account per agent. Routing/account numbers are stored encrypted (model casts).
        Schema::create('agent_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('account_holder_name');
            $table->string('bank_name');
            $table->text('routing_number');
            $table->text('account_number');
            $table->string('account_last4', 4);
            $table->string('account_type', 10); // checking | savings
            $table->timestamps();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->change();
            // manual = bank transfer by an admin; stripe = old Connect payout
            $table->string('method', 10)->default('manual')->after('agent_id');
            // Encrypted copy of the bank details the request was made with
            $table->text('bank_details')->nullable()->after('currency');
            $table->string('reference')->nullable()->after('status'); // admin's transfer reference
            $table->foreignId('processed_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->after('processed_by');
        });

        // Payouts made before this change were Stripe Connect payouts
        DB::table('payouts')->whereNotNull('stripe_payout_id')->update(['method' => 'stripe']);
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('processed_by');
            $table->dropColumn(['method', 'bank_details', 'reference', 'processed_at']);
        });

        Schema::dropIfExists('agent_bank_accounts');
    }
};

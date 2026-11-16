<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default(UserRole::SellerBuyer->value)->change();
        });

        DB::table('users')
            ->whereIn('role', ['client', 'seller', 'buyer', 'seller/buyer'])
            ->update(['role' => UserRole::SellerBuyer->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('seller_buyer')->change();
        });
    }
};


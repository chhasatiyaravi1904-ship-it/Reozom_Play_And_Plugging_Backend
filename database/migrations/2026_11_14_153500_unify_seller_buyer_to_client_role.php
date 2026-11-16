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
        // Change role column to string to allow unified 'client' alongside any legacy role aliases
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default(UserRole::SellerBuyer->value)->change();
        });

        // Unify existing seller and buyer records to client
        DB::table('users')
            ->whereIn('role', ['seller', 'buyer', 'client'])
            ->update(['role' => UserRole::SellerBuyer->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('buyer')->change();
        });
    }
};


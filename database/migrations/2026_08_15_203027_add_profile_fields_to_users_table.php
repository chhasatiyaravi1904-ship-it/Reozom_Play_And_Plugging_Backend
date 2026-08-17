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
        Schema::table('users', function (Blueprint $table) {
            $table->string('street_address')->nullable()->after('phone');
            $table->string('city')->nullable()->after('street_address');
            $table->string('state')->nullable()->after('city');
            $table->string('zip', 10)->nullable()->after('state');
            $table->string('company')->nullable()->after('zip');
            $table->string('office_number')->nullable()->after('company');
            $table->string('extension', 10)->nullable()->after('office_number');
            $table->boolean('profile_finished')->default(false)->after('extension');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'street_address',
                'city',
                'state',
                'zip',
                'company',
                'office_number',
                'extension',
                'profile_finished',
            ]);
        });
    }
};

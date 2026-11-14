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
        Schema::table('listing_documents', function (Blueprint $table) {
            $table->longText('signature_image')->nullable()->after('signed_by');
            $table->string('signature_type', 32)->nullable()->after('signature_image'); // 'drawn', 'typed'
            $table->text('initials_image')->nullable()->after('signature_type');
            $table->string('signer_ip', 64)->nullable()->after('initials_image');
            $table->string('verification_code', 64)->nullable()->after('signer_ip');
            $table->json('audit_trail')->nullable()->after('verification_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listing_documents', function (Blueprint $table) {
            $table->dropColumn([
                'signature_image',
                'signature_type',
                'initials_image',
                'signer_ip',
                'verification_code',
                'audit_trail',
            ]);
        });
    }
};


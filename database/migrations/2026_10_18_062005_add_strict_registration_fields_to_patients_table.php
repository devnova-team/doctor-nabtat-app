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
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('national_id', 14)->nullable()->after('name');
            $table->string('blood_type', 3)->nullable()->after('gender');
            $table->text('chronic_diseases')->nullable()->after('blood_type');
            $table->unique(['clinic_id', 'national_id']);
            $table->index(['clinic_id', 'phone', 'national_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique(['clinic_id', 'national_id']);
            $table->dropIndex(['clinic_id', 'phone', 'national_id']);
            $table->dropColumn(['national_id', 'blood_type', 'chronic_diseases']);
        });
    }
};

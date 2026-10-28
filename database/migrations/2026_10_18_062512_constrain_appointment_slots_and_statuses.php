<?php

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
        DB::table('appointments')->where('status', 'completed')->update(['status' => 'attended']);
        DB::table('appointments')->where('status', 'missed')->update(['status' => 'cancelled']);

        Schema::table('appointments', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'confirmed', 'attended', 'cancelled'])->default('pending')->change();
            $table->unique(['clinic_id', 'doctor_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('appointments')->where('status', 'attended')->update(['status' => 'completed']);

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique(['clinic_id', 'doctor_id', 'starts_at']);
            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled', 'missed'])->default('pending')->change();
        });
    }
};

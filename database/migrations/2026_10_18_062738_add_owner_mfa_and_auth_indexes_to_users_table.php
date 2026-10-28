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
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', ['owner', 'admin', 'doctor', 'receptionist'])->default('receptionist')->change();
            $table->text('mfa_secret')->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->unsignedInteger('token_version')->default(0);
            $table->string('phone', 30)->nullable();
            $table->index(['email', 'clinic_id']);
            $table->dropForeign(['clinic_id']);
            $table->foreign('clinic_id')->references('id')->on('clinics')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->where('role', 'owner')->update(['role' => 'admin']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['clinic_id']);
            $table->foreign('clinic_id')->references('id')->on('clinics')->nullOnDelete();
            $table->dropIndex(['email', 'clinic_id']);
            $table->dropColumn(['mfa_secret', 'mfa_enabled', 'token_version', 'phone']);
            $table->enum('role', ['doctor', 'receptionist', 'admin'])->default('receptionist')->change();
        });
    }
};

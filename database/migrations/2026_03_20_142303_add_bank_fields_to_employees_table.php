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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('bank_name', 255)->nullable()->after('password');
            $table->string('account_number', 50)->nullable()->after('bank_name');
            $table->string('ifsc_code', 50)->nullable()->after('account_number');
            $table->string('branch_name', 255)->nullable()->after('ifsc_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name',
                'account_number',
                'ifsc_code',
                'branch_name',
            ]);
        });
    }
};

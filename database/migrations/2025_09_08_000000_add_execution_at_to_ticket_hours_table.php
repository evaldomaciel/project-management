<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_hours', function (Blueprint $table) {
            $table->dateTime('execution_at')->nullable()->after('value');
        });

        // Backfill existing rows: set execution_at = created_at
        DB::table('ticket_hours')
            ->whereNull('execution_at')
            ->update(['execution_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('ticket_hours', function (Blueprint $table) {
            $table->dropColumn('execution_at');
        });
    }
};



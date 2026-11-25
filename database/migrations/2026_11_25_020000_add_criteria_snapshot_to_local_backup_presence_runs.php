<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_backup_presence_runs', function (Blueprint $table) {
            // Nullable for pre-existing schema-only rows. The claim service
            // refuses such rows rather than retroactively inventing criteria.
            $table->json('criteria_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('local_backup_presence_runs', function (Blueprint $table) {
            $table->dropColumn('criteria_snapshot');
        });
    }
};

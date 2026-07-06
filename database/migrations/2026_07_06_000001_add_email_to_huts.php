<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('huts', function (Blueprint $table) {
            // Book-direct (OSM) huts often have only a phone/email, no website.
            $table->string('email')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('huts', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};

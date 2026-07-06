<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('huts', function (Blueprint $table) {
            // Whether this hut has an online booking system with queryable
            // availability. False = book-direct (phone/email) only. Distinguishes
            // a booked-out online hut (true, no free beds) from a phone-only hut.
            // Defaults true so existing Alpenverein/huetten-holiday huts are correct.
            $table->boolean('bookable_online')->default(true)->index()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('huts', function (Blueprint $table) {
            $table->dropColumn('bookable_online');
        });
    }
};

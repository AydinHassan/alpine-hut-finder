<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('huts', function (Blueprint $table) {
            // Which booking platform a hut comes from — 'hrs' (Alpenverein /
            // hut-reservation.org) or 'huetten-holiday' (ÖTK, private, opted-out
            // section huts). Their id spaces don't overlap because huetten-holiday
            // rows are stored at id = 1_000_000 + cabinId.
            $table->string('source')->default('hrs')->index()->after('id');
            // Where to send the user to book — differs per source.
            $table->string('booking_url')->nullable()->after('website');
        });

        DB::table('huts')->update(['source' => 'hrs']);
    }

    public function down(): void
    {
        Schema::table('huts', function (Blueprint $table) {
            $table->dropColumn(['source', 'booking_url']);
        });
    }
};

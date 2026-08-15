<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Location maps are worldbuilding-only; live-play tokens now live on battle rooms (room_tokens),
// so the map_tokens table is obsolete. Dropping it loses no live data (it was never populated in
// production). Irreversible — no down().
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('map_tokens');
    }
};

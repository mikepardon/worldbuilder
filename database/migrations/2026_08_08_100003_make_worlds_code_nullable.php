<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The join `code` moved from the world to the campaign layer; worlds no longer generate one, so the
 * column becomes nullable. Irreversible (NULLs may be inserted), so there is no down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table) {
            $table->string('code')->nullable()->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // owner / GM
            $table->string('code', 32)->unique(); // public /c/{code}
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('setting')->nullable();
            $table->string('visibility')->default('private'); // private | hidden | public
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};

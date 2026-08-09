<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A unique index on "name" alone is case-sensitive on SQLite and PostgreSQL, so
     * "Acme Corp" and "acme corp" would both insert. "name_key" holds the canonical
     * form (see Client::keyFor) and carries the uniqueness guarantee on every driver,
     * while "name" stays as the user typed it for display.
     */
    public function up(): void
    {
        Schema::create('clients', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};

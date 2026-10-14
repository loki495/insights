<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The encrypted cast stores base64(JSON{iv, value, mac, tag}), about 290 characters for a
    // real Plaid access token, which does not fit varchar(255) on a strict MySQL connection.
    public function up(): void
    {
        Schema::table('linked_accounts', function (Blueprint $table): void {
            $table->text('access_token')->change();
        });
    }

    public function down(): void
    {
        Schema::table('linked_accounts', function (Blueprint $table): void {
            $table->string('access_token')->change();
        });
    }
};

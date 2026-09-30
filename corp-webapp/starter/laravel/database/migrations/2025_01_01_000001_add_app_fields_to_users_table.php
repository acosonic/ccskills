<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // AD (sAMAccountName) or local login name; most AD accounts have no e-mail.
            $table->string('username', 100)->nullable()->unique()->after('name');
            $table->boolean('ldap_user')->default(false)->after('password');
            $table->enum('role', ['admin', 'supervisor', 'worker'])->default('worker')->after('ldap_user');
            $table->string('language_preference', 5)->default('sr')->after('role');
            $table->boolean('is_active')->default(true)->after('language_preference');
            $table->string('email')->nullable()->change();
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropIndex(['role']);
            $table->dropColumn(['username', 'ldap_user', 'role', 'language_preference', 'is_active']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 120);
            $t->timestamps();
        });
        Schema::create('ai_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $t->string('role', 16);                     // user | assistant (samo tekst — istorija za sledeće pitanje)
            $t->longText('content');
            $t->json('meta')->nullable();               // alati, tokeni
            $t->timestamps();
        });
        Schema::create('ai_proposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $t->unsignedBigInteger('message_id')->nullable();   // poruka asistenta ispod koje stoji kartica
            $t->string('tool');                                 // klasa ProposalTool-a
            $t->string('title');
            $t->json('lines');                                  // „polje: staro → novo"
            $t->json('data');
            $t->string('status', 16)->default('proposed');      // proposed | running | done | cancelled | failed
            $t->text('result')->nullable();
            $t->string('link')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposals');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};

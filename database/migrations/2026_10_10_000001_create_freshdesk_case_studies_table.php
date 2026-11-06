<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freshdesk_case_studies', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('installation_id')->constrained('connector_installations')->cascadeOnDelete();
            $table->string('ticket_id', 100);
            $table->string('source_fingerprint', 64);
            $table->boolean('private_notes_included');
            $table->string('status')->default('queued');
            $table->json('snapshot');
            $table->json('checkpoint');
            $table->json('result')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'installation_id', 'ticket_id'], 'freshdesk_case_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freshdesk_case_studies');
    }
};

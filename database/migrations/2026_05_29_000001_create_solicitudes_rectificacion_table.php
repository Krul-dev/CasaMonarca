<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('solicitudes_rectificacion', function (Blueprint $table) {
            $table->id();

            // Original document — nullOnDelete so the record survives after doc is deleted on approval
            $table->foreignId('documento_id')->nullable()->constrained('documentos')->nullOnDelete();
            $table->foreignId('solicitante_id')->constrained('users')->cascadeOnDelete();

            // Preserved at creation time for audit trail even after doc deletion
            $table->string('doc_nombre', 255)->nullable();
            $table->string('doc_etiqueta', 100)->nullable();

            $table->enum('tipo', ['rectificacion', 'cancelacion']);
            $table->text('descripcion')->nullable();

            // Staff member who took the request
            $table->foreignId('tomado_por')->nullable()->constrained('users')->nullOnDelete();

            // New version uploaded by staff (only for rectificacion)
            $table->foreignId('documento_propuesta_id')->nullable()->constrained('documentos')->nullOnDelete();

            $table->enum('status', ['pendiente', 'en_proceso', 'pendiente_aprobacion', 'aprobada', 'rechazada'])
                  ->default('pendiente');

            // Coordinator approval
            $table->foreignId('aprobada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('firma_b64')->nullable();
            $table->timestamp('aprobada_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_rectificacion');
    }
};

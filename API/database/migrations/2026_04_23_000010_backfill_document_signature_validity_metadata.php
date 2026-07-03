<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $validityDays = max(1, (int) config('documents.signature_validity_days', 365));

        DB::table('document_signatures')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($validityDays): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata)
                        ? json_decode($row->metadata, true) ?? []
                        : [];

                    if (
                        isset($metadata['validity']) &&
                        is_array($metadata['validity']) &&
                        is_string($metadata['validity']['expiresAt'] ?? null)
                    ) {
                        continue;
                    }

                    $signedAt = CarbonImmutable::parse($row->signed_at)->setTimezone('UTC');

                    $metadata['validity'] = [
                        'days' => $validityDays,
                        'expiresAt' => $signedAt->addDays($validityDays)->toIso8601String(),
                        'source' => 'server-policy',
                    ];

                    DB::table('document_signatures')
                        ->where('id', $row->id)
                        ->update([
                            'metadata' => json_encode(
                                $metadata,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ),
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('document_signatures')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata)
                        ? json_decode($row->metadata, true) ?? []
                        : [];

                    if (! isset($metadata['validity']) || ! is_array($metadata['validity'])) {
                        continue;
                    }

                    unset($metadata['validity']);

                    DB::table('document_signatures')
                        ->where('id', $row->id)
                        ->update([
                            'metadata' => json_encode(
                                $metadata,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ),
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');
    }
};

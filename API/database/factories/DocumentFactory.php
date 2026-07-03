<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'status' => 'active',
            'confidentiality' => 'confidential',
            'owner_user_id' => User::factory(),
            'uploaded_by_user_id' => User::factory(),
            'current_revision_id' => null,
            'approved_at' => now(),
            'approved_by_user_id' => null,
            'approval_note' => null,
            'signature_order_enforced' => false,
        ];
    }
}

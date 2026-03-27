<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class DefaultMessagesTranslationTest extends TestCase
{
    /**
     * Test ModelNotFound translation for Listing.
     */
    public function test_model_not_found_translation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/listings/999999', ['lang' => 'es']);

        $response->assertStatus(404)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Listado no encontrado.',
                 ]);
        
        $responseEn = $this->getJson('/api/listings/999999', ['lang' => 'en']);
        $responseEn->assertStatus(404)
                   ->assertJson([
                       'message' => 'Listing not found.',
                   ]);
    }

    /**
     * Test Validation translation for Contact.
     */
    public function test_validation_translation(): void
    {
        $response = $this->postJson('/api/contact', [], ['lang' => 'es']);

        $response->assertStatus(422)
                 ->assertJson([
                     'success' => false,
                     'message' => 'El campo nombre es obligatorio.',
                 ]);

        $responseEn = $this->postJson('/api/contact', [], ['lang' => 'en']);
        $responseEn->assertStatus(422)
                   ->assertJson([
                       'message' => 'The name field is required.',
                   ]);
    }
}

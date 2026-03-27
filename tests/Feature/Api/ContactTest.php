<?php

namespace Tests\Feature\Api;

use App\Mail\ContactMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    /**
     * Test that the contact API sends an email.
     */
    public function test_contact_api_sends_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/contact', [
            'name' => 'Anil Ramani',
            'email' => 'anil.triunity@gmail.com',
            'subject' => 'Test from web',
            'message' => 'Test purpose from development team',
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => 'success',
                     'message' => 'Your message has been sent successfully.',
                 ]);

        Mail::assertSent(ContactMail::class, function ($mail) {
            return $mail->hasTo('support@fullmarket.net') &&
                   $mail->data['name'] === 'Anil Ramani' &&
                   $mail->data['subject'] === 'Test from web';
        });
    }

    /**
     * Test validation for contact API.
     */
    public function test_contact_api_validation(): void
    {
        $response = $this->postJson('/api/contact', []);

        $response->assertStatus(422)
                 ->assertJsonStructure([
                     'status',
                     'message',
                     'errors' => [
                         'name',
                         'email',
                         'subject',
                         'message',
                     ],
                 ]);
    }
}

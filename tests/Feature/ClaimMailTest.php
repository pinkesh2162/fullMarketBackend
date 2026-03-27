<?php

namespace Tests\Feature;

use App\Mail\ClaimMail;
use App\Models\Claim;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClaimMailTest extends TestCase
{
    /**
     * Test that ClaimMail uses the correct view and contains expected data.
     */
    public function test_claim_mail_content(): void
    {
        $claim = new Claim([
            'listing_id' => 456,
            'full_name' => 'John Doe',
            'email' => 'john@example.com',
            'phone_code' => '52',
            'phone' => '1234567890',
            'claim_type' => 1, // Claim Ad
            'description' => 'I want to claim this listing.',
        ]);
        $claim->id = 123;

        $mailable = new ClaimMail($claim);
        $mailable->assertHasSubject('New Claim Request - #123');
        $mailable->assertSeeInHtml('John Doe');
        $mailable->assertSeeInHtml('john@example.com');
        $mailable->assertSeeInHtml('Listing #456');
        $mailable->assertSeeInHtml('I want to claim this listing.');
    }
}

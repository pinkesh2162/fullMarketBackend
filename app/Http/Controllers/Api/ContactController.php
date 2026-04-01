<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Models\Contact;

class ContactController extends Controller
{
    /**
     * Send a contact email.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function send(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        try {
            // Store contact message in the database
            Contact::create($data);

            // Send email to the support address
            Mail::to(config('app.admin_email'))->queue(new ContactMail($data));

            return $this->actionSuccess('request_submitted');
        } catch (\Exception $e) {
            return $this->serverError('contact_send_failed', ['error' => $e->getMessage()]);
        }
    }
}

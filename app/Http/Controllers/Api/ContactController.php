<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /**
     * Send a contact email.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->validationFailed('Validation failed', $validator->errors());
        }

        $data = $request->only('name', 'email', 'subject', 'message');

        try {
            // Send email to the support address
            Mail::to(config('app.admin_email'))->send(new ContactMail($data));

            return $this->actionSuccess('Your message has been sent successfully.');
        } catch (\Exception $e) {
            return $this->serverError('Failed to send message. Please try again later.', ['error' => $e->getMessage()]);
        }
    }
}

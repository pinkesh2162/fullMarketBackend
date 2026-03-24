<x-mail::message>
# Password Reset Requested

You recently requested to reset your password. Please use the following One-Time Password (OTP) to complete the process:

<x-mail::panel>
**{{ $otp }}**
</x-mail::panel>

This OTP will expire shortly.

If you did not request a password reset, no further action is required.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

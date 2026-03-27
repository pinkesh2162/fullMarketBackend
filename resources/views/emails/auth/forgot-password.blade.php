<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 12px; overflow: hidden;">
    <!-- Header Banner -->
    <div style="background-color: #ffffff; text-align: center;">
        <img src="{{ asset('media/mail/header_logo.png') }}" alt="FullMarket" style="width: 100%; height: auto; display: block;">
    </div>

    <!-- Body Content -->
    <div style="padding: 35px; background-color: #ffffff;">
        <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 20px;">Reset Your Password</h2>

        <p style="margin-bottom: 20px; font-size: 16px;">
            Hello <a href="mailto:{{ $email }}" style="color: #007bff; text-decoration: none;">{{ $email }}</a>,
        </p>

        <p style="margin-bottom: 20px; font-size: 16px; line-height: 1.5; color: #555;">
            We received a request to reset the password for your FullMarket account.<br>
            Click the button below to set a new password.
        </p>

        <p style="font-weight: bold; margin-bottom: 20px; font-size: 16px; color: #333;">Click the button below to continue.</p>

        <div style="margin-bottom: 40px;">
            <a href="{{ $resetLink }}" style="display: inline-block; padding: 14px 30px; background-color: #f05a28; color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 8px; font-size: 16px;">Reset Password</a>
        </div>

        <p style="color: #666; font-size: 14px; margin-bottom: 10px; line-height: 1.5;">
            This link will help you reset your password on our site. If you did not request a password reset, you can safely ignore this email.
        </p>

        <p style="color: #666; font-size: 14px; margin-bottom: 30px;">
            If you didn't request this, ignore this email.
        </p>

        <!-- App Download -->
        <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 15px; color: #333;">Download our app</h3>
        <div style="margin-bottom: 20px;display:flex;gap:20px">
            <a href="https://play.google.com/store/apps/details?id=com.fullmarket&hl=en">
                <img src="{{ asset('media/mail/google-pay.png') }}" alt="Google Play"
                    style="display:block;height:auto;border:0;max-width:160px">
            </a>
            <a href="https://apps.apple.com/us/app/fullmarket/id6502962551">
                <img src="{{ asset('media/mail/apple-store.png') }}" alt="App Store"
                    style="display:block;height:auto;border:0;max-width:160px">
            </a>
        </div>
    </div>
    
    <!-- Footer -->
    <div style="padding: 15px 35px; border-top: 1px solid #eee; background-color: #fafafa;">
        <p style="color: #999; font-size: 12px; margin: 0;">© FullMarket</p>
    </div>
</div>
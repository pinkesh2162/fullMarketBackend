<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px; padding: 20px;">
    <div style="margin-bottom: 30px;">
        <img src="{{asset('assets/images/logo.png')}}" alt="FullMarket" style="height: 30px;">
    </div>

    <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 20px;">Reset Your Password</h2>

    <p style="margin-bottom: 20px; font-size: 16px;">
        Hello <a href="mailto:{{ $email }}" style="color: #0056b3; text-decoration: none;">{{ $email }}</a>,
    </p>

    <p style="margin-bottom: 20px; font-size: 16px; line-height: 1.5; color: #555;">
        We received a request to reset the password for your FullMarket account.<br>
        Click the button below to set a new password.
    </p>

    <p style="font-weight: bold; margin-bottom: 20px; font-size: 16px;">Click the button below to continue.</p>

    <div style="margin-bottom: 30px;">
        <a href="{{ $resetLink }}" style="display: inline-block; padding: 12px 24px; background-color: #f05a28; color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 6px;">Reset Password</a>
    </div>

    <p style="color: #666; font-size: 14px; margin-bottom: 10px; line-height: 1.5;">
        This link will help you reset your password on our site. If you did not request a password reset, you can safely ignore this email.
    </p>

    <p style="color: #666; font-size: 14px; margin-bottom: 30px;">
        If you didn't request this, ignore this email.
    </p>

    <h3 style="font-size: 18px; font-weight: bold; margin-bottom: 15px;">Download our app</h3>

    <div style="margin-bottom: 30px;">
        <a href="#" style="margin-right: 10px; text-decoration: none;">
            <img src="https://dummyimage.com/135x40/000/fff.png&text=Google+Play" alt="Get it on Google Play" style="height: 40px; border-radius: 4px;">
        </a>
        <a href="#" style="text-decoration: none;">
            <img src="https://dummyimage.com/135x40/000/fff.png&text=App+Store" alt="Download on the App Store" style="height: 40px; border-radius: 4px;">
        </a>
    </div>

    <hr style="border: 0; border-top: 1px solid #eee; margin-bottom: 20px;">

    <p style="color: #999; font-size: 12px;">© FullMarket</p>
</div>
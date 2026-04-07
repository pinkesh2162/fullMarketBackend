<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 12px; overflow: hidden;">
    <!-- Header Banner -->
    <div style="background-color: #ffffff; text-align: center;">
        <img src="{{ asset('media/mail/header_logo.png') }}" alt="FullMarket" style="width: 100%; height: auto; display: block;">
    </div>

    <!-- Body Content -->
    <div style="padding: 35px; background-color: #ffffff;">
        <h1 style="font-size: 28px; font-weight: bold; margin-bottom: 25px; color: #333;">Welcome to FullMarket!</h1>

        <p style="margin-bottom: 20px; font-size: 16px; color: #555;">
            Hello {{ $user->first_name }} {{ $user->last_name }},
        </p>

        <p style="margin-bottom: 25px; font-size: 16px; color: #555; line-height: 1.5;">
            Your account has been created successfully. Here are your login details:
        </p>

        <div style="margin-bottom: 30px; background-color: #f9f9f9; padding: 20px; border-radius: 8px;">
            <p style="margin: 0 {{ $password ? '0 10px 0' : '' }}; font-size: 16px;"><strong>Email:</strong> <span style="color: #007bff;">{{ $user->email }}</span></p>
            @if($password)
                <p style="margin: 0; font-size: 16px;"><strong>Password:</strong> {{ $password }}</p>
            @endif
        </div>

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
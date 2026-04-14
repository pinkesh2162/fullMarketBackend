<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User report #{{ $payload['reported_user_id'] ?? '' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f6f8;">
    <tr>
      <td align="center" style="padding: 40px 20px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; margin: 0 auto; background-color:#ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
          <tr>
            <td style="padding: 0; border-radius: 12px 12px 0 0; overflow: hidden;">
              <img src="{{ asset('media/mail/header_logo.png') }}" alt="{{ $payload['app_name'] }}" width="600" style="display: block; width: 100%; max-width: 600px; height: auto; border: 0;" />
            </td>
          </tr>
          <tr>
            <td style="padding: 32px;">
              <h1 style="margin: 0 0 24px 0; font-size: 22px; font-weight: 600; color: #1a1a1a;">User report</h1>
              <p style="margin: 0 0 20px 0; font-size: 13px; color: #6b6b6b;">
                Environment: <strong>{{ $payload['app_env'] }}</strong> · Submitted (UTC): <strong>{{ $payload['submitted_at_utc'] }}</strong>
              </p>
              <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; font-size: 14px;">
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee; width: 140px;"><strong>Reporter ID</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $payload['reporter_id'] }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Reporter name</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $payload['reporter_name'] }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Reporter email</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><a href="mailto:{{ $payload['reporter_email'] }}" style="color:#2E86DE;">{{ $payload['reporter_email'] }}</a></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Reported user ID</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $payload['reported_user_id'] }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Reported name</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $payload['reported_name'] }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Reported email</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><a href="mailto:{{ $payload['reported_email'] }}" style="color:#2E86DE;">{{ $payload['reported_email'] }}</a></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Unique key</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $payload['reported_unique_key'] }}</td>
                </tr>
                @if(!empty($payload['reported_profile_url']))
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Profile link</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><a href="{{ $payload['reported_profile_url'] }}" style="color:#2E86DE;">Open profile</a></td>
                </tr>
                @endif
                <tr>
                    <td style="padding: 12px 0; vertical-align: top;"><strong>Message</strong></td>
                    <td style="padding: 12px 0; line-height: 1.6; color: #444;">{!! nl2br(e($payload['message'] ?? '')) !!}</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>

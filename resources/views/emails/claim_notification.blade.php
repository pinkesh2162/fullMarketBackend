<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $claim->claim_type ? 'New Claim Request' : 'New Remove Ad Request' }} - FullMarket</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f6f8;">
    <tr>
      <td align="center" style="padding: 40px 20px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; margin: 0 auto; background-color:#ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
          <!-- Header: full-width en.png / es.png image (same as other emails) -->
          <tr>
            <td style="padding: 0; border-radius: 12px 12px 0 0; overflow: hidden;">
              <img src="{{ asset('media/mail/header_logo.png') }}" alt="FullMarket" width="600" style="display: block; width: 100%; max-width: 600px; height: auto; border: 0;" />
            </td>
          </tr>
          <tr>
            <td style="padding: 32px;">
              <h1 style="margin: 0 0 24px 0; font-size: 22px; font-weight: 600; color: #1a1a1a;">
                {{ $claim->claim_type ? 'New Claim Request' : 'New Remove Ad Request' }}
              </h1>
              <p style="margin: 0 0 20px 0; font-size: 14px; color: #6b6b6b;">
                A new request has been submitted for <strong>Listing #{{ $claim->listing_id }}</strong>.
              </p>
              <table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; font-size: 14px;">
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee; width: 120px;"><strong>Full Name</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $claim->full_name }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Email</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><a href="mailto:{{ $claim->email }}" style="color:#2E86DE;">{{ $claim->email }}</a></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Phone</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $claim->phone_code }} {{ $claim->phone }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Listing ID</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">
                        {{ $claim->listing_id }} 
                        <a href="{{ config('app.frontend_url') }}/ads/{{ $claim->listing_id }}" style="color:#2E86DE; text-decoration: none; margin-left: 10px;">(View Ad)</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0; vertical-align: top;"><strong>Message</strong></td>
                    <td style="padding: 12px 0; line-height: 1.6; color: #444;">{!! nl2br(e($claim->description ?: 'No description provided')) !!}</td>
                </tr>
              </table>

              @if($claim->getMedia(App\Models\Claim::CLAIM_IMAGES)->count() > 0)
                <div style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border-radius: 8px; border: 1px solid #eee;">
                    <p style="margin: 0 0 10px 0; font-size: 14px; font-weight: bold; color: #333;">Attached Documents:</p>
                    <ul style="margin: 0; padding: 0; list-style: none;">
                        @foreach($claim->getMedia(App\Models\Claim::CLAIM_IMAGES) as $media)
                            <li style="margin-bottom: 5px;">
                                <a href="{{ $media->getFullUrl() }}" style="color: #2E86DE; font-size: 13px;">View Attachment #{{ $loop->iteration }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
              @endif

              <p style="margin: 24px 0 0 0; font-size: 12px; color: #8a8a8a; border-top: 1px solid #eee; padding-top: 20px;">
                © {{ date('Y') }} FullMarket. All rights reserved.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>

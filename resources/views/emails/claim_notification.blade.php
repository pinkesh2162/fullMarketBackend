<div style="font-family: Arial, sans-serif; color: #333; max-width: 600px; padding: 20px;">
    <h2 style="font-size: 24px; font-weight: bold; margin-bottom: 20px;">
        {{ $claim->claim_type ? 'New Claim Request' : 'New Remove Ad Request' }}
    </h2>

    <p style="margin-bottom: 10px;">A new request has been submitted for <strong>Listing #{{ $claim->listing_id }}</strong>.</p>

    <div style="background-color: #f9f9f9; padding: 15px; border-radius: 8px; border-left: 4px solid #f05a28; margin-bottom: 20px;">
        <p><strong>Full Name:</strong> {{ $claim->full_name }}</p>
        <p><strong>Email:</strong> {{ $claim->email }}</p>
        <p><strong>Phone:</strong> +{{ $claim->phone_code }} {{ $claim->phone }}</p>
        <p><strong>Type:</strong> {{ $claim->claim_type ? 'Claim Ad' : 'Remove Ad' }}</p>
        <p><strong>Description:</strong></p>
        <p style="white-space: pre-wrap;">{{ $claim->description ?: 'No description provided' }}</p>
    </div>

    @if($claim->getMedia(App\Models\Claim::CLAIM_IMAGES)->count() > 0)
        <p><strong>Attached Images:</strong></p>
        <ul style="list-style: none; padding: 0;">
            @foreach($claim->getMedia(App\Models\Claim::CLAIM_IMAGES) as $media)
                <li style="margin-bottom: 10px;">
                    <a href="{{ $media->getFullUrl() }}" style="color: #f05a28; text-decoration: none;">View Attachment #{{ $loop->iteration }}</a>
                </li>
            @endforeach
        </ul>
    @endif

    <p style="color: #666; font-size: 14px; margin-top: 30px;">
        Submit Date: {{ $claim->created_at->format('M d, Y H:i:s') }}
    </p>

    <hr style="border: 0; border-top: 1px solid #eee; margin-top: 20px; margin-bottom: 10px;">
    <p style="color: #999; font-size: 12px;">© FullMarket Notification System</p>
</div>

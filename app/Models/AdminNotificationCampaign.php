<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AdminNotificationCampaign extends Model
{
    public const SOURCE_ADMIN_PANEL = 'admin_panel';

    public const SOURCE_FIREBASE = 'firebase_token';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PARTIALLY_SENT = 'partially_sent';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        'queued',
        'processing',
        'sent',
        'failed',
        'partially_sent',
    ];

    protected $table = 'admin_notification_campaigns';

    protected $fillable = [
        'public_id',
        'campaign_id',
        'title',
        'body',
        'selected_segment',
        'segment_label',
        'country_filter',
        'city_filter',
        'targeted_users',
        'reachable_devices',
        'skipped_no_token',
        'status',
        'sent_count',
        'failed_count',
        'open_rate',
        'error_message',
        'created_by_user_id',
        'created_by_email',
        'dry_run',
        'source',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'targeted_users' => 'integer',
            'reachable_devices' => 'integer',
            'skipped_no_token' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'open_rate' => 'float',
            'dry_run' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function generatePublicId(): string
    {
        return 'NOT-'.strtoupper(Str::random(6));
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $createdBy = null;
        if ($this->created_by_user_id !== null || (is_string($this->created_by_email) && $this->created_by_email !== '')) {
            $createdBy = [
                'id' => $this->created_by_user_id,
                'email' => $this->created_by_email,
            ];
        }

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'selected_segment' => $this->selected_segment,
            'segment_label' => $this->segment_label,
            'country_filter' => $this->country_filter,
            'city_filter' => $this->city_filter,
            'targeted_users' => $this->targeted_users,
            'reachable_devices' => $this->reachable_devices,
            'skipped_no_token' => $this->skipped_no_token,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,
            'open_rate' => $this->open_rate,
            'error_message' => $this->error_message,
            'source' => $this->source,
            'created_by' => $createdBy,
            'created_at' => self::asIso8601String($this->created_at),
            'sent_at' => self::asIso8601String($this->sent_at),
        ];
    }

    /**
     * Works when Eloquent has already cast to Carbon, or when values are still strings from the DB/PDO.
     * PHP's nullsafe operator is not enough: a non-null string is still a string, not a Carbon.
     */
    public static function asIso8601String(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }
        if (is_int($value)) {
            return Carbon::createFromTimestamp($value)->toIso8601String();
        }
        if (is_string($value)) {
            return Carbon::parse($value)->toIso8601String();
        }

        return null;
    }
}

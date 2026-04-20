<?php

namespace App\Support;

use App\Models\FriendRequest;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Store;

/**
 * Localized copy for GET /notifications only. Nothing extra is required in stored `data`
 * beyond the original payloads (ids + types); templates use stored English title + DB lookups.
 */
final class PushNotificationMessages
{
    public const KEY_LISTING_CREATED = 'listing_created';

    public const KEY_FRIEND_REQUEST_RECEIVED = 'friend_request_received';

    public const KEY_FRIEND_REQUEST_ACCEPTED = 'friend_request_accepted';

    public const KEY_FRIEND_REQUEST_REJECTED = 'friend_request_rejected';

    public const KEY_FRIEND_REQUEST_CANCELLED = 'friend_request_cancelled';

    public const KEY_STORE_CREATED = 'store_created';

    public const KEY_STORE_FOLLOWED = 'store_followed';

    public static function normalizeLocale(?string $lang): string
    {
        $l = strtolower(trim((string) ($lang ?? 'en')));

        return in_array($l, ['en', 'es'], true) ? $l : 'en';
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array{title: string, body: string}
     */
    public static function render(string $msgKey, string $locale, array $params): array
    {
        $locale = self::normalizeLocale($locale);
        $templates = self::templates();
        if (! isset($templates[$msgKey][$locale])) {
            return ['title' => $msgKey, 'body' => ''];
        }
        $t = $templates[$msgKey][$locale];

        return [
            'title' => self::interpolate($t['title'], $params),
            'body' => self::interpolate($t['body'], $params),
        ];
    }

    /**
     * Resolve localized title/body for API. Uses optional legacy `data.msg_key` + inline params if present,
     * otherwise infers template from stored English `title` + `listing_id` / `friend_request_id` / `store_id`.
     *
     * @return array{title: string, body: string}
     */
    public static function localizedFromStoredNotification(Notification $notification): array
    {
        $locale = self::normalizeLocale(app()->getLocale());
        $data = $notification->data ?? [];
        $data = is_array($data) ? $data : [];

        if (! empty($data['msg_key']) && is_string($data['msg_key'])) {
            $msgKey = $data['msg_key'];
            $params = self::paramsFromInference($msgKey, $data);
            $explicit = self::paramsFromData($msgKey, $data);
            foreach ($explicit as $k => $v) {
                if ($v !== '') {
                    $params[$k] = $v;
                }
            }

            return self::render($msgKey, $locale, $params);
        }

        $msgKey = self::inferMsgKey((string) $notification->title);
        if ($msgKey === null) {
            $msgKey = self::inferMsgKeyFromData($data);
        }
        if ($msgKey === null) {
            return [
                'title' => (string) $notification->title,
                'body' => (string) $notification->body,
            ];
        }

        $params = self::paramsFromInference($msgKey, $data);

        return self::render($msgKey, $locale, $params);
    }

    /**
     * Match stored English title from DB/FCM (trimmed).
     */
    public static function inferMsgKey(string $title): ?string
    {
        $t = trim($title);

        return match ($t) {
            'Listing Created' => self::KEY_LISTING_CREATED,
            'New Friend Request' => self::KEY_FRIEND_REQUEST_RECEIVED,
            'Friend Request Accepted' => self::KEY_FRIEND_REQUEST_ACCEPTED,
            'Friend Request Rejected' => self::KEY_FRIEND_REQUEST_REJECTED,
            'Friend Request Cancelled' => self::KEY_FRIEND_REQUEST_CANCELLED,
            'Store Created' => self::KEY_STORE_CREATED,
            'Store Followed' => self::KEY_STORE_FOLLOWED,
            default => null,
        };
    }

    /**
     * Prefer stable `data.type` from dispatch payloads when title text does not match templates
     * (legacy rows, client/Firebase wording, or whitespace differences).
     *
     * @param  array<string, mixed>  $data
     */
    public static function inferMsgKeyFromData(array $data): ?string
    {
        $type = isset($data['type']) ? strtolower(trim((string) $data['type'])) : '';

        return match ($type) {
            'friend_request' => self::KEY_FRIEND_REQUEST_RECEIVED,
            'friend_request_accepted' => self::KEY_FRIEND_REQUEST_ACCEPTED,
            'friend_request_rejected' => self::KEY_FRIEND_REQUEST_REJECTED,
            'friend_request_cancelled' => self::KEY_FRIEND_REQUEST_CANCELLED,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data  Minimal stored payload (ids only).
     * @return array<string, string>
     */
    private static function paramsFromInference(string $msgKey, array $data): array
    {
        return match ($msgKey) {
            self::KEY_LISTING_CREATED => self::paramsListingCreated($data),
            self::KEY_FRIEND_REQUEST_RECEIVED,
            self::KEY_FRIEND_REQUEST_ACCEPTED,
            self::KEY_FRIEND_REQUEST_REJECTED,
            self::KEY_FRIEND_REQUEST_CANCELLED => self::paramsFriendRequest($msgKey, $data),
            self::KEY_STORE_CREATED,
            self::KEY_STORE_FOLLOWED => self::paramsStore($data),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function paramsListingCreated(array $data): array
    {
        $id = isset($data['listing_id']) ? (int) $data['listing_id'] : 0;
        $listing = $id > 0 ? Listing::query()->find($id) : null;
        $locale = self::normalizeLocale(app()->getLocale());

        return ['listing_title' => $listing ? $listing->titleForLocale($locale) : ''];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function paramsFriendRequest(string $msgKey, array $data): array
    {
        $id = isset($data['friend_request_id']) ? (int) $data['friend_request_id'] : 0;
        $fr = $id > 0 ? FriendRequest::query()->with(['sender', 'receiver'])->find($id) : null;
        if (! $fr) {
            return match ($msgKey) {
                self::KEY_FRIEND_REQUEST_ACCEPTED,
                self::KEY_FRIEND_REQUEST_REJECTED => ['peer_name' => ''],
                default => ['sender_name' => ''],
            };
        }

        return match ($msgKey) {
            self::KEY_FRIEND_REQUEST_RECEIVED => [
                'sender_name' => self::socialNameOf($fr->sender),
            ],
            self::KEY_FRIEND_REQUEST_ACCEPTED,
            self::KEY_FRIEND_REQUEST_REJECTED => [
                'peer_name' => self::socialNameOf($fr->receiver),
            ],
            self::KEY_FRIEND_REQUEST_CANCELLED => [
                'sender_name' => self::socialNameOf($fr->sender),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function paramsStore(array $data): array
    {
        $id = isset($data['store_id']) ? (int) $data['store_id'] : 0;
        $store = $id > 0 ? Store::query()->find($id) : null;

        return ['store_name' => $store ? (string) $store->name : ''];
    }

    /**
     * @param  mixed  $entity
     */
    private static function socialNameOf($entity): string
    {
        if (! is_object($entity)) {
            return '';
        }

        return trim((string) ($entity->social_name ?? ''));
    }

    /**
     * Legacy: inline params saved with older notifications.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function paramsFromData(string $msgKey, array $data): array
    {
        $s = static fn ($v): string => is_scalar($v) || $v === null ? (string) $v : '';

        return match ($msgKey) {
            self::KEY_LISTING_CREATED => [
                'listing_title' => $s($data['listing_title'] ?? ''),
            ],
            self::KEY_FRIEND_REQUEST_RECEIVED => [
                'sender_name' => $s($data['sender_name'] ?? ''),
            ],
            self::KEY_FRIEND_REQUEST_ACCEPTED, self::KEY_FRIEND_REQUEST_REJECTED => [
                'peer_name' => $s($data['peer_name'] ?? ''),
            ],
            self::KEY_FRIEND_REQUEST_CANCELLED => [
                'sender_name' => $s($data['sender_name'] ?? ''),
            ],
            self::KEY_STORE_CREATED => [
                'store_name' => $s($data['store_name'] ?? ''),
            ],
            self::KEY_STORE_FOLLOWED => [
                'store_name' => $s($data['store_name'] ?? ''),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $params
     */
    private static function interpolate(string $template, array $params): string
    {
        $out = $template;
        foreach ($params as $key => $value) {
            $out = str_replace('{'.$key.'}', $value, $out);
        }

        return $out;
    }

    /**
     * @return array<string, array<string, array{title: string, body: string}>>
     */
    private static function templates(): array
    {
        return [
            self::KEY_LISTING_CREATED => [
                'en' => [
                    'title' => 'Listing Created',
                    'body' => 'Your listing «{listing_title}» has been created successfully.',
                ],
                'es' => [
                    'title' => 'Anuncio creado',
                    'body' => 'Tu anuncio «{listing_title}» se ha creado correctamente.',
                ],
            ],
            self::KEY_FRIEND_REQUEST_RECEIVED => [
                'en' => [
                    'title' => 'New Friend Request',
                    'body' => '{sender_name} sent you a friend request.',
                ],
                'es' => [
                    'title' => 'Nueva solicitud de amistad',
                    'body' => '{sender_name} te envió una solicitud de amistad.',
                ],
            ],
            self::KEY_FRIEND_REQUEST_ACCEPTED => [
                'en' => [
                    'title' => 'Friend Request Accepted',
                    'body' => '{peer_name} accepted your friend request.',
                ],
                'es' => [
                    'title' => 'Solicitud aceptada',
                    'body' => '{peer_name} aceptó tu solicitud de amistad.',
                ],
            ],
            self::KEY_FRIEND_REQUEST_REJECTED => [
                'en' => [
                    'title' => 'Friend Request Rejected',
                    'body' => '{peer_name} rejected your friend request.',
                ],
                'es' => [
                    'title' => 'Solicitud rechazada',
                    'body' => '{peer_name} rechazó tu solicitud de amistad.',
                ],
            ],
            self::KEY_FRIEND_REQUEST_CANCELLED => [
                'en' => [
                    'title' => 'Friend Request Cancelled',
                    'body' => '{sender_name} cancelled the friend request.',
                ],
                'es' => [
                    'title' => 'Solicitud cancelada',
                    'body' => '{sender_name} canceló la solicitud de amistad.',
                ],
            ],
            self::KEY_STORE_CREATED => [
                'en' => [
                    'title' => 'Store Created',
                    'body' => 'Your store «{store_name}» has been created successfully.',
                ],
                'es' => [
                    'title' => 'Tienda creada',
                    'body' => 'Tu tienda «{store_name}» se ha creado correctamente.',
                ],
            ],
            self::KEY_STORE_FOLLOWED => [
                'en' => [
                    'title' => 'Store Followed',
                    'body' => 'You have followed the store «{store_name}».',
                ],
                'es' => [
                    'title' => 'Tienda seguida',
                    'body' => 'Sigues la tienda «{store_name}».',
                ],
            ],
        ];
    }
}

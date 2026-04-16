<?php

namespace App\Http\Middleware;

use App\Services\Firebase\FirebaseAdminAuthorizer;
use App\Services\Firebase\FirebaseIdTokenVerifier;
use Closure;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnsureFirebaseAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $idToken = $this->resolveIdToken($request);
        if ($idToken === null) {
            return response()->json([
                'ok' => false,
                'code' => 'unauthorized',
                'message' => 'Missing or invalid Authorization bearer token.',
                'hint' => 'Use Authorization: Bearer <id_token>, or set ADMIN_PUSH_ACCEPT_FIREBASE_TOKEN_BODY=true and send "firebase_id_token" in the JSON body (local only).',
            ], 401);
        }

        if (substr_count($idToken, '.') !== 2) {
            $payload = [
                'ok' => false,
                'code' => 'malformed_token',
                'message' => 'The value is not a JWT (Firebase ID tokens have exactly three segments separated by two dots).',
                'hint' => $this->malformedTokenHint($idToken, $request),
            ];
            if (config('app.debug')) {
                $payload['debug'] = [
                    'token_length' => strlen($idToken),
                    'dot_count' => substr_count($idToken, '.'),
                    'looks_like_jwt_header' => str_starts_with($idToken, 'eyJ'),
                    'looks_like_postman_placeholder' => $this->looksLikePostmanPlaceholder($idToken),
                    'looks_like_readme_placeholder' => $this->looksLikeReadmePlaceholder($idToken),
                    'token_preview' => strlen($idToken) <= 40 ? $idToken : substr($idToken, 0, 20).'…'.substr($idToken, -8),
                ];
            }

            return response()->json($payload, 401);
        }

        try {
            $claims = app(FirebaseIdTokenVerifier::class)->verify($idToken);
        } catch (ExpiredException $e) {
            return response()->json([
                'ok' => false,
                'code' => 'token_expired',
                'message' => 'Firebase ID token has expired.',
            ], 401);
        } catch (\Throwable $e) {
            $payload = [
                'ok' => false,
                'code' => 'unauthorized',
                'message' => 'Invalid Firebase ID token.',
                'hint' => 'Send a Firebase Auth ID token (from getIdToken() after user sign-in), not the service account JSON or a Google OAuth access token.',
            ];
            if (config('app.debug')) {
                $payload['debug'] = $e->getMessage();
            }

            return response()->json($payload, 401);
        }

        try {
            app(FirebaseAdminAuthorizer::class)->assertIsAdmin($claims);
        } catch (InvalidArgumentException) {
            return response()->json([
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'Admin privileges required.',
            ], 403);
        }

        $request->attributes->set('firebase_claims', $claims);

        return $next($request);
    }

    protected function resolveIdToken(Request $request): ?string
    {
        $candidates = [];

        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $candidates[] = $this->normalizeTokenString($bearer);
        }
        $candidates[] = $this->normalizeTokenString((string) $request->header('Authorization', ''));

        if (config('admin_push.accept_firebase_id_token_in_body')) {
            $raw = $request->input('firebase_id_token');
            if (is_string($raw)) {
                $candidates[] = $this->normalizeTokenString($raw);
            }
        }

        foreach ($candidates as $c) {
            if ($c !== null && substr_count($c, '.') === 2) {
                return $c;
            }
        }

        foreach ($candidates as $c) {
            if ($c !== null) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Strips wrapping quotes and duplicate "Bearer " prefixes; trims whitespace.
     */
    protected function normalizeTokenString(string $value): ?string
    {
        $t = trim($value);
        if ($t === '') {
            return null;
        }
        while (preg_match('/^Bearer\s+/i', $t)) {
            $t = trim(preg_replace('/^Bearer\s+/i', '', $t));
        }
        if ((str_starts_with($t, '"') && str_ends_with($t, '"')) || (str_starts_with($t, "'") && str_ends_with($t, "'"))) {
            $t = substr($t, 1, -1);
        }
        $t = trim($t);

        return $t === '' ? null : $t;
    }

    protected function malformedTokenHint(string $idToken, Request $request): string
    {
        $parts = [
            'Use the Firebase ID token from getIdToken() after sign-in (three dot-separated segments, usually 800+ characters).',
            'Do not use the refresh token, Google OAuth access token, or service account JSON.',
        ];

        if ($this->looksLikeReadmePlaceholder($idToken)) {
            $parts[] = 'You pasted a documentation placeholder (e.g. YOUR_FIREBASE_ID_TOKEN). Replace it with the real JWT string from the browser: await firebase.auth().currentUser.getIdToken() — it starts with eyJ and is much longer.';
        } elseif ($this->looksLikePostmanPlaceholder($idToken)) {
            $parts[] = 'This looks like a Postman variable placeholder (e.g. {{firebase_id_token}}) that did not resolve: open Environments, set that variable to the real JWT, or paste the raw eyJ… token in Auth → Bearer Token with no {{ }}.';
        } elseif (strlen($idToken) < 200) {
            $parts[] = 'The string is very short; a real ID token is almost always much longer. Check the Authorization tab: Postman often sends an unresolved {{variable}} as the literal text.';
        }

        if (config('admin_push.accept_firebase_id_token_in_body') && ! $request->filled('firebase_id_token')) {
            $parts[] = 'Your JSON body has no "firebase_id_token" field; only the Authorization header was used. Either paste the full JWT in Auth → Bearer Token, or add "firebase_id_token": "<same JWT>" to this JSON body.';
        }

        return implode(' ', $parts);
    }

    protected function looksLikePostmanPlaceholder(string $idToken): bool
    {
        if (preg_match('/^\{\{\s*[^}]+\s*\}\}$/', $idToken)) {
            return true;
        }

        return str_contains($idToken, '{{') && str_contains($idToken, '}}');
    }

    /**
     * Common tutorial strings users paste into Bearer by mistake.
     */
    protected function looksLikeReadmePlaceholder(string $idToken): bool
    {
        $u = strtoupper(trim($idToken));

        if (preg_match('/^YOUR[\s_-]*FIREBASE[\s_-]*ID[\s_-]*TOKEN$/i', trim($idToken))) {
            return true;
        }

        $snake = preg_replace('/[\s-]+/', '_', $u);

        return in_array($snake, [
            'PASTE_TOKEN_HERE',
            'YOUR_TOKEN_HERE',
            'INSERT_ACCESS_TOKEN',
            'REPLACE_WITH_TOKEN',
            'API_TOKEN_HERE',
        ], true) || preg_match('/^(EXAMPLE|PLACEHOLDER|SAMPLE)_?TOKEN$/', $snake);
    }
}

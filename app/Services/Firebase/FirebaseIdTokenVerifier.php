<?php

namespace App\Services\Firebase;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use stdClass;

class FirebaseIdTokenVerifier
{
    public function __construct(
        protected Client $http,
        protected string $projectId
    ) {}

    /**
     * Verify a Firebase ID token (RS256 against Google JWKS) and return decoded claims.
     *
     * @throws InvalidArgumentException
     */
    public function verify(string $idToken): stdClass
    {
        $idToken = trim($idToken);
        if ($idToken === '') {
            throw new InvalidArgumentException('Missing ID token.');
        }

        $jwks = Cache::remember('firebase_securetoken_jwks', 3600, function () {
            $res = $this->http->get(
                'https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com'
            );

            return json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        });

        $keys = JWK::parseKeySet($jwks);
        $prevLeeway = JWT::$leeway;
        JWT::$leeway = 120;
        try {
            $decoded = JWT::decode($idToken, $keys);
        } finally {
            JWT::$leeway = $prevLeeway;
        }

        $expectedIss = 'https://securetoken.google.com/'.$this->projectId;
        if (($decoded->iss ?? null) !== $expectedIss) {
            throw new InvalidArgumentException('Invalid token issuer.');
        }
        if (($decoded->aud ?? null) !== $this->projectId) {
            throw new InvalidArgumentException('Invalid token audience.');
        }
        if (empty($decoded->sub)) {
            throw new InvalidArgumentException('Invalid token subject.');
        }

        return $decoded;
    }
}

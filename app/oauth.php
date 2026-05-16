<?php
declare(strict_types=1);

// OAuth 2.0 Authorization Code Flow with PKCE (S256 only)

function hb_oauth_client(PDO $pdo, string $clientId): ?array
{
    $stmt = $pdo->prepare('select * from oauth_clients where id = :id');
    $stmt->execute(['id' => $clientId]);
    return $stmt->fetch() ?: null;
}

function hb_oauth_validate_redirect_uri(array $client, string $uri): bool
{
    $allowed = json_decode($client['redirect_uris'], true) ?? [];
    return in_array($uri, $allowed, true);
}

function hb_oauth_pkce_verify(string $verifier, string $challenge): bool
{
    // S256: BASE64URL(SHA256(code_verifier)) == code_challenge
    $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return hash_equals($computed, $challenge);
}

function hb_oauth_issue_auth_code(
    PDO $pdo,
    int $userId,
    string $clientId,
    int $householdId,
    string $redirectUri,
    string $scopes,
    string $challenge
): string {
    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $expiresAt = (new DateTimeImmutable())->modify('+10 minutes')->format('c');

    $stmt = $pdo->prepare(
        'insert into oauth_authorization_codes (code_hash, user_id, client_id, household_id, redirect_uri, scopes, code_challenge, code_challenge_method, expires_at)
         values (:hash, :uid, :cid, :hid, :uri, :scopes, :challenge, :method, :expires)'
    );
    $stmt->execute([
        'hash' => $hash,
        'uid' => $userId,
        'cid' => $clientId,
        'hid' => $householdId,
        'uri' => $redirectUri,
        'scopes' => $scopes,
        'challenge' => $challenge,
        'method' => 'S256',
        'expires' => $expiresAt,
    ]);

    return $plain;
}

function hb_oauth_exchange_code(
    PDO $pdo,
    string $plainCode,
    string $clientId,
    string $redirectUri,
    string $plainVerifier
): array {
    $codeHash = hash('sha256', $plainCode);

    $stmt = $pdo->prepare(
        'select * from oauth_authorization_codes where code_hash = :hash and used = false'
    );
    $stmt->execute(['hash' => $codeHash]);
    $code = $stmt->fetch();

    if (!$code) {
        throw new Exception('Invalid or already used authorization code', 400);
    }

    if (new DateTimeImmutable() > new DateTimeImmutable($code['expires_at'])) {
        throw new Exception('Authorization code expired', 400);
    }

    if ($code['client_id'] !== $clientId) {
        throw new Exception('Client ID mismatch', 400);
    }

    if ($code['redirect_uri'] !== $redirectUri) {
        throw new Exception('Redirect URI mismatch', 400);
    }

    if ($code['code_challenge_method'] !== 'S256') {
        throw new Exception('Invalid code challenge method', 400);
    }

    if (!hb_oauth_pkce_verify($plainVerifier, $code['code_challenge'])) {
        throw new Exception('PKCE verification failed', 400);
    }

    // Mark as used
    $upd = $pdo->prepare('update oauth_authorization_codes set used = true where code_hash = :hash');
    $upd->execute(['hash' => $codeHash]);

    return [
        'user_id' => (int)$code['user_id'],
        'household_id' => (int)$code['household_id'],
        'scopes' => $code['scopes'],
    ];
}

function hb_oauth_issue_access_token(
    PDO $pdo,
    int $userId,
    string $clientId,
    int $householdId,
    string $scopes,
    bool $manageTransaction = true
): array {
    // Access token: valid 1 hour
    $accessPlain = bin2hex(random_bytes(32));
    $accessHash = hash('sha256', $accessPlain);
    $accessExpires = (new DateTimeImmutable())->modify('+1 hour')->format('c');

    // Refresh token: valid 30 days
    $refreshPlain = bin2hex(random_bytes(32));
    $refreshHash = hash('sha256', $refreshPlain);
    $refreshExpires = (new DateTimeImmutable())->modify('+30 days')->format('c');

    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Insert access token
        $stmt = $pdo->prepare(
            'insert into oauth_access_tokens (id, user_id, client_id, household_id, scopes, expires_at)
             values (:id, :uid, :cid, :hid, :scopes, :expires)'
        );
        $stmt->execute([
            'id' => $accessHash,
            'uid' => $userId,
            'cid' => $clientId,
            'hid' => $householdId,
            'scopes' => $scopes,
            'expires' => $accessExpires,
        ]);

        // Insert refresh token
        $stmt = $pdo->prepare(
            'insert into oauth_refresh_tokens (id, access_token_id, user_id, client_id, household_id, expires_at)
             values (:id, :aid, :uid, :cid, :hid, :expires)'
        );
        $stmt->execute([
            'id' => $refreshHash,
            'aid' => $accessHash,
            'uid' => $userId,
            'cid' => $clientId,
            'hid' => $householdId,
            'expires' => $refreshExpires,
        ]);

        if ($manageTransaction) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($manageTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'access_token' => $accessPlain,
        'refresh_token' => $refreshPlain,
        'expires_in' => 3600,
    ];
}

function hb_oauth_validate_access_token(PDO $pdo, string $plainToken): ?array
{
    $tokenHash = hash('sha256', $plainToken);

    $stmt = $pdo->prepare(
        'select t.id, t.user_id, u.username, t.household_id, t.scopes, t.expires_at
         from oauth_access_tokens t
         join users u on u.id = t.user_id
         where t.id = :hash and t.revoked = false'
    );
    $stmt->execute(['hash' => $tokenHash]);
    $token = $stmt->fetch();

    if (!$token) {
        return null;
    }

    if (new DateTimeImmutable() > new DateTimeImmutable($token['expires_at'])) {
        return null;
    }

    return [
        'token_id' => $token['id'],
        'user_id' => (int)$token['user_id'],
        'username' => $token['username'],
        'household_id' => (int)$token['household_id'],
        'scopes' => $token['scopes'],
    ];
}

function hb_oauth_refresh(PDO $pdo, string $plainRefreshToken, string $clientId): array
{
    $refreshHash = hash('sha256', $plainRefreshToken);

    $stmt = $pdo->prepare(
        'select * from oauth_refresh_tokens where id = :hash and client_id = :cid'
    );
    $stmt->execute(['hash' => $refreshHash, 'cid' => $clientId]);
    $refreshToken = $stmt->fetch();

    if (!$refreshToken) {
        throw new Exception('Invalid refresh token', 400);
    }

    if ($refreshToken['revoked']) {
        // Reuse detection: revoke all tokens for this user+client
        $revokeStmt = $pdo->prepare(
            'update oauth_access_tokens set revoked = true
             where user_id = :uid and client_id = :cid'
        );
        $revokeStmt->execute(['uid' => $refreshToken['user_id'], 'cid' => $clientId]);

        $revokeStmt = $pdo->prepare(
            'update oauth_refresh_tokens set revoked = true, reuse_detected = true
             where user_id = :uid and client_id = :cid'
        );
        $revokeStmt->execute(['uid' => $refreshToken['user_id'], 'cid' => $clientId]);

        throw new Exception('Refresh token reuse detected – session revoked', 400);
    }

    if (new DateTimeImmutable() > new DateTimeImmutable($refreshToken['expires_at'])) {
        throw new Exception('Refresh token expired', 400);
    }

    // Get original scopes from access token
    $accessStmt = $pdo->prepare('select scopes from oauth_access_tokens where id = :id');
    $accessStmt->execute(['id' => $refreshToken['access_token_id']]);
    $accessToken = $accessStmt->fetch();
    $scopes = $accessToken['scopes'] ?? '';

    $pdo->beginTransaction();

    try {
        // Revoke old refresh token
        $upd = $pdo->prepare('update oauth_refresh_tokens set revoked = true where id = :hash');
        $upd->execute(['hash' => $refreshHash]);

        // Issue new tokens
        $result = hb_oauth_issue_access_token(
            $pdo,
            (int)$refreshToken['user_id'],
            $clientId,
            (int)$refreshToken['household_id'],
            $scopes,
            false
        );

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}

function hb_oauth_revoke_token(PDO $pdo, string $plainToken, string $clientId): void
{
    $tokenHash = hash('sha256', $plainToken);

    // Try access token first
    $upd = $pdo->prepare('update oauth_access_tokens set revoked = true where id = :hash and client_id = :cid');
    $result = $upd->execute(['hash' => $tokenHash, 'cid' => $clientId]);

    if ($upd->rowCount() > 0) {
        return;
    }

    // Try refresh token
    $upd = $pdo->prepare('update oauth_refresh_tokens set revoked = true where id = :hash and client_id = :cid');
    $upd->execute(['hash' => $tokenHash, 'cid' => $clientId]);

    // Per RFC 7009: always return 200 even if token not found
}

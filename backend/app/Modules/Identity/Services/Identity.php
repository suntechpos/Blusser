<?php

namespace App\Modules\Identity\Services;

use App\Modules\Access\Services\Governance;
use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

final class Identity
{
    public function __construct(private Governance $db) {}

    public function authenticate(string $login, #[\SensitiveParameter] string $password, string $trustedIp): User
    {
        $login = Governance::login($login);
        $key = $this->reserve('login:'.$login, $trustedIp);
        $record = $this->record($login);
        $valid = $record && $record['active'] && (! $record['must_change_password'] || CarbonImmutable::parse($record['temporary_password_expires_at'])->isFuture());
        // The fallback hash is not an account credential. Same algorithm/cost as real verification.
        $hash = $record['password_hash'] ?? self::DUMMY_HASH;
        $correct = mb_strlen($password) <= 128 && Hash::check($password, $hash);
        $this->db->call('rate_finish', [$key, $valid && $correct]);
        if (! $valid || ! $correct) {
            $this->db->call('auth_failure', [$key]);
            throw new GovernanceDenied('credentials');
        }
        unset($record['password_hash']);

        return (new User)->forceFill($record);
    }

    public function verify(User $actor, #[\SensitiveParameter] string $password, bool $allowTemporary = false): void
    {
        $record = $this->record((string) $actor->login);
        if (! $record || $record['id'] !== $actor->getKey() || ! $record['active'] || (int) $record['credential_version'] !== $actor->credential_version || (! $allowTemporary && $record['must_change_password']) || ($record['must_change_password'] && CarbonImmutable::parse($record['temporary_password_expires_at'])->isPast()) || ! Hash::check($password, $record['password_hash'])) {
            throw new GovernanceDenied('credentials');
        }
    }

    public function changePassword(User $actor, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $replacement): User
    {
        $this->reserve('change:'.$actor->getKey(), 'internal:'.$actor->getKey());
        Governance::password($replacement);
        $this->verify($actor, $current, true);
        if (hash_equals($current, $replacement)) {
            throw new GovernanceDenied('password_unchanged');
        }
        $version = $this->db->call('password_change', [Hash::make($replacement)], $actor);

        return (clone $actor)->forceFill(['credential_version' => (int) $version, 'must_change_password' => false, 'temporary_password_expires_at' => null]);
    }

    public function reserve(string $key, string $trustedIp): string
    {
        $digest = hash_hmac('sha256', $key, (string) config('app.key'));
        $ipDigest = hash_hmac('sha256', 'ip:'.$trustedIp, (string) config('app.key'));
        $ipAllowed = $this->db->call('rate_reserve', [$ipDigest, true]);
        $keyAllowed = $this->db->call('rate_reserve', [$digest, false]);
        if (! $ipAllowed || ! $keyAllowed) {
            $this->db->call('auth_failure', [$digest]);
            throw new GovernanceDenied('rate_limited');
        }

        return $digest;
    }

    private function record(string $login): ?array
    {
        $raw = $this->db->call('identity_lookup', [$login]);

        return $raw === null ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZHZYUXNZRlVLNFNhMWxScg$TN7f2Xfv5muFbKjURnBg/8UdeeQolWDDwyXNTBEc2Ug';
}

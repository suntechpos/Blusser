<?php

namespace App\Modules\Platform\Services;

use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Identity;
use Illuminate\Support\Facades\Hash;

final class ClinicProvisioning
{
    public function __construct(private Governance $db, private Identity $identity) {}

    public function createClinic(User $actor, string $name, string $slug, string $ceoLogin, #[\SensitiveParameter] ?string $temporaryPassword): array
    {
        if ($temporaryPassword !== null) {
            Governance::password($temporaryPassword);
        }

        return json_decode($this->db->call('provision', [Governance::text($name), Governance::text($slug, 100), Governance::login($ceoLogin), $temporaryPassword === null ? null : Hash::make($temporaryPassword)], $actor), true, flags: JSON_THROW_ON_ERROR);
    }

    public function requestRecovery(User $actor, string $tenant, #[\SensitiveParameter] string $currentPassword): string
    {
        $this->identity->reserve('recovery-open:'.$actor->getKey(), 'internal:'.$actor->getKey());
        $this->identity->verify($actor, $currentPassword);

        return $this->db->call('recovery_open', [Governance::uuid($tenant)], $actor, reauthenticated: true);
    }

    public function approveRecovery(User $actor, string $request, string $verificationReference, #[\SensitiveParameter] string $currentPassword): RecoveryToken
    {
        $this->identity->reserve('recovery-approve:'.$actor->getKey(), 'internal:'.$actor->getKey());
        $this->identity->verify($actor, $currentPassword);
        $token = bin2hex(random_bytes(32));
        $this->db->call('recovery_approve', [Governance::uuid($request), Governance::text($verificationReference, 100), hash('sha256', $token)], $actor, reauthenticated: true);

        return new RecoveryToken($request, $token);
    }

    public function recover(string $request, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $newPassword, string $trustedIp): void
    {
        $this->identity->reserve('recover:'.$request, $trustedIp);
        Governance::password($newPassword);
        $this->db->call('recovery_consume', [Governance::uuid($request), hash('sha256', $token), Hash::make($newPassword)]);
    }
}

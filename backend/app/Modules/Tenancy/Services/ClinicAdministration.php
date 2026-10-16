<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Identity;
use Illuminate\Support\Facades\Hash;

/** Internal use cases. Actor must be a server-authenticated identity, never body input. */
final class ClinicAdministration
{
    public function __construct(private Governance $db, private Identity $identity) {}

    public function saveRole(User $actor, string $tenant, ?string $role, string $code, string $name, array $grants): string
    {
        return $this->db->call('role_save', [$role === null ? null : Governance::uuid($role), Governance::text($code, 64), Governance::text($name), json_encode(array_values($grants), JSON_THROW_ON_ERROR)], $actor, Governance::uuid($tenant));
    }

    public function deleteRole(User $actor, string $tenant, string $role): void
    {
        $this->db->call('role_delete', [Governance::uuid($role)], $actor, Governance::uuid($tenant));
    }

    public function createBranch(User $actor, string $tenant, string $name, string $code): string
    {
        return $this->db->call('branch_create', [Governance::text($name), Governance::text($code, 64)], $actor, Governance::uuid($tenant));
    }

    public function createEmployee(User $actor, string $tenant, string $login, string $name, #[\SensitiveParameter] string $temporaryPassword, string $role, array $branches = []): string
    {
        Governance::password($temporaryPassword);

        return $this->db->call('employee_create', [Governance::login($login), Governance::text($name), Hash::make($temporaryPassword), Governance::uuid($role), Governance::uuids($branches)], $actor, Governance::uuid($tenant));
    }

    public function assignEmployee(User $actor, string $tenant, string $member, ?string $role = null, ?array $branches = null, ?bool $active = null): void
    {
        $this->db->call('employee_assign', [Governance::uuid($member), $role === null ? null : Governance::uuid($role), Governance::uuids($branches), $active], $actor, Governance::uuid($tenant));
    }

    public function resetEmployee(User $actor, string $tenant, string $member, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $temporaryPassword): void
    {
        $this->identity->reserve('reset:'.$actor->getKey(), 'internal:'.$actor->getKey());
        $this->identity->verify($actor, $currentPassword);
        Governance::password($temporaryPassword);
        $this->db->call('employee_reset', [Governance::uuid($member), Hash::make($temporaryPassword)], $actor, Governance::uuid($tenant), true);
    }

    public function loginExists(User $actor, string $tenant, string $login): bool
    {
        $this->identity->reserve('lookup:'.$actor->getKey(), 'internal:'.$actor->getKey());

        return $this->db->call('login_exists', [Governance::login($login)], $actor, Governance::uuid($tenant));
    }

    public function requestLink(User $actor, string $tenant, string $login, string $role, array $branches = []): string
    {
        $this->identity->reserve('link:'.$actor->getKey(), 'internal:'.$actor->getKey());

        return $this->db->call('link_request', [Governance::login($login), Governance::uuid($role), Governance::uuids($branches)], $actor, Governance::uuid($tenant));
    }

    public function pendingLinks(User $actor): array
    {
        return json_decode($this->db->call('pending_links', [], $actor), true, flags: JSON_THROW_ON_ERROR);
    }

    public function acceptLink(User $actor, string $request, #[\SensitiveParameter] string $currentPassword): string
    {
        $this->identity->reserve('accept:'.$actor->getKey(), 'internal:'.$actor->getKey());
        $this->identity->verify($actor, $currentPassword);

        return $this->db->call('link_accept', [Governance::uuid($request)], $actor, reauthenticated: true);
    }
}

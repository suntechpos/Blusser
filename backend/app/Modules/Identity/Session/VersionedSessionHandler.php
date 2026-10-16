<?php

namespace App\Modules\Identity\Session;

use App\Modules\Access\Services\Governance;
use Illuminate\Session\DatabaseSessionHandler;

final class VersionedSessionHandler extends DatabaseSessionHandler
{
    public function read(#[\SensitiveParameter] $sessionId): string|false
    {
        $row = (object) $this->getQuery()->find($sessionId);
        if ($this->expired($row)) {
            $this->destroy($sessionId);

            return '';
        }

        return parent::read($sessionId);
    }

    public function write(#[\SensitiveParameter] $sessionId, #[\SensitiveParameter] $data): bool
    {
        $identity = $this->container->make('request')->session()->get('identity');
        app(Governance::class)->call('session_write', [$sessionId, $identity['id'] ?? null, $identity['version'] ?? null, $identity['stage'] ?? 'anonymous', base64_encode($data), time()]);

        // A revoked session is deliberately not recreated. The next request is anonymous.
        return true;
    }
}

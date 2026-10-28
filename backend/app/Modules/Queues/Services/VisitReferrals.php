<?php
namespace App\Modules\Queues\Services;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
final class VisitReferrals {
 public function read(User $actor,string $tenant,string $visit,string $function): array {
  if(!in_array($function,['doctor_referral_list','doctor_referral_options'],true)) throw new \InvalidArgumentException('Invalid function.');
  return app(TenantContext::class)->run($actor,$tenant,function() use($visit,$function) {
   return json_decode(DB::selectOne("SELECT governance.{$function}(?::uuid) AS value",[$visit])->value,true,flags:JSON_THROW_ON_ERROR);
  },consistentSnapshot:true);
 }
 public function create(User $actor,string $tenant,string $visit,array $data): array {
  return json_decode(app(Governance::class)->call('doctor_referral_create',
   [$visit,json_encode($data,JSON_THROW_ON_ERROR)],$actor,$tenant),true,flags:JSON_THROW_ON_ERROR);
 }
}

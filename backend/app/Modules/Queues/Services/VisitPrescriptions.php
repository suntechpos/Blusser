<?php
namespace App\Modules\Queues\Services;
use App\Modules\Access\Services\Governance;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
final class VisitPrescriptions {
 public function list(User $actor,string $tenant,string $visit): array {
  return app(TenantContext::class)->run($actor,$tenant,function() use($visit) {
   return json_decode(DB::selectOne('SELECT governance.doctor_prescription_list(?::uuid) AS value',[$visit])->value,true,flags:JSON_THROW_ON_ERROR);
  },consistentSnapshot:true);
 }
 public function save(User $actor,string $tenant,string $visit,?string $id,array $data): array {
  $version=$data['expected_version'];unset($data['expected_version']);
  return json_decode(app(Governance::class)->call('doctor_prescription_save',
   [$visit,$id,$version,json_encode($data,JSON_THROW_ON_ERROR)],$actor,$tenant),true,flags:JSON_THROW_ON_ERROR);
 }
}

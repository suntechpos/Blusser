<?php
namespace App\Modules\Queues\Http;
use App\Modules\Queues\Services\VisitReferrals;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
final class VisitReferralController {
 public function __construct(private VisitReferrals $referrals) {}
 public function index(Request $request) {
  if ($request->query()) throw ValidationException::withMessages(['request'=>'Unknown fields.']);
  return response()->json($this->referrals->read($request->user(),$this->tenant($request),$request->route('visit'),'doctor_referral_list'))->header('Cache-Control','no-store');
 }
 public function options(Request $request) {
  if ($request->query()) throw ValidationException::withMessages(['request'=>'Unknown fields.']);
  return response()->json($this->referrals->read($request->user(),$this->tenant($request),$request->route('visit'),'doctor_referral_options'))->header('Cache-Control','no-store');
 }
 public function store(Request $request) {
  $rules=['location'=>'required|in:internal,external','referral_type'=>'required|in:laboratory,diagnostic,consultation',
   'service_id'=>'required|uuid','target_branch_id'=>'present|nullable|uuid',
   'external_clinic'=>'present|nullable|string|max:255','note'=>'present|nullable|string|max:4000'];
  if(array_diff(array_keys($request->all()),array_keys($rules))) throw ValidationException::withMessages(['request'=>'Unknown fields.']);
  $data=validator($request->all(),$rules)->validate();
  $data['external_clinic']=$data['external_clinic']??'';
  $data['note']=$data['note']??'';
  return response()->json($this->referrals->create($request->user(),$this->tenant($request),$request->route('visit'),$data),201)->header('Cache-Control','no-store');
 }
 private function tenant(Request $request): string {
  $tenant=$request->session()->get('active_tenant_id');
  abort_unless(is_string($tenant),409,'Select an active clinic.');
  return $tenant;
 }
}

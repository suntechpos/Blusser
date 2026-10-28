<?php
namespace App\Modules\Queues\Http;
use App\Modules\Queues\Services\VisitPrescriptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
final class VisitPrescriptionController {
 public function __construct(private VisitPrescriptions $prescriptions) {}
 public function index(Request $request) {
  if($request->query()) throw ValidationException::withMessages(['request'=>'Unknown fields.']);
  return response()->json($this->prescriptions->list($request->user(),$this->tenant($request),$request->route('visit')))->header('Cache-Control','no-store');
 }
 public function store(Request $request) { return $this->write($request,null); }
 public function update(Request $request) { return $this->write($request,$request->route('prescription')); }
 private function write(Request $request,?string $id) {
  $rules=['expected_version'=>'required|integer|min:0','note'=>'nullable|string|max:4000','medicines'=>'required|array|min:1|max:30',
   'medicines.*.name'=>'required|string|max:500','medicines.*.form'=>'present|string|max:500',
   'medicines.*.strength'=>'present|string|max:500','medicines.*.dose'=>'required|string|max:500',
   'medicines.*.unit'=>'required|string|max:500','medicines.*.route'=>'present|string|max:500',
   'medicines.*.frequency'=>'required|string|max:500','medicines.*.duration'=>'required|string|max:500',
   'medicines.*.instruction'=>'present|string|max:500'];
  if(array_diff(array_keys($request->all()),['expected_version','note','medicines']))
   throw ValidationException::withMessages(['request'=>'Unknown fields.']);
  $data=validator($request->all(),$rules)->validate();
  $data['note'] = $data['note'] ?? '';
  foreach($data['medicines'] as $item) {
   if(array_diff(array_keys($item),['name','form','strength','dose','unit','route','frequency','duration','instruction']))
    throw ValidationException::withMessages(['medicines'=>'Unknown medicine fields.']);
  }
  return response()->json($this->prescriptions->save($request->user(),$this->tenant($request),$request->route('visit'),$id,$data),$id?200:201)->header('Cache-Control','no-store');
 }
 private function tenant(Request $request): string {
  $tenant=$request->session()->get('active_tenant_id');
  abort_unless(is_string($tenant),409,'Select an active clinic.');
  return $tenant;
 }
}

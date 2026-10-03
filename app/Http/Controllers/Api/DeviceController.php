<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\SecurePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class DeviceController extends Controller {
 public function legacyOnboardingDisabled(): JsonResponse{return response()->json(['message'=>'Manual device registration and table-code pairing have been retired. Ask an Administrator to generate a secure one-time pairing QR, then scan it in the Customer app.','error'=>'secure_pairing_required'],410);}
 public function pairWithToken(Request $r,SecurePairingService $service){$data=$r->validate(['pairing_token'=>'required|string|size:64','payload_signature'=>['required','string','size:64','regex:/\A[a-f0-9]{64}\z/'],'device_uuid'=>'required|uuid','device_name'=>'required|string|max:255','app_version'=>'nullable|string|max:40']);return response()->json($service->consume($data),201);}
 public function heartbeat(Request $r){return ['ok'=>true,'server_time'=>now()->toIso8601String()];}
}

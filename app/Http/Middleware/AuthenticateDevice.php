<?php
namespace App\Http\Middleware;
use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class AuthenticateDevice { public function handle(Request $request, Closure $next): Response { $device=$request->user(); abort_unless($device instanceof Device && $device->is_active && hash_equals($device->device_uuid,(string)$request->header('X-Device-UUID')),401); $device->update(['last_seen_at'=>now(),'ip_address'=>$request->ip()]); $request->attributes->set('device',$device); return $next($request); } }

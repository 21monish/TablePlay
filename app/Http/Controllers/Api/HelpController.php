<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HelpAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpController extends Controller
{
    public function chat(Request $request, HelpAssistantService $assistant): JsonResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'min:2', 'max:500']]);

        return response()->json($assistant->answer($validated['message'], $request->user()));
    }
}

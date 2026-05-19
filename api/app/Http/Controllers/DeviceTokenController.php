<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'platform' => ['required', 'string', 'in:fcm,apns'],
        ]);

        $user = Auth::guard('api')->user();

        $deviceToken = DeviceToken::updateOrCreate(
            ['user_id' => $user->id, 'platform' => $data['platform']],
            ['token' => $data['token']],
        );

        return response()->json($deviceToken, $deviceToken->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'string', 'in:fcm,apns'],
        ]);

        $user = Auth::guard('api')->user();

        DeviceToken::where('user_id', $user->id)
            ->where('platform', $data['platform'])
            ->delete();

        return response()->json(['message' => 'Device token removed'], 200);
    }
}

<?php

namespace App\Http\Controllers\Betting;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BetController extends Controller
{
    public function odds(Request $request)
    {
        return response()->json([
            'message' => 'Market odds endpoint',
            'data' => [],
        ]);
    }

    public function place(Request $request)
    {
        return response()->json([
            'message' => 'Bet placement endpoint - implement validation and job dispatch here',
        ]);
    }

    public function cancel(Request $request)
    {
        return response()->json([
            'message' => 'Bet cancel endpoint - implement idempotent cancellation logic here',
        ]);
    }

    public function history(Request $request)
    {
        return response()->json([
            'message' => 'Betting history endpoint',
            'user' => Auth::guard('api')->user()?->id,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\Game;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    /**
     * Get user activity report
     * GET /admin/v1/reports/user-activity
     */
    public function userActivity(Request $request)
    {
        $days = $request->get('days', 30);
        $startDate = now()->subDays($days)->startOfDay();

        $userActivity = User::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as new_users'),
            DB::raw('SUM(CASE WHEN active = 1 AND blocked = 0 THEN 1 ELSE 0 END) as active_users')
        )
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        // Get betting activity per day
        $bettingActivity = Bet::select(
            DB::raw('DATE(placed_at) as date'),
            DB::raw('COUNT(*) as total_bets'),
            DB::raw('SUM(amount) as total_bet_amount'),
            DB::raw('COUNT(DISTINCT user_id) as unique_bettors')
        )
            ->where('placed_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'data' => [
                'user_registrations' => $userActivity,
                'betting_activity' => $bettingActivity,
            ],
            'meta' => [
                'days' => $days,
                'start_date' => $startDate->toDateString(),
            ],
        ]);
    }

    /**
     * Get financial report
     * GET /admin/v1/reports/financial
     */
    public function financial(Request $request)
    {
        $period = $request->get('period', 'daily');
        $days = $request->get('days', 30);
        $startDate = now()->subDays($days)->startOfDay();

        $deposits = Deposit::select(
            DB::raw('DATE(updated_at) as date'),
            DB::raw('COUNT(*) as count'),
            DB::raw('SUM(amount) as total_amount')
        )
            ->where('status', 'approved')
            ->where('updated_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        $withdrawals = Withdrawal::select(
            DB::raw('DATE(updated_at) as date'),
            DB::raw('COUNT(*) as count'),
            DB::raw('SUM(amount) as total_amount')
        )
            ->where('status', 'paid')
            ->where('updated_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        $bets = Bet::select(
            DB::raw('DATE(placed_at) as date'),
            DB::raw('COUNT(*) as count'),
            DB::raw('SUM(amount) as total_amount')
        )
            ->where('placed_at', '>=', $startDate)
            ->where('status', '!=', 'cancelled')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        $payouts = DB::table('settlements')
            ->join('bets', 'settlements.bet_id', '=', 'bets.id')
            ->select(
                DB::raw('DATE(settlements.settled_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(settlements.payout_amount) as total_amount')
            )
            ->where('settlements.settled_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'data' => [
                'deposits' => $deposits,
                'withdrawals' => $withdrawals,
                'bets' => $bets,
                'payouts' => $payouts,
            ],
            'meta' => [
                'period' => $period,
                'days' => $days,
            ],
        ]);
    }

    /**
     * Get game performance report
     * GET /admin/v1/reports/game-performance
     */
    public function gamePerformance(Request $request)
    {
        $days = $request->get('days', 30);
        $startDate = now()->subDays($days)->startOfDay();

        $games = Game::where('status', 'active')->withCount(['bets as total_bets' => function ($query) use ($startDate) {
            $query->where('placed_at', '>=', $startDate);
        }])->withSum(['bets as total_bet_amount' => function ($query) use ($startDate) {
            $query->where('placed_at', '>=', $startDate);
        }], 'amount')->get();

        $performance = [];
        foreach ($games as $game) {
            $payout = Settlement::whereHas('bet', function ($q) use ($game, $startDate) {
                $q->where('game_id', $game->id)
                  ->where('placed_at', '>=', $startDate);
            })->sum('payout_amount');

            $performance[] = [
                'game_id' => $game->id,
                'game_name' => $game->name,
                'engine_key' => $game->engine_key,
                'total_bets' => $game->total_bets,
                'total_bet_amount' => round($game->total_bet_amount ?? 0, 2),
                'total_payout' => round($payout, 2),
                'house_profit' => round(($game->total_bet_amount ?? 0) - $payout, 2),
                'roi' => $payout > 0 ? round((($game->total_bet_amount ?? 0) / $payout - 1) * 100, 2) : 0,
            ];
        }

        return response()->json([
            'data' => $performance,
            'meta' => [
                'days' => $days,
                'start_date' => $startDate->toDateString(),
            ],
        ]);
    }

    /**
     * Get transaction report
     * GET /admin/v1/reports/transactions
     */
    public function transactions(Request $request)
    {
        $type = $request->get('type'); // deposit, withdrawal, bet, payout
        $startDate = $request->get('from_date', now()->subDays(30)->toDateString());
        $endDate = $request->get('to_date', now()->toDateString());

        $results = [];

        if (!$type || $type === 'deposit') {
            $results['deposits'] = Deposit::with('user')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('created_at', 'desc')
                ->limit(1000)
                ->get();
        }

        if (!$type || $type === 'withdrawal') {
            $results['withdrawals'] = Withdrawal::with('user')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('created_at', 'desc')
                ->limit(1000)
                ->get();
        }

        if (!$type || $type === 'bet') {
            $results['bets'] = Bet::with(['user', 'game'])
                ->whereBetween('placed_at', [$startDate, $endDate])
                ->orderBy('placed_at', 'desc')
                ->limit(1000)
                ->get();
        }

        return response()->json([
            'data' => $results,
            'meta' => [
                'from_date' => $startDate,
                'to_date' => $endDate,
                'type' => $type,
            ],
        ]);
    }

    /**
     * Get audit log report (placeholder - implement with activity log package)
     * GET /admin/v1/reports/audit-logs
     */
    public function auditLogs(Request $request)
    {
        // This would typically use a package like spatie/laravel-activitylog
        // For now, return a placeholder
        return response()->json([
            'data' => [],
            'message' => 'Audit log feature requires additional package installation',
            'meta' => [
                'suggested_package' => 'spatie/laravel-activitylog',
            ],
        ]);
    }

    /**
     * Export report to CSV (placeholder)
     * GET /admin/v1/reports/export
     */
    public function export(Request $request)
    {
        $type = $request->get('type', 'financial');
        $format = $request->get('format', 'csv');

        // Implement CSV/Excel export here
        // Suggest using maatwebsite/excel package

        return response()->json([
            'message' => 'Export feature requires maatwebsite/excel package',
            'meta' => [
                'type' => $type,
                'format' => $format,
                'suggested_package' => 'maatwebsite/excel',
            ],
        ]);
    }
}

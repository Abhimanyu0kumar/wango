<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Bet;
use App\Models\WalletAccount;
use App\Models\Withdrawal;
use App\Models\Deposit;
use App\Models\Game;
use App\Models\LuckyDrawRound;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard summary
     */
    public function summary(Request $request)
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $thisMonth = now()->startOfMonth();

        // User stats
        $totalUsers = User::count();
        $activeUsers = User::where('active', true)->where('blocked', false)->count();
        $newUsersToday = User::where('created_at', '>=', $today)->count();
        $newUsersYesterday = User::whereBetween('created_at', [$yesterday, $today])->count();

        // Financial stats
        $totalDeposits = Deposit::where('status', 'approved')->sum('amount');
        $pendingDeposits = Deposit::where('status', 'pending')->sum('amount');
        $pendingWithdrawals = Withdrawal::where('status', 'pending')->sum('amount');
        $totalWithdrawalApproved = Withdrawal::where('status', 'approved')->sum('amount');

        $depositsToday = Deposit::where('status', 'approved')
            ->where('updated_at', '>=', $today)
            ->sum('amount');
        $withdrawalsToday = Withdrawal::where('status', 'approved')
            ->where('updated_at', '>=', $today)
            ->sum('amount');

        // Betting stats
        $totalBets = Bet::count();
        $totalBetAmount = Bet::sum('amount');
        $totalPayout = Settlement::sum('payout_amount');
        $houseProfit = $totalBetAmount - $totalPayout;

        $betsToday = Bet::where('placed_at', '>=', $today)->count();
        $betAmountToday = Bet::where('placed_at', '>=', $today)->sum('amount');
        $payoutToday = Settlement::where('settled_at', '>=', $today)->sum('payout_amount');

        // Wallet stats
        $totalBalance = WalletAccount::where('status', 'active')->sum('available_balance');
        $totalLocked = WalletAccount::where('status', 'active')->sum('locked_balance');

        // Pending actions
        $pendingKyc = \App\Models\UserKycDocument::where('status', 'pending')->count();
        $pendingWithdrawalCount = Withdrawal::where('status', 'pending')->count();

        return response()->json([
            'data' => [
                'users' => [
                    'total' => $totalUsers,
                    'active' => $activeUsers,
                    'new_today' => $newUsersToday,
                    'new_yesterday' => $newUsersYesterday,
                ],
                'financials' => [
                    'total_deposits' => round($totalDeposits, 2),
                    'pending_deposits' => round($pendingDeposits, 2),
                    'total_withdrawals_approved' => round($totalWithdrawalApproved, 2),
                    'pending_withdrawals' => round($pendingWithdrawals, 2),
                    'deposits_today' => round($depositsToday, 2),
                    'withdrawals_today' => round($withdrawalsToday, 2),
                ],
                'betting' => [
                    'total_bets' => $totalBets,
                    'total_bet_amount' => round($totalBetAmount, 2),
                    'total_payout' => round($totalPayout, 2),
                    'house_profit' => round($houseProfit, 2),
                    'bets_today' => $betsToday,
                    'bet_amount_today' => round($betAmountToday, 2),
                    'payout_today' => round($payoutToday, 2),
                ],
                'wallets' => [
                    'total_balance' => round($totalBalance, 2),
                    'total_locked' => round($totalLocked, 2),
                ],
                'pending_actions' => [
                    'kyc_documents' => $pendingKyc,
                    'withdrawals' => $pendingWithdrawalCount,
                ],
            ],
        ]);
    }

    /**
     * Get revenue report
     */
    public function revenue(Request $request)
    {
        $period = $request->get('period', 'daily');
        $days = $request->get('days', 30);

        $startDate = now()->subDays($days)->startOfDay();

        $revenue = DB::table('bets')
            ->join('settlements', 'bets.id', '=', 'settlements.bet_id')
            ->select(
                DB::raw('DATE(bets.placed_at) as date'),
                DB::raw('COUNT(bets.id) as total_bets'),
                DB::raw('SUM(bets.amount) as total_bet_amount'),
                DB::raw('SUM(settlements.payout_amount) as total_payout'),
                DB::raw('SUM(bets.amount) - SUM(settlements.payout_amount) as profit')
            )
            ->where('bets.placed_at', '>=', $startDate)
            ->where('bets.status', '!=', 'cancelled')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'data' => $revenue,
            'meta' => [
                'period' => $period,
                'days' => $days,
            ],
        ]);
    }

    /**
     * Get active users count
     */
    public function activeUsers(Request $request)
    {
        $today = now()->startOfDay();
        
        $activeToday = User::whereHas('bets', function ($q) use ($today) {
            $q->where('placed_at', '>=', $today);
        })->count();

        $activeWeek = User::whereHas('bets', function ($q) {
            $q->where('placed_at', '>=', now()->subDays(7));
        })->count();

        $activeMonth = User::whereHas('bets', function ($q) {
            $q->where('placed_at', '>=', now()->subDays(30));
        })->count();

        return response()->json([
            'data' => [
                'today' => $activeToday,
                'this_week' => $activeWeek,
                'this_month' => $activeMonth,
            ],
        ]);
    }

    /**
     * Get game statistics
     */
    public function gameStats(Request $request)
    {
        $gameId = $request->get('game_id');

        $query = Game::where('status', 'active');

        if ($gameId) {
            $query->where('id', $gameId);
        }

        $games = $query->get();
        $stats = [];

        foreach ($games as $game) {
            $engine = new \App\Services\GameEngines\LuckyDrawGameEngine($game);
            $gameStats = $engine->getGameStats();

            $liveState = null;
            if ($game->engine_key === 'dice') {
                $autoManager = new \App\Services\GameEngines\AutoRoundManager();
                $liveState = $autoManager->getLiveState($game);
            }

            $stats[] = [
                'game_id' => $game->id,
                'game_name' => $game->name,
                'engine_key' => $game->engine_key,
                'stats' => $gameStats,
                'live_state' => $liveState,
            ];
        }

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get withdrawal pending summary
     */
    public function withdrawalPending(Request $request)
    {
        $pending = Withdrawal::where('status', 'pending')
            ->with(['user', 'wallet'])
            ->orderBy('created_at', 'asc')
            ->get();

        $totalAmount = $pending->sum('amount');
        $count = $pending->count();

        return response()->json([
            'data' => $pending,
            'meta' => [
                'count' => $count,
                'total_amount' => round($totalAmount, 2),
            ],
        ]);
    }
}

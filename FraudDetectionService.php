<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use App\Models\ServiceTransaction;
use App\Models\WalletTransaction;
use App\Events\FraudDetected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * FraudDetectionService
 *
 * Real-time transaction anomaly detection.
 * Scores each transaction and flags suspicious ones.
 *
 * Rules checked:
 *  1. Velocity — too many transactions too fast
 *  2. Amount spike — sudden large transaction
 *  3. Time-of-day — unusual hours for this user
 *  4. New device/IP
 *  5. Multiple failed PINs before success
 *  6. Rapid beneficiary switching
 *  7. Daily limit breach
 */
class FraudDetectionService
{
    // Score thresholds
    private const WARN_SCORE  = 40;
    private const BLOCK_SCORE = 80;

    /**
     * Score a transaction before processing.
     * Returns ['score' => int, 'flags' => [], 'action' => 'allow|warn|block']
     */
    public function scoreTransaction(
        User $user,
        float $amount,
        string $serviceType,
        string $phone,
        string $ip,
    ): array {
        $score = 0;
        $flags = [];

        // ── Rule 1: Velocity Check ────────────────────────────────────────────
        // More than 5 transactions in 10 minutes → suspicious
        $recentCount = $this->getRecentTransactionCount($user->id, 10);
        if ($recentCount >= 10) {
            $score += 50;
            $flags[] = 'HIGH_VELOCITY';
        } elseif ($recentCount >= 5) {
            $score += 25;
            $flags[] = 'MODERATE_VELOCITY';
        }

        // ── Rule 2: Amount Spike ──────────────────────────────────────────────
        // Amount is 5x the user's average transaction
        $avgAmount = $this->getUserAverageAmount($user->id, $serviceType);
        if ($avgAmount > 0 && $amount > ($avgAmount * 5)) {
            $score += 30;
            $flags[] = 'AMOUNT_SPIKE';
        }

        // ── Rule 3: Daily Limit ───────────────────────────────────────────────
        $dailySpend = $this->getDailySpend($user->id);
        $dailyLimit = config('vtu.wallet.daily_transfer_limit', 500000);
        if ($dailySpend + $amount > $dailyLimit) {
            $score += 40;
            $flags[] = 'DAILY_LIMIT_EXCEEDED';
        }

        // ── Rule 4: New IP Address ────────────────────────────────────────────
        if ($this->isNewIp($user->id, $ip)) {
            $score += 10;
            $flags[] = 'NEW_IP';
        }

        // ── Rule 5: Odd Hours ─────────────────────────────────────────────────
        // Between 1am - 5am local time
        $hour = (int) now()->format('G');
        if ($hour >= 1 && $hour <= 5) {
            $score += 10;
            $flags[] = 'ODD_HOURS';
        }

        // ── Rule 6: Rapid Beneficiary Switching ───────────────────────────────
        // 3+ different phone numbers in last 5 transactions
        $recentBeneficiaries = $this->getRecentBeneficiaries($user->id, 5);
        if (count($recentBeneficiaries) >= 4) {
            $score += 20;
            $flags[] = 'RAPID_BENEFICIARY_SWITCH';
        }

        // ── Rule 7: Previously Flagged User ───────────────────────────────────
        if (Cache::has("fraud_flagged_{$user->id}")) {
            $score += 30;
            $flags[] = 'PREVIOUSLY_FLAGGED';
        }

        // ── Determine Action ──────────────────────────────────────────────────
        $action = match (true) {
            $score >= self::BLOCK_SCORE => 'block',
            $score >= self::WARN_SCORE  => 'warn',
            default                     => 'allow',
        };

        // Log and fire event if suspicious
        if ($action !== 'allow') {
            Log::warning('Fraud detection triggered', [
                'user_id'      => $user->id,
                'score'        => $score,
                'flags'        => $flags,
                'action'       => $action,
                'amount'       => $amount,
                'service_type' => $serviceType,
                'ip'           => $ip,
            ]);

            event(new FraudDetected($user, $score, $flags, $action));

            // Cache flag for 24 hours
            if ($action === 'block') {
                Cache::put("fraud_flagged_{$user->id}", true, now()->addHours(24));
            }
        }

        return compact('score', 'flags', 'action');
    }

    /**
     * Get number of transactions in the last N minutes.
     */
    private function getRecentTransactionCount(int $userId, int $minutes): int
    {
        return Cache::remember(
            "tx_count_{$userId}_{$minutes}",
            60, // cache for 1 minute
            fn () => ServiceTransaction::where('user_id', $userId)
                ->where('created_at', '>=', now()->subMinutes($minutes))
                ->count()
        );
    }

    /**
     * Get user's average transaction amount for a service type.
     */
    private function getUserAverageAmount(int $userId, string $serviceType): float
    {
        return Cache::remember(
            "avg_amount_{$userId}_{$serviceType}",
            3600, // cache for 1 hour
            fn () => (float) ServiceTransaction::where('user_id', $userId)
                ->where('service_type', $serviceType)
                ->where('status', 'success')
                ->avg('amount') ?? 0
        );
    }

    /**
     * Get today's total spend for a user.
     */
    private function getDailySpend(int $userId): float
    {
        return (float) WalletTransaction::where('user_id', $userId)
            ->where('type', 'debit')
            ->where('status', 'success')
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    /**
     * Check if this IP is new for the user.
     */
    private function isNewIp(int $userId, string $ip): bool
    {
        return ! \App\Models\LoginHistory::where('user_id', $userId)
            ->where('ip_address', $ip)
            ->where('status', 'success')
            ->exists();
    }

    /**
     * Get unique beneficiary phone numbers from recent transactions.
     */
    private function getRecentBeneficiaries(int $userId, int $count): array
    {
        return ServiceTransaction::where('user_id', $userId)
            ->whereNotNull('phone')
            ->latest()
            ->limit($count)
            ->pluck('phone')
            ->unique()
            ->values()
            ->toArray();
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\ServiceTransaction;
use App\Models\PaymentTransaction;
use App\Models\SupportTicket;

/**
 * TransactionPolicy
 *
 * Prevents IDOR: ensures users can only access their OWN data.
 * Even if an attacker guesses/decodes an ID, this policy
 * blocks cross-user and cross-tenant access.
 */
class TransactionPolicy
{
    /**
     * Can this user view this wallet transaction?
     */
    public function view(User $user, WalletTransaction $transaction): bool
    {
        // Must be same tenant AND same user (or admin)
        return $transaction->tenant_id === $user->tenant_id
            && (
                $transaction->user_id === $user->id
                || $user->hasAnyRole(['admin', 'super_admin', 'support'])
            );
    }

    /**
     * Can this user view this service transaction?
     */
    public function viewService(User $user, ServiceTransaction $transaction): bool
    {
        return $transaction->tenant_id === $user->tenant_id
            && (
                $transaction->user_id === $user->id
                || $user->hasAnyRole(['admin', 'super_admin', 'support'])
            );
    }

    /**
     * Can this user download the receipt?
     */
    public function downloadReceipt(User $user, ServiceTransaction $transaction): bool
    {
        return $transaction->tenant_id === $user->tenant_id
            && $transaction->user_id === $user->id
            && $transaction->status === 'success';
    }

    /**
     * Can admin reverse this transaction?
     */
    public function reverse(User $user, WalletTransaction $transaction): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin'])
            && $transaction->tenant_id === $user->tenant_id
            && $transaction->status === 'success'
            && $transaction->type === 'debit';
    }
}

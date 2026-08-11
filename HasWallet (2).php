<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\WalletTransaction;
use App\Helpers\MoneyHelper;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WalletException;
use Illuminate\Support\Facades\DB;

/**
 * HasWallet — Edge-case hardened version
 *
 * Fixes applied:
 *  [W1]  Double-click prevention — idempotency enforced
 *  [W3]  Negative balance prevention — DB constraint + code check
 *  [W5]  Self-transfer blocked
 *  [W6]  Transfer to suspended/banned user blocked
 *  [P4]  Amount always re-verified server-side (never trust frontend)
 *  MoneyHelper used for all arithmetic (no float bugs)
 */
trait HasWallet
{
    /**
     * Credit wallet — atomic, idempotent, BCMath safe.
     *
     * @throws WalletException
     */
    public function creditWallet(
        float|string $amount,
        string $category,
        string $description,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): WalletTransaction {

        // [P4] Server-side amount validation — never trust caller
        $amount = MoneyHelper::validate($amount, 'credit amount');

        // [W1] Idempotency — return existing transaction if duplicate
        if ($idempotencyKey) {
            $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)
                ->where('type', 'credit')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use (
            $amount,
            $category,
            $description,
            $metadata,
            $idempotencyKey,
        ) {
            // Lock row — prevents race conditions
            $user = static::lockForUpdate()->findOrFail($this->id);

            $balanceBefore = $user->wallet_balance;

            // [BCMath] Safe addition — no float bugs
            $balanceAfter = MoneyHelper::add($balanceBefore, $amount);

            // Update balance using BCMath result
            $user->update([
                'wallet_balance' => $balanceAfter,
                'total_funded'   => MoneyHelper::add($user->total_funded, $amount),
            ]);

            return WalletTransaction::create([
                'tenant_id'       => $this->tenant_id,
                'user_id'         => $this->id,
                'reference'       => generate_transaction_ref(),
                'idempotency_key' => $idempotencyKey,
                'type'            => 'credit',
                'category'        => $category,
                'amount'          => $amount,
                'balance_before'  => $balanceBefore,
                'balance_after'   => $balanceAfter,
                'status'          => 'success',
                'description'     => $description,
                'metadata'        => $metadata,
                'ip_address'      => request()->ip(),
                'completed_at'    => now(),
            ]);
        });
    }

    /**
     * Debit wallet — atomic, idempotent, BCMath safe.
     *
     * @throws InsufficientBalanceException
     * @throws WalletException
     */
    public function debitWallet(
        float|string $amount,
        string $category,
        string $description,
        float|string $charge = '0.00',
        float|string $profit = '0.00',
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): WalletTransaction {

        // [P4] Server-side validation
        $amount = MoneyHelper::validate($amount, 'debit amount');
        if ((float) $charge > 0) {
                $charge = MoneyHelper::validate($charge, 'charge');
            } else {
                $charge = '0.00';
            }
        // Allow zero charge
        $charge = (float) $charge <= 0 ? '0.00' : $charge;

        // [W1] Idempotency check
        if ($idempotencyKey) {
            $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)
                ->where('type', 'debit')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use (
            $amount,
            $category,
            $description,
            $charge,
            $profit,
            $metadata,
            $idempotencyKey,
        ) {
            // Lock row
            $user = static::lockForUpdate()->findOrFail($this->id);

            // Total to deduct
            $totalDebit = MoneyHelper::add($amount, $charge);

            // [W3] Explicit balance check AFTER locking
            if (! MoneyHelper::greaterThanOrEqual($user->wallet_balance, $totalDebit)) {
                throw new InsufficientBalanceException(
                    'Insufficient wallet balance. Required: ' .
                    MoneyHelper::format($totalDebit) .
                    ', Available: ' .
                    MoneyHelper::format($user->wallet_balance)
                );
            }

            $balanceBefore = $user->wallet_balance;
            $balanceAfter  = MoneyHelper::subtract($balanceBefore, $totalDebit);

            // Final safety — should never be negative after lockForUpdate
            if (MoneyHelper::compare($balanceAfter, '0') < 0) {
                throw new WalletException(
                    'Balance integrity check failed. Transaction aborted.'
                );
            }

            $user->update([
                'wallet_balance' => $balanceAfter,
                'total_spent'    => MoneyHelper::add($user->total_spent, $totalDebit),
            ]);

            return WalletTransaction::create([
                'tenant_id'       => $this->tenant_id,
                'user_id'         => $this->id,
                'reference'       => generate_transaction_ref(),
                'idempotency_key' => $idempotencyKey,
                'type'            => 'debit',
                'category'        => $category,
                'amount'          => $amount,
                'charge'          => $charge,
                'balance_before'  => $balanceBefore,
                'balance_after'   => $balanceAfter,
                'profit'          => $profit,
                'status'          => 'success',
                'description'     => $description,
                'metadata'        => $metadata,
                'ip_address'      => request()->ip(),
                'completed_at'    => now(),
            ]);
        });
    }

    /**
     * Transfer to another user.
     *
     * [W5] Self-transfer blocked.
     * [W6] Transfer to suspended/banned user blocked.
     *
     * @throws WalletException
     */
    public function transferTo(
        self $receiver,
        float|string $amount,
        string $narration = '',
        float|string $charge = '0.00',
        ?string $idempotencyKey = null,
    ): array {

        // [W5] Block self-transfer
        if ($this->id === $receiver->id) {
            throw new WalletException(
                'You cannot transfer to your own wallet.'
            );
        }

        // [W6] Block transfer to inactive accounts
        if (! in_array($receiver->status->value, ['active'])) {
            throw new WalletException(
                'Cannot transfer to this account. Please verify the recipient.'
            );
        }

        // Ensure same tenant — prevent cross-tenant transfer
        if ($this->tenant_id !== $receiver->tenant_id) {
            throw new WalletException(
                'Cross-tenant transfers are not allowed.'
            );
        }

        $idempotencyKey ??= generate_idempotency_key(
            $this->id,
            "transfer_{$receiver->id}_{$amount}"
        );

        return DB::transaction(function () use (
            $receiver,
            $amount,
            $narration,
            $charge,
            $idempotencyKey,
        ) {
            // Debit sender (includes charge)
            $debitTx = $this->debitWallet(
                amount: $amount,
                category: 'wallet_transfer',
                description: "Transfer to {$receiver->full_name}: {$narration}",
                charge: $charge,
                idempotencyKey: $idempotencyKey . '_debit',
            );

            // Credit receiver (amount only, not charge)
            $creditTx = $receiver->creditWallet(
                amount: $amount,
                category: 'wallet_transfer',
                description: "Transfer from {$this->full_name}: {$narration}",
                idempotencyKey: $idempotencyKey . '_credit',
            );

            // Log the transfer
            \App\Models\WalletTransfer::create([
                'tenant_id'   => $this->tenant_id,
                'sender_id'   => $this->id,
                'receiver_id' => $receiver->id,
                'reference'   => generate_transaction_ref('TRF'),
                'amount'      => $amount,
                'charge'      => $charge,
                'narration'   => $narration,
                'status'      => 'success',
            ]);

            return [
                'debit_transaction'  => $debitTx,
                'credit_transaction' => $creditTx,
            ];
        });
    }

    /**
     * Reverse a debit transaction.
     */
    public function reverseTransaction(WalletTransaction $transaction): WalletTransaction
    {
        if ($transaction->user_id !== $this->id) {
            throw new WalletException(
                'Transaction does not belong to this user.'
            );
        }

        if ($transaction->status !== 'success') {
            throw new WalletException(
                'Only successful transactions can be reversed.'
            );
        }

        if ($transaction->type !== 'debit') {
            throw new WalletException(
                'Only debit transactions can be reversed.'
            );
        }

        $reversal = $this->creditWallet(
            amount: MoneyHelper::add($transaction->amount, $transaction->charge),
            category: 'reversal',
            description: "Reversal of {$transaction->reference}",
            metadata: ['original_reference' => $transaction->reference],
        );

        $transaction->update(['status' => 'reversed']);

        return $reversal;
    }

    /**
     * Get fresh balance directly from DB (never cached).
     */
    public function freshBalance(): string
    {
        return (string) (static::find($this->id)?->wallet_balance ?? '0.00');
    }
}

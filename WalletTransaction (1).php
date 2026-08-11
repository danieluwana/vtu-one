<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;
use App\Traits\HasHashId;

class WalletTransaction extends Model
{
    use BelongsToTenant, HasHashId, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'reference',
        'idempotency_key',
        'type',
        'category',
        'amount',
        'charge',
        'balance_before',
        'balance_after',
        'profit',
        'status',
        'description',
        'metadata',
        'provider_reference',
        'ip_address',
        'performed_by',
        'failure_reason',
        'completed_at',
    ];

    /**
     * Appended accessors — available on every model instance.
     * hash_id comes from HasHashId trait (already appended there).
     */
    protected $appends = [
        'status_color_class',
        'icon_svg',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'charge'         => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after'  => 'decimal:2',
            'profit'         => 'decimal:2',
            'metadata'       => 'array',
            'completed_at'   => 'datetime',
            'user_id'      => 'integer',
            'performed_by' => 'integer',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Inverse of ServiceTransaction::walletTransaction(). Added 2026-08-04
     * so the receipt page can surface token/pin data stored on the
     * ServiceTransaction's metadata — previously there was no way to get
     * from a WalletTransaction back to its ServiceTransaction at all.
     */
    public function serviceTransaction(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ServiceTransaction::class, 'wallet_transaction_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Computed Accessors (used by dashboard transaction list) ───────────

    /**
     * Tailwind background class for the transaction icon bubble.
     * Depends on type + status.
     */
    public function getStatusColorClassAttribute(): string
    {
        return match (true) {
            $this->type === 'credit' && $this->status === 'success' => 'bg-green-100',
            $this->type === 'debit'  && $this->status === 'success' => 'bg-red-100',
            $this->status === 'pending'                             => 'bg-yellow-100',
            $this->status === 'failed'                              => 'bg-gray-100',
            $this->status === 'reversed'                            => 'bg-orange-100',
            default                                                 => 'bg-blue-100',
        };
    }

    /**
     * Inline SVG icon matched to the transaction description.
     * Covers all VTU service types + generic credit/debit fallback.
     */
    public function getIconSvgAttribute(): string
    {
        $desc     = strtolower($this->description ?? '');
        $isCredit = $this->type === 'credit';

        // Airtime
        if (str_contains($desc, 'airtime')) {
            return '<svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
            </svg>';
        }

        // Data / SME / Gifting / Corporate
        if (str_contains($desc, 'data') || str_contains($desc, 'sme') || str_contains($desc, 'gifting') || str_contains($desc, 'corporate')) {
            return '<svg class="w-5 h-5 text-cyan-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.143 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
            </svg>';
        }

        // Cable TV
        if (str_contains($desc, 'cable') || str_contains($desc, 'dstv') || str_contains($desc, 'gotv') || str_contains($desc, 'startimes')) {
            return '<svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>';
        }

        // Electricity / Meter
        if (str_contains($desc, 'electricity') || str_contains($desc, 'meter') || str_contains($desc, 'token') || str_contains($desc, 'power')) {
            return '<svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>';
        }

        // Exam pins
        if (str_contains($desc, 'exam') || str_contains($desc, 'waec') || str_contains($desc, 'neco') || str_contains($desc, 'nabteb')) {
            return '<svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
            </svg>';
        }

        // Wallet transfer
        if (str_contains($desc, 'transfer')) {
            return '<svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
            </svg>';
        }

        // Reversal
        if (str_contains($desc, 'reversal')) {
            return '<svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
            </svg>';
        }

        // Fund wallet / generic credit
        if ($isCredit) {
            return '<svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>';
        }

        // Generic debit fallback
        return '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
        </svg>';
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToTenant;
use App\Traits\HasHashId;

class PaymentTransaction extends Model
{
    use BelongsToTenant, HasHashId;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'reference',
        'gateway_reference',
        'gateway',
        'amount',
        'gateway_charge',
        'amount_credited',
        'status',
        'channel',
        'gateway_response',
        'ip_address',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:2',
            'gateway_charge'   => 'decimal:2',
            'amount_credited'  => 'decimal:2',
            'gateway_response' => 'array',
            'paid_at'          => 'datetime',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

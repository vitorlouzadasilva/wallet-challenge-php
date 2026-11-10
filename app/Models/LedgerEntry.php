<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    protected function casts(): array
    {
        return ['delta_cents' => 'integer'];
    }
}

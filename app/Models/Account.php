<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory;

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function linkedAccount()
    {
        return $this->belongsTo(LinkedAccount::class);
    }
}

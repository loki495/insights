<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LinkedAccount extends Model
{
    /** @use HasFactory<\Database\Factories\LinkedAccountFactory> */
    use HasFactory;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function updateInfo() : self
    {
        $plaid = app(\App\Services\Plaid\PlaidService::class, ['environment' => \App\Services\Plaid\PlaidService::ENV_SANDBOX]);
        $info = $plaid->getItemInfo(data: [
            'access_token' => $this->access_token
        ]);

        $this->provider_name = $info['item']['institution_name'];

        $this->save();

        return $this;

    }
}

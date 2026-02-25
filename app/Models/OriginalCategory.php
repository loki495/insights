<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OriginalCategory extends Model
{
    /** @use HasFactory<\Database\Factories\OriginalCategoryFactory> */
    use HasFactory;

    private $details = [
        21005000 => 'Transfer IN',
        21001000 => 'Transfer OUT - Investment / Retirement',
        15001000 => 'Interest Earned',
        16000000 => 'Transfer OUT',
        16001000 => 'Credit Card Payment',
        21006000 => 'Transfer OUT - Other',
        21009000 => 'Income',
        19019000 => 'Electronics',
        10000000 => 'Restaurant',
        13005000 => 'Restaurant',
        18068004 => 'Gas',
        18020004 => 'Loan Payment',
        21007000 => 'Deposit',
        19047000 => 'Groceries',
        13005032 => 'Fast Food',
        19025000 => 'Liquor Store',
        18009000 => 'Internet / Cable Bill',
        19048000 => 'Tobacco / Vape',
        18061000 => 'TV & Movies',
        13005043 => 'Coffee',
        22016000 => 'Taxi / Ride Share',
        18020001 => 'Accounting / Financial Planning',
        20002000 => 'Gov. Tax / Non-Profit Payment',
        16002000 => 'General Services',
        18063000 => 'Utilities',
        18045000 => 'Personal Care',
        18020000 => 'Transfer Out - Investment / Retirement',
        18008000 => 'General Services',
        13001000 => 'Entertainment - Music &amp; Audio',
        22009000 => 'Gas',
        15002000 => 'Interest Charged',
        21010003 => 'Transfer OUT - Other',
        21008000 => 'Transfer In - Savings',
        19013000 => 'Loan Payment',
        22013000 => 'Parking',
        19005007 => 'Auto',
        18006000 => 'Auto',
        17001000 => 'TV & Movies',
        18020007 => 'Loan Payment',
        21007001 => 'Deposit / Transfer In',
        19015000 => 'General Store',
        18006003 => 'Car Service',
        17015000 => 'Sporting Goods',
    ];

    public function details() : Attribute
    {
        if (!isset($this->details[$this->plaid_id])) {
            dd($this);
        }
        $str = $this->details[$this->plaid_id];
        return Attribute::make(
            get: fn ($value) => $str
        );
    }

    public function transactions() : HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function total() : Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->transactions->sum('amount')
        );
    }
}

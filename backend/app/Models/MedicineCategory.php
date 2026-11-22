<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicineCategory extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description'];

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }
}

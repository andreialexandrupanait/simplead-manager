<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        "name",
        "email",
        "phone",
        "company",
        "logo",
        "notes",
        "is_active",
    ];

    protected $casts = [
        "is_active" => "boolean",
    ];

    public function sites()
    {
        return $this->hasMany(Site::class);
    }
}

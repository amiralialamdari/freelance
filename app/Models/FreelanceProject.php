<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreelanceProject extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'analysis' => 'array',
            'submitted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}

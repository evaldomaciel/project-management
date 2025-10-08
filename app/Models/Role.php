<?php

namespace App\Models;

class Role extends \Spatie\Permission\Models\Role
{
    protected $fillable = [
        'name',
        'guard_name',
        'must_have_agenda'
    ];

    protected $casts = [
        'must_have_agenda' => 'boolean',
    ];
}

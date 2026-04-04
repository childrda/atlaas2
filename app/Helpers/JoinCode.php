<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class JoinCode
{
    public static function generate(string $table, string $column = 'join_code', int $length = 6): string
    {
        do {
            // Exclude characters that look alike: 0/O, 1/I/L
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, $length));
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }
}

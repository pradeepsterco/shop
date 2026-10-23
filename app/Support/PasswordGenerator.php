<?php

namespace App\Support;

class PasswordGenerator
{
    /**
     * Generate a cryptographically secure password guaranteed to pass Password::defaults().
     */
    public static function generate(int $length = 16): string
    {
        $length = max($length, 16);

        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $numbers = '23456789';
        $symbols = '!@#$%^&*()-_=+';

        // Ensure at least 2 of each category to be rock solid
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $numbers[random_int(0, strlen($numbers) - 1)],
            $numbers[random_int(0, strlen($numbers) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        $allPool = $upper.$lower.$numbers.$symbols;
        $remaining = $length - count($chars);
        for ($i = 0; $i < $remaining; $i++) {
            $chars[] = $allPool[random_int(0, strlen($allPool) - 1)];
        }

        // Shuffle securely
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $tmp = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $tmp;
        }

        return implode('', $chars);
    }
}

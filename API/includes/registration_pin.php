<?php
declare(strict_types=1);

function registration_pin_is_weak(string $pin): bool
{
    if (!preg_match('/^\d{4}$/D', $pin)) return true;
    if (preg_match('/^(\d)\1{3}$/D', $pin)) return true;
    return in_array($pin, ['0123', '1234', '4321', '3210'], true);
}

function registration_generate_pin(): string
{
    do {
        $pin = (string)random_int(1000, 9999);
    } while (registration_pin_is_weak($pin));
    return $pin;
}

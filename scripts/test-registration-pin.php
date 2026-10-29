<?php
declare(strict_types=1);

require_once __DIR__ . '/../API/includes/registration_pin.php';

foreach (['0000', '1111', '1234', '4321', '0123', '3210'] as $candidate) {
    if (!registration_pin_is_weak($candidate)) exit(1);
}
for ($index = 0; $index < 100; $index++) {
    $pin = registration_generate_pin();
    if (registration_pin_is_weak($pin) || strlen($pin) !== 4 || !ctype_digit($pin)) exit(1);
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === $pin || !password_verify($pin, $hash)) exit(1);
}
echo "registration_pin_checks=ok\n";

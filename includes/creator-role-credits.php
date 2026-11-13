<?php
defined('ABSPATH') || exit;

function tcs_creator_role_key(string $value): string {
    $value = trim($value);

    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function tcs_creator_role_credits(string $credits): array {
    $entries = [];

    foreach (preg_split('/\R/u', $credits) ?: [] as $line) {
        $parts = preg_split('/\s+[–—-]\s+/u', trim($line), 2);

        if (!$parts || count($parts) !== 2) {
            continue;
        }

        $name = trim($parts[0]);

        if ($name === '') {
            continue;
        }

        foreach (explode(',', $parts[1]) as $role) {
            $role = tcs_creator_role_key($role);
            $role = match ($role) {
                'penciller' => 'penciler',
                'colourist' => 'colorist',
                default => $role,
            };

            if (in_array($role, ['', 'n/a', 'unknown'], true)) {
                continue;
            }

            $entries[$role][tcs_creator_role_key($name)] ??= $name;
        }
    }

    return $entries;
}
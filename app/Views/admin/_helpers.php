<?php

declare(strict_types=1);

/**
 * Small shared helpers for the estate-admin views (Increment 4, contract §3/§7).
 *
 * Guarded function declarations so a render harness that renders several admin
 * views in one PHP process never re-declares them. Every helper is escaping-safe
 * (callers still e() values) and defensive about absent data.
 */

if (!function_exists('admin_icon')) {
    function admin_icon(string $name, string $extra = 'ico'): string
    {
        return \App\Core\View::capture('partials/icon', ['name' => $name, 'class' => $extra]);
    }
}

if (!function_exists('admin_humanize')) {
    function admin_humanize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
    }
}

if (!function_exists('admin_tone_class')) {
    function admin_tone_class(string $tone, string $status = ''): string
    {
        $mapped = match ($tone) {
            'ok' => 'status--active',
            'warn' => 'status--pending',
            'danger' => 'status--danger',
            'neutral' => 'status--inactive',
            default => '',
        };
        if ($mapped !== '') {
            return $mapped;
        }

        return match ($status) {
            'active', 'available', 'reserved' => 'status--active',
            'suspended', 'pending', 'checked_in' => 'status--pending',
            'inactive', 'archived', 'ended', 'cancelled', 'expired', 'completed' => 'status--inactive',
            'blacklisted', 'denied' => 'status--danger',
            default => 'status--inactive',
        };
    }
}

if (!function_exists('admin_field_error')) {
    function admin_field_error(array $errors, string $field): string
    {
        if (!array_key_exists($field, $errors)) {
            return '';
        }
        $messages = $errors[$field];
        if (is_array($messages)) {
            $first = reset($messages);

            return is_scalar($first) ? trim((string) $first) : '';
        }

        return is_scalar($messages) ? trim((string) $messages) : '';
    }
}

if (!function_exists('admin_error_list')) {
    /**
     * Flatten an errors map into a unique list of messages (for the summary box).
     *
     * @return array<int, string>
     */
    function admin_error_list(array $errors): array
    {
        $list = [];
        foreach ($errors as $messages) {
            if (is_array($messages)) {
                foreach ($messages as $message) {
                    if (is_scalar($message) && trim((string) $message) !== '') {
                        $list[] = trim((string) $message);
                    }
                }
            } elseif (is_scalar($messages) && trim((string) $messages) !== '') {
                $list[] = trim((string) $messages);
            }
        }

        return array_values(array_unique($list));
    }
}

if (!function_exists('admin_old')) {
    function admin_old(array $old, string $field, string $default = ''): string
    {
        $candidate = $old[$field] ?? $default;

        return is_scalar($candidate) ? trim((string) $candidate) : $default;
    }
}

if (!function_exists('admin_timezone')) {
    function admin_timezone(?array $estate = null): string
    {
        $timezone = $estate !== null ? trim((string) ($estate['estate_timezone'] ?? '')) : '';
        if ($timezone === '') {
            $timezone = (string) config('app.timezone', 'Africa/Nairobi');
        }

        return $timezone;
    }
}

if (!function_exists('admin_datetime')) {
    function admin_datetime(?string $iso, string $timezone, string $format = 'd M Y, H:i'): string
    {
        if ($iso === null || trim($iso) === '') {
            return '-';
        }

        return format_datetime($iso, $timezone, $format);
    }
}

if (!function_exists('admin_summary')) {
    /**
     * Compact "errors" summary used at the top of admin forms.
     *
     * @param array<string, mixed> $errors
     */
    function admin_summary(array $errors): string
    {
        $list = admin_error_list($errors);
        if ($list === []) {
            return '';
        }

        $html = '<div class="admin-error-summary alert alert--error" role="alert">'
            . admin_icon('alert')
            . '<div class="alert__body"><p class="alert__title">Please check the highlighted fields</p><ul>';

        foreach (array_slice($list, 0, 8) as $message) {
            $html .= '<li>' . e($message) . '</li>';
        }

        $html .= '</ul></div></div>';

        return $html;
    }
}

if (!function_exists('admin_can')) {
    /**
     * @param array<int, mixed> $permissions
     */
    function admin_can(array $permissions, string $slug): bool
    {
        foreach ($permissions as $permission) {
            if (is_string($permission) && $permission === $slug) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('admin_can_any')) {
    /**
     * @param array<int, mixed>  $permissions
     * @param array<int, string> $slugs
     */
    function admin_can_any(array $permissions, array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if (admin_can($permissions, $slug)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('admin_checkbox_checked')) {
    /**
     * @param array<int, mixed> $values
     */
    function admin_checkbox_checked(array $values, string $candidate): bool
    {
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value === $candidate) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Server-side input validation.
 *
 * Only fields listed in the rule set are returned, which prevents mass
 * assignment of authority-bearing fields from request bodies.
 */
final class Validator
{
    /** @var array<string, array<int, string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /** @var array<string, string> */
    private array $labels = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string> $labels Human-readable field names.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        return (new self())->run($data, $rules, $labels);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    private function run(array $data, array $rules, array $labels): array
    {
        $this->labels = $labels;

        foreach ($rules as $field => $ruleSet) {
            $ruleList = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $data[$field] ?? null;

            $required = in_array('required', $ruleList, true);
            $nullable = in_array('nullable', $ruleList, true);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                if ($required) {
                    $this->addError($field, 'This field is required.');

                    continue;
                }

                if ($nullable || !$required) {
                    if ($nullable) {
                        $this->validated[$field] = null;
                    }

                    continue;
                }
            }

            $skip = false;

            foreach ($ruleList as $rule) {
                if ($rule === '' || $rule === 'required' || $rule === 'nullable') {
                    continue;
                }

                if ($skip) {
                    break;
                }

                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

                $skip = $this->applyRule($field, $value, $name, $argument);
            }
        }

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    private function applyRule(string $field, mixed $value, string $rule, ?string $argument): bool
    {
        switch ($rule) {
            case 'string':
                if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                    $this->addError($field, 'This field must be text.');

                    return true;
                }

                $this->validated[$field] = trim((string) $value);

                return false;

            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, 'This field must be a whole number.');

                    return true;
                }

                $this->validated[$field] = (int) $value;

                return false;

            case 'boolean':
                $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                if ($bool === null) {
                    $this->addError($field, 'This field must be true or false.');

                    return true;
                }

                $this->validated[$field] = $bool;

                return false;

            case 'array':
                if (!is_array($value)) {
                    $this->addError($field, 'This field must be a list.');

                    return true;
                }

                $this->validated[$field] = $value;

                return false;

            case 'max':
                $max = (int) $argument;
                $length = is_array($value) ? count($value) : mb_strlen((string) $value);

                if ($length > $max) {
                    $this->addError($field, sprintf('This field may not be longer than %d characters.', $max));

                    return true;
                }

                $this->persistIfUnset($field, $value);

                return false;

            case 'min':
                $min = (int) $argument;
                $length = is_array($value) ? count($value) : mb_strlen((string) $value);

                if ($length < $min) {
                    $this->addError($field, sprintf('This field must be at least %d characters.', $min));

                    return true;
                }

                $this->persistIfUnset($field, $value);

                return false;

            case 'between':
                [$min, $max] = array_pad(explode(',', (string) $argument), 2, null);

                if (!is_numeric($value) || (int) $value < (int) $min || (int) $value > (int) $max) {
                    $this->addError($field, sprintf('This field must be between %s and %s.', $min, $max));

                    return true;
                }

                $this->validated[$field] = (int) $value;

                return false;

            case 'in':
                $allowed = array_map('trim', explode(',', (string) $argument));

                if (!in_array((string) $value, $allowed, true)) {
                    $this->addError($field, 'The selected value is not allowed.');

                    return true;
                }

                $this->validated[$field] = (string) $value;

                return false;

            case 'digits':
                $length = (int) $argument;
                $string = (string) $value;

                if (!ctype_digit($string) || ($length > 0 && strlen($string) !== $length)) {
                    $this->addError($field, sprintf('This field must be exactly %d digits.', $length));

                    return true;
                }

                $this->validated[$field] = $string;

                return false;

            case 'phone':
                try {
                    $this->validated[$field] = Phone::normalize(
                        (string) $value,
                        (string) Config::get('app.default_country_code', '254')
                    );
                } catch (\InvalidArgumentException) {
                    $this->addError($field, 'Enter a valid phone number, for example 0712 345 678.');
                }

                return false;

            case 'email':
                $email = filter_var((string) $value, FILTER_VALIDATE_EMAIL);

                if ($email === false) {
                    $this->addError($field, 'Enter a valid email address.');

                    return true;
                }

                $this->validated[$field] = mb_strtolower($email);

                return false;

            case 'uuid':
                $string = (string) $value;

                if (!Uuid::isValid($string)) {
                    $this->addError($field, 'This identifier is not valid.');

                    return true;
                }

                $this->validated[$field] = $string;

                return false;

            case 'date':
                try {
                    $this->validated[$field] = Clock::toDb(Clock::parse((string) $value));
                } catch (\InvalidArgumentException) {
                    $this->addError($field, 'Enter a valid date and time.');

                    return true;
                }

                return false;

            case 'slug':
                if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) !== 1) {
                    $this->addError($field, 'Use lowercase letters, numbers and single hyphens.');

                    return true;
                }

                $this->validated[$field] = (string) $value;

                return false;

            default:
                throw new \RuntimeException('Unknown validation rule: ' . $rule);
        }
    }

    private function persistIfUnset(string $field, mixed $value): void
    {
        if (!array_key_exists($field, $this->validated)) {
            $this->validated[$field] = is_string($value) ? trim($value) : $value;
        }
    }

    private function addError(string $field, string $message): void
    {
        $label = $this->labels[$field] ?? $field;

        if ($label !== $field) {
            $message = str_replace('This field', $label, $message);
        }

        $this->errors[$field][] = $message;
    }
}

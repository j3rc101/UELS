<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rule syntax: 'required|string|max:100', 'int|min:1', 'email', 'date', 'future_date',
 * 'in:a,b,c', 'bool', 'nullable'.  Only fields listed in $rules are returned (whitelist).
 */
class Validator
{
    public static function validate(array $data, array $rules, bool $partial = false): array
    {
        $errors = [];
        $clean  = [];

        foreach ($rules as $field => $ruleString) {
            $list     = explode('|', $ruleString);
            $required = in_array('required', $list, true);
            $present  = array_key_exists($field, $data);

            if ($partial && !$present) {
                continue;
            }

            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[$field] = "The {$field} field is required.";
                } elseif ($present) {
                    $clean[$field] = null;
                }
                continue;
            }

            foreach ($list as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $err = null;

                switch ($name) {
                    case 'string':
                        if (!is_scalar($value)) { $err = "The {$field} must be text."; break; }
                        $value = (string) $value;
                        break;
                    case 'int':
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) { $err = "The {$field} must be a whole number."; break; }
                        $value = (int) $value;
                        break;
                    case 'bool':
                        if (!in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) { $err = "The {$field} must be true or false."; break; }
                        $value = in_array($value, [true, 1, '1', 'true'], true) ? 1 : 0;
                        break;
                    case 'email':
                        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) { $err = "The {$field} must be a valid email address."; }
                        break;
                    case 'date':
                    case 'future_date':
                        $d = is_string($value) ? \DateTime::createFromFormat('!Y-m-d', $value) : false;
                        if (!$d || $d->format('Y-m-d') !== $value) { $err = "The {$field} must be a date in YYYY-MM-DD format."; break; }
                        if ($name === 'future_date' && $value < date('Y-m-d')) { $err = "The {$field} cannot be in the past."; }
                        break;
                    case 'in':
                        if (!in_array((string) $value, explode(',', (string) $arg), true)) { $err = "The {$field} must be one of: {$arg}."; }
                        break;
                    case 'min':
                        $n = is_int($value) ? $value : mb_strlen((string) $value);
                        if ($n < (int) $arg) { $err = "The {$field} must be at least {$arg}" . (is_int($value) ? '.' : ' characters.'); }
                        break;
                    case 'max':
                        $n = is_int($value) ? $value : mb_strlen((string) $value);
                        if ($n > (int) $arg) { $err = "The {$field} may not be greater than {$arg}" . (is_int($value) ? '.' : ' characters.'); }
                        break;
                }

                if ($err !== null) {
                    $errors[$field] = $err;
                    continue 2;
                }
            }
            $clean[$field] = $value;
        }

        if ($errors) {
            throw new ApiException('Validation failed', 422, $errors);
        }
        return $clean;
    }

    /** Validate an optional YYYY-MM-DD query parameter. */
    public static function dateParam($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', (string) $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new ApiException('Validation failed', 422, [$field => "The {$field} must be a date in YYYY-MM-DD format."]);
        }
        return $d->format('Y-m-d');
    }
}

<?php

namespace Platform\Core;

/**
 * Shared field-name-pattern redaction for anything this project logs.
 * Originally lived only inside PaystackClient (scoped to Paystack's own
 * response shapes); promoted here once sync introduced new field names
 * (password_hash, free-text notes/addresses) that class's narrower
 * pattern didn't cover - a single source of truth is the actual fix,
 * not a bigger regex copy-pasted into two places.
 *
 * Deliberately broad (bare `number`/`name` rather than an exact field
 * list): the real lesson from this pattern's history is that a fixed
 * list of field names drifts out of date the moment a new response/
 * table shape shows up that wasn't tested against (mobile_money_number
 * slipped through an earlier, narrower version of this pattern in
 * exactly this way).
 */
class Redactor
{
    private const PATTERN = '/secret|authorization|key|token|number|name|email|phone|ip_address|password|hash|address|note/i';

    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::redact($value);
                continue;
            }
            if (preg_match(self::PATTERN, (string) $key)) {
                $values[$key] = '[redacted]';
            }
        }
        return $values;
    }
}

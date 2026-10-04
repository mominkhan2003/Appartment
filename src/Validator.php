<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Input validation
 * ---------------------------------------------------------------------------
 * Chainable, framework-free validator. Throws ValidationException on
 * :check() so a bad request can never reach the service layer.
 *
 *   $v = Validator::make($_POST)
 *             ->required('title', 'Title')
 *             ->money('amount', 'Amount')
 *             ->in('split_type', 'Split', ['equal','selective','shares','meal_based'])
 *             ->check();
 */

declare(strict_types=1);

final class Validator
{
    private array $errors = [];
    private array $clean  = [];

    public function __construct(private readonly array $input)
    {
    }

    public static function make(array $input): self
    {
        return new self($input);
    }

    /* ------------------------------------------------------------------ */

    public function required(string $field, string $label): self
    {
        $value = $this->raw($field);
        if ($value === null || (is_string($value) && trim($value) === '') || $value === []) {
            return $this->fail($field, "$label is required.");
        }
        return $this;
    }

    public function string(string $field, string $label, int $min = 0, int $max = 255): self
    {
        $value = trim((string) $this->raw($field));
        $len   = mb_strlen($value);

        if ($len < $min) {
            return $this->fail($field, $min === 1
                ? "$label is required."
                : "$label must be at least $min characters.");
        }
        if ($len > $max) {
            return $this->fail($field, "$label must be $max characters or fewer.");
        }
        return $this->store($field, $value);
    }

    public function email(string $field, string $label): self
    {
        $value = strtolower(trim((string) $this->raw($field)));
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->fail($field, "$label is not a valid email address.");
        }
        return $this->store($field, $value);
    }

    /** Accepts "1,250.75", "1250" or "\u{20AC}1,250.75". Returns a float. */
    public function money(string $field, string $label, float $min = 0.01, float $max = 99_999_999.0): self
    {
        $value = (float) preg_replace('/[^0-9.\-]/', '', (string) $this->raw($field));

        if ($value < $min) {
            return $this->fail($field, "$label must be at least " . number_format($min, 2) . '.');
        }
        if ($value > $max) {
            return $this->fail($field, "$label is too large.");
        }
        return $this->store($field, round($value, 2));
    }

    public function int(string $field, string $label, ?int $min = null, ?int $max = null): self
    {
        $raw = $this->raw($field);
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $this->fail($field, "$label must be a number.");
        }
        $value = (int) $raw;

        if ($min !== null && $value < $min) {
            return $this->fail($field, "$label must be at least $min.");
        }
        if ($max !== null && $value > $max) {
            return $this->fail($field, "$label must be $max or less.");
        }
        return $this->store($field, $value);
    }

    public function in(string $field, string $label, array $allowed): self
    {
        $value = (string) $this->raw($field);
        if (!in_array($value, $allowed, true)) {
            return $this->fail($field, "$label must be one of: " . implode(', ', $allowed) . '.');
        }
        return $this->store($field, $value);
    }

    public function date(string $field, string $label): self
    {
        $value = trim((string) $this->raw($field));
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            return $this->fail($field, "$label must be a date in YYYY-MM-DD format.");
        }
        return $this->store($field, $value);
    }

    public function dateTime(string $field, string $label): self
    {
        $value = trim((string) $this->raw($field));
        try {
            $d = new DateTime($value);
        } catch (Exception) {
            return $this->fail($field, "$label must be a valid date and time.");
        }
        return $this->store($field, $d->format('Y-m-d H:i:s'));
    }

    /** 1..7 where 1 = Monday (ISO-8601). */
    public function isoDay(string $field, string $label): self
    {
        $value = (int) $this->raw($field);
        if ($value < 1 || $value > 7) {
            return $this->fail($field, "$label must be between 1 (Monday) and 7 (Sunday).");
        }
        return $this->store($field, $value);
    }

    public function boolean(string $field): self
    {
        return $this->store($field, (bool) $this->raw($field));
    }

    /** @param int[] $allowed */
    public function idList(string $field, string $label, array $allowed = []): self
    {
        $raw = $this->raw($field);
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), static fn($v) => trim($v) !== '');
        }
        $raw = is_array($raw) ? $raw : [];

        $ids = [];
        foreach ($raw as $v) {
            if (!is_numeric($v)) {
                return $this->fail($field, "$label contains an invalid id.");
            }
            $id = (int) $v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if ($allowed !== []) {
            $bad = array_diff($ids, $allowed);
            if ($bad !== []) {
                return $this->fail($field, "$label includes someone who is not an active resident: "
                    . implode(', ', $bad) . '.');
            }
        }
        return $this->store($field, $ids);
    }

    /** userId => float weight, e.g. {"1":2,"2":2,"3":1} */
    public function weightMap(string $field, string $label, array $allowed = []): self
    {
        $raw = $this->raw($field);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || $raw === []) {
            return $this->fail($field, "$label must be a non-empty map of resident id to share.");
        }

        $map = [];
        foreach ($raw as $uid => $w) {
            if (!is_numeric($uid) || !is_numeric($w) || (float) $w < 0) {
                return $this->fail($field, "$label has an invalid entry.");
            }
            $uid = (int) $uid;
            if ($allowed !== [] && !in_array($uid, $allowed, true)) {
                return $this->fail($field, "$label references user $uid who is not an active resident.");
            }
            $map[$uid] = round((float) $w, 4);
        }
        if (array_sum($map) <= 0) {
            return $this->fail($field, "$label must contain at least one share greater than zero.");
        }
        return $this->store($field, $map);
    }

    public function password(string $field, string $label): self
    {
        $value = (string) $this->raw($field);
        $errs  = Auth::validatePassword($value);
        if ($errs !== []) {
            return $this->fail($field, "$label: " . implode(' ', $errs));
        }
        return $this->store($field, $value);
    }

    /** Ensure a foreign key exists in the given table. */
    public function exists(string $field, string $label, string $table, int $apartmentId): self
    {
        $value = (int) $this->raw($field);
        $found = Database::value(
            "SELECT 1 FROM `$table` WHERE id = :id AND apartment_id = :a",
            ['id' => $value, 'a' => $apartmentId]
        );
        if ($found === null) {
            return $this->fail($field, "$label is not valid for this apartment.");
        }
        return $this->store($field, $value);
    }

    /** Free-form note, truncated to the column width. */
    public function note(string $field, string $label, int $max = 500): self
    {
        $value = trim((string) $this->raw($field));
        if (mb_strlen($value) > $max) {
            return $this->fail($field, "$label must be $max characters or fewer.");
        }
        return $this->store($field, $value === '' ? null : $value);
    }

    /* ------------------------------------------------------------------ */

    /** Verify the user belongs to the same apartment before acting on them. */
    public function sameApartment(string $field, string $label, int $apartmentId): self
    {
        $value = (int) $this->raw($field);
        $found = Database::value(
            'SELECT 1 FROM users WHERE id = :id AND apartment_id = :a',
            ['id' => $value, 'a' => $apartmentId]
        );
        if ($found === null) {
            return $this->fail($field, "$label is not a member of this apartment.");
        }
        return $this->store($field, $value);
    }

    private function raw(string $field): mixed
    {
        return $this->input[$field] ?? null;
    }

    private function store(string $field, mixed $value): self
    {
        $this->clean[$field] = $value;
        return $this;
    }

    private function fail(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    /* ------------------------------------------------------------------ */

    public function check(): array
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
        return $this->clean;
    }
}

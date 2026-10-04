<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Money — integer-cent arithmetic
 * ---------------------------------------------------------------------------
 * All ledger maths runs on integer cents. Floats never touch a balance, so
 * 0.1 + 0.2 never becomes 0.30000000000000004 and the SUM(split_amount)
 * invariant can be asserted exactly.
 *
 *   Money::toCents('12.34')          -> 1234
 *   Money::toAmount(1234)           -> 12.34
 *   Money::format(1234)             -> '\u{20AC}12.34'
 *   Money::allocate(1000, 3)        -> [334, 333, 333]   (sums to 1000)
 */

declare(strict_types=1);

final class Money
{
    /** Convert a user/DB amount to integer cents. */
    public static function toCents(float|int|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        if (is_int($amount)) {
            return $amount * 100;
        }
        // round() rather than floor() so 4.205 -> 421 (nearest cent, not 420)
        return (int) round(((float) $amount) * 100);
    }

    /** Convert integer cents back to a decimal amount. */
    public static function toAmount(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** Human string, e.g. "\u{20AC}1,234.50". */
    public static function format(int $cents, bool $withSymbol = true): string
    {
        $n = number_format(self::toAmount($cents), 2);
        return $withSymbol ? config('app.currency', '\u{20AC}') . $n : $n;
    }

    /**
     * Split an integer amount into $parts integer shares that sum EXACTLY to
     * $total. Remainder cents are distributed one-per-part to the earliest
     * parts, so the result is deterministic and never loses a cent.
     *
     *   allocate(1000, 3)  => [334, 333, 333]
     *   allocate(100,  6)  => [ 17,  17,  17,  17,  16,  16]
     */
    public static function allocate(int $totalCents, int $parts): array
    {
        if ($parts <= 0) {
            return [];
        }
        $totalCents = (int) $totalCents;          // also guards against negatives
        $base   = intdiv($totalCents, $parts);
        $remainder = $totalCents - ($base * $parts);

        $shares = array_fill(0, $parts, $base);
        for ($i = 0; $i < $remainder; $i++) {
            $shares[$i]++;
        }
        return $shares;
    }

    /**
     * Allocate proportionally to integer weights, preserving the exact total.
     *
     *   weighted(10000, [2, 2, 1, 1, 1, 1])  => [2500, 2500, 1250, 1250, 1250, 1250]
     *
     * Uses largest-remainder (Hamilton) apportionment so the sum is exact even
     * when the weights do not divide the total evenly.
     */
    public static function weighted(int $totalCents, array $weights): array
    {
        $weights = array_values(array_map('floatval', $weights));
        $n       = count($weights);
        if ($n === 0) {
            return [];
        }
        $weightSum = array_sum($weights);
        if ($weightSum <= 0.0) {
            return self::allocate($totalCents, $n);
        }

        $exact   = [];
        $floors  = [];
        $running = 0;
        foreach ($weights as $i => $w) {
            $exact[$i]  = $totalCents * ($w / $weightSum);
            $floors[$i] = (int) floor($exact[$i]);
            $running   += $floors[$i];
        }

        $short = $totalCents - $running;           // 0 .. n-1 cents
        if ($short > 0) {
            // hand the leftover cents to the largest fractional parts first
            $order = range(0, $n - 1);
            usort($order, static fn(int $a, int $b): int =>
                (($exact[$b] - $floors[$b]) <=> ($exact[$a] - $floors[$a])) ?: ($a <=> $b));

            for ($i = 0; $i < $short; $i++) {
                $floors[$order[$i]]++;
            }
        }
        ksort($floors);
        return array_values($floors);
    }

    /** Sign label used across the UI. */
    public static function direction(int $cents): string
    {
        return match (true) {
            $cents > 0  => 'credit',   // the flat owes them
            $cents < 0  => 'debit',    // they owe the flat
            default     => 'settled',
        };
    }
}

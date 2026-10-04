<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Minimal test runner
 * ---------------------------------------------------------------------------
 * A dependency-free stand-in for PHPUnit, so `php tests/<file>.php` works on a
 * bare PHP install with no composer step. Counts assertions and exits non-zero
 * on failure, which is all a CI step needs.
 */

declare(strict_types=1);

final class TestCase
{
    private int $pass = 0;
    private int $fail = 0;
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo PHP_EOL . $name . PHP_EOL;
    }

    public function ok(bool $cond, string $what): void
    {
        if ($cond) {
            $this->pass++;
            echo '  PASS  ' . $what . PHP_EOL;
            return;
        }
        $this->fail++;
        echo '  FAIL  [' . $this->group . '] ' . $what . PHP_EOL;
    }

    public function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected === $actual) {
            $this->pass++;
            echo '  PASS  ' . $what . PHP_EOL;
            return;
        }
        $this->fail++;
        echo '  FAIL  [' . $this->group . '] ' . $what . PHP_EOL
            . '          expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL;
    }

    /** Assert $fn throws, and that the message mentions $needle. */
    public function throws(callable $fn, string $needle, string $what): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $hit = stripos($e->getMessage(), $needle) !== false;
            $this->ok($hit, $what . ($hit ? '' : ' -- got: ' . $e->getMessage()));
            return;
        }
        $this->ok(false, $what . ' -- nothing was thrown');
    }

    public function summary(): void
    {
        echo PHP_EOL . str_repeat('-', 62) . PHP_EOL;
        printf("%d passed, %d failed%s", $this->pass, $this->fail, PHP_EOL);
        exit($this->fail === 0 ? 0 : 1);
    }
}
<?php

namespace Tests\Unit;

use App\Support\Curp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurpTest extends TestCase
{
    public function test_it_normalizes_curp_input(): void
    {
        $this->assertSame('SABC560626MDFLRN01', Curp::normalize(' sabc560626mdflrn01 '));
        $this->assertNull(Curp::normalize('  '));
        $this->assertNull(Curp::normalize(null));
    }

    #[DataProvider('invalidCurps')]
    public function test_it_rejects_invalid_curps(string $curp): void
    {
        $this->assertFalse(Curp::isValid($curp));
    }

    public function test_it_accepts_a_structurally_valid_curp_with_correct_check_digit(): void
    {
        $this->assertTrue(Curp::isValid('SABC560626MDFLRN01'));
        $this->assertTrue(Curp::isValid('PELJ900101HDFRNS01'));
    }

    /** @return array<string, array{string}> */
    public static function invalidCurps(): array
    {
        return [
            'wrong check digit' => ['SABC560626MDFLRN02'],
            'invalid calendar date' => ['SABC560231MDFLRN08'],
            'invalid state code' => ['SABC560626MXXLRN08'],
            'lowercase is not normalized implicitly' => ['sabc560626mdflrn01'],
            'wrong length' => ['SABC560626MDFLRN0'],
        ];
    }
}

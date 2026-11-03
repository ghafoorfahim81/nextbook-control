<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function numbers(): array
    {
        return [
            'local with the leading zero' => ['0791234567', '93791234567'],
            'local without the zero' => ['791234567', '93791234567'],
            'with the country code' => ['93791234567', '93791234567'],
            'with a plus' => ['+93 79 123 4567', '93791234567'],
            'with 00' => ['0093-791-234-567', '93791234567'],
            'spaces and dashes' => ['079 123-4567', '93791234567'],
            'persian digits' => ['۰۷۹۱۲۳۴۵۶۷', '93791234567'],
            'arabic-indic digits' => ['٠٧٩١٢٣٤٥٦٧', '93791234567'],
            'another country, with a plus' => ['+971 50 123 4567', '971501234567'],
            'another country, with 00' => ['00923001234567', '923001234567'],
            'empty' => ['', null],
            'null' => [null, null],
            'only spaces' => ['   ', null],
            'not a number' => ['call me', null],
            'too short' => ['12345', null],
            'too long' => ['+9379123456789012345', null],
            'a plus with too few digits' => ['+9379', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_numbers_are_read_the_way_whatsapp_needs_them_or_refused(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, Phone::e164($raw));
    }

    public function test_the_default_country_can_be_changed(): void
    {
        $this->assertSame('923001234567', Phone::e164('03001234567', '92'));
    }
}

<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use PHPUnit\Framework\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_nothing_set_trusts_no_one(): void
    {
        $this->assertNull(TrustedProxies::fromEnv(null));
        $this->assertNull(TrustedProxies::fromEnv(''));
        $this->assertNull(TrustedProxies::fromEnv('   '));
        $this->assertNull(TrustedProxies::fromEnv(' , ,'));
    }

    public function test_a_star_trusts_everyone_and_is_only_that(): void
    {
        $this->assertSame('*', TrustedProxies::fromEnv('*'));
        $this->assertSame('*', TrustedProxies::fromEnv(' * '));
    }

    public function test_a_list_is_trimmed_and_cleaned(): void
    {
        $this->assertSame(['10.0.0.1'], TrustedProxies::fromEnv('10.0.0.1'));
        $this->assertSame(['10.0.0.1', '10.0.0.0/24', '::1'], TrustedProxies::fromEnv(' 10.0.0.1 , 10.0.0.0/24,, ::1 '));
        $this->assertSame(['10.0.0.1'], TrustedProxies::fromEnv('10.0.0.1,10.0.0.1'), 'a repeat is dropped');
    }
}

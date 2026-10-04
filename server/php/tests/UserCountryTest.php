<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\User;
use Corrai\Utils\WSException;
use PHPUnit\Framework\TestCase;

class UserCountryTest extends TestCase
{
    public function testCountryDefaultsToFrance(): void
    {
        $user = User::from_array([
            'email' => 'a@ind.local',
            'name' => 'Ada',
        ]);

        $this->assertSame('fr', $user->country);
        $this->assertSame('fr', $user->to_output()['country']);
    }

    public function testCountryIsNormalized(): void
    {
        $user = User::from_array([
            'country' => ' DE ',
        ]);

        $this->assertSame('de', $user->country);
    }

    public function testUnknownCountryFallsBackToFrance(): void
    {
        $user = User::from_array([
            'country' => 'zz',
        ]);

        $this->assertSame('fr', $user->country);
    }

    public function testDiscountRateDefaultsToZeroAndRoundTrips(): void
    {
        $missing = User::from_array(['email' => 'a@ind.local', 'name' => 'Ada']);
        $this->assertSame(0, $missing->discount_rate);
        $this->assertSame(0, $missing->to_output()['discount_rate']);

        $user = User::from_array(['discount_rate' => 25]);
        $this->assertSame(25, $user->discount_rate);
        $this->assertSame(40, User::from_array(['discount_rate' => '40'])->discount_rate);
    }

    public function testDiscountRateRejectsValuesOutsideZeroToOneHundred(): void
    {
        $this->expectException(WSException::class);
        User::normalizeDiscountRate(101, true);
    }

    public function testStrictUnknownCountryIsRejected(): void
    {
        $this->expectException(WSException::class);
        User::normalizeCountry('not-a-country', true);
    }
}

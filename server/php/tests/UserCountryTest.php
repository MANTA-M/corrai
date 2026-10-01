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

    public function testStrictUnknownCountryIsRejected(): void
    {
        $this->expectException(WSException::class);
        User::normalizeCountry('not-a-country', true);
    }
}

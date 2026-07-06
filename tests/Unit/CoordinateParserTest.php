<?php

namespace Tests\Unit;

use App\Support\CoordinateParser;
use PHPUnit\Framework\TestCase;

class CoordinateParserTest extends TestCase
{
    public function test_decimal_degrees_comma(): void
    {
        [$lat, $lon] = CoordinateParser::parse('47.20, 11.52');
        $this->assertEqualsWithDelta(47.20, $lat, 0.001);
        $this->assertEqualsWithDelta(11.52, $lon, 0.001);
    }

    public function test_decimal_degrees_slash(): void
    {
        [$lat, $lon] = CoordinateParser::parse('47.104134/ 9.787181');
        $this->assertEqualsWithDelta(47.104134, $lat, 0.001);
        $this->assertEqualsWithDelta(9.787181, $lon, 0.001);
    }

    public function test_utm_northing_easting(): void
    {
        // Dümlerhütte, UTM 33N — should land in Upper Austria (~47.67, 14.28).
        [$lat, $lon] = CoordinateParser::parse('5280260 / 445766');
        $this->assertEqualsWithDelta(47.673, $lat, 0.01);
        $this->assertEqualsWithDelta(14.278, $lon, 0.01);
    }

    public function test_labelled_utm_with_thousands_dots(): void
    {
        [$lat, $lon] = CoordinateParser::parse('UTM Y (Nord) 5.236.808 / UTM X (Ost) 655.466');
        $this->assertGreaterThan(46.0, $lat);
        $this->assertLessThan(49.2, $lat);
        $this->assertGreaterThan(9.0, $lon);
        $this->assertLessThan(17.5, $lon);
    }

    public function test_placeholder_and_empty_return_null(): void
    {
        $this->assertNull(CoordinateParser::parse('XXX.XXX / YYY.YYY'));
        $this->assertNull(CoordinateParser::parse(''));
        $this->assertNull(CoordinateParser::parse(null));
    }
}

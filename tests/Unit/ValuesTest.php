<?php

declare(strict_types=1);

namespace TriCoreDb\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCoreDb\Bytes;
use TriCoreDb\Decimal;
use TriCoreDb\Exception\InvalidValueException;
use TriCoreDb\Filter;
use TriCoreDb\GraphDirection;
use TriCoreDb\Internal\Enums;
use TriCoreDb\Internal\Json;
use TriCoreDb\Internal\Params;
use TriCoreDb\Internal\Wire;

/**
 * How PHP values become wire values.
 */
final class ValuesTest extends TestCase
{
    public function testScalarsGoAsThemselves(): void
    {
        self::assertSame([null, true, false, 7, 1.5, 'ada'], Params::encode([null, true, false, 7, 1.5, 'ada']));
    }

    public function testAStringIsTextAndBytesMustSaySo(): void
    {
        // PHP strings do not know whether they hold text: a password that looks
        // like hex stays a password unless the caller wraps it.
        self::assertSame('0xdeadbeef', Params::encodeOne('0xdeadbeef', 1));
        self::assertSame('0x00ff10', Params::encodeOne(new Bytes("\x00\xff\x10"), 1));
    }

    public function testATimeGoesAsIsoEightSixOneWithItsOffset(): void
    {
        $when = new \DateTimeImmutable('2026-09-16 10:30:15.250000', new \DateTimeZone('+05:30'));

        self::assertSame('2026-09-16T10:30:15.250000+05:30', Params::encodeOne($when, 1));
    }

    public function testNotANumberIsRefusedNamingThePosition(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('parameter #2');
        Params::encode([1, NAN]);
    }

    public function testAFloatThatHasAlreadyLostAnIntegerIsRefused(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('lost precision');
        Params::encodeOne(9007199254740993.0, 1);
    }

    public function testAValueWithNoSqlFormIsRefusedNotStringified(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('parameter #1 is array');
        Params::encode([['nested']]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function decimals(): iterable
    {
        yield 'keeps its scale' => ['1.50', '1.50'];
        yield 'bare fraction' => ['.5', '0.5'];
        yield 'exponent written out' => ['1.5e3', '1500'];
        yield 'negative exponent' => ['-12.5e-3', '-0.0125'];
        yield 'leading zeros dropped' => ['007.10', '7.10'];
        yield 'negative zero is zero' => ['-0.0', '0.0'];
        yield 'many digits kept' => ['123456789012345678901234567890.123', '123456789012345678901234567890.123'];
    }

    #[DataProvider('decimals')]
    public function testADecimalIsSentAsPlainDigits(string $input, string $wire): void
    {
        self::assertSame($wire, Params::encodeOne(new Decimal($input), 1));
    }

    public function testTextThatIsNotADecimalIsRefused(): void
    {
        $this->expectException(InvalidValueException::class);
        new Decimal('ten');
    }

    public function testAnEmptyAssociativeArrayIsAJsonObject(): void
    {
        self::assertSame('{"properties":{}}', Json::encode(['properties' => Json::object([], 'properties')]));
    }

    public function testAListWhereAnObjectIsRequiredIsRefused(): void
    {
        $this->expectException(InvalidValueException::class);
        Json::object(['a', 'b'], 'document');
    }

    public function testAFilterEncodesInTheServersVocabulary(): void
    {
        $filter = Filter::and(Filter::eq('city', 'Pune'), Filter::gt('visits', 4));

        self::assertSame(
            '{"And":[{"Eq":{"field":"city","value":"Pune"}},{"Gt":{"field":"visits","value":4}}]}',
            Json::encode($filter)
        );
        self::assertSame('"All"', Json::encode(Filter::all()));
    }

    public function testBytesRoundTripThroughTheWiresNumberArrays(): void
    {
        $raw = "\x00\x9f\x92\x96";
        self::assertSame([0, 159, 146, 150], Wire::toByteList($raw));
        self::assertSame($raw, Wire::fromByteList([0, 159, 146, 150], 'value'));
    }

    public function testAnEnumAcceptsItsCaseOrItsNameAndNothingElse(): void
    {
        self::assertSame('both', Enums::value(GraphDirection::Both, GraphDirection::class, 'direction'));
        self::assertSame('incoming', Enums::value('incoming', GraphDirection::class, 'direction'));

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('outgoing, incoming, both');
        Enums::value('sideways', GraphDirection::class, 'direction');
    }
}

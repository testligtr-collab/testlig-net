<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Entity\Subject;
use App\Enum\SubjectStatus;
use App\Presentation\ResultPresentation;
use PHPUnit\Framework\TestCase;

final class ResultPresentationTest extends TestCase
{
    private ResultPresentation $presentation;

    protected function setUp(): void
    {
        $this->presentation = new ResultPresentation();
    }

    public function testPercentagesUseTurkishRounding(): void
    {
        self::assertSame('%100', $this->presentation->percent('100.0000'));
        self::assertSame('%50', $this->presentation->percent('50.0000'));
        self::assertSame('%83,33', $this->presentation->percent('83.3333'));
        self::assertSame('%16,67', $this->presentation->percent('16.6666'));
        self::assertSame('%0', $this->presentation->percent('0.0000'));
    }

    public function testPointsDropTrailingZeros(): void
    {
        self::assertSame('5', $this->presentation->points('5.00'));
        self::assertSame('1', $this->presentation->points('1.00'));
        self::assertSame('4,5', $this->presentation->points('4.50'));
        self::assertSame('1,25', $this->presentation->points('1.25'));
        self::assertSame('5 / 5', $this->presentation->points('5.00').' / '.$this->presentation->points('5.00'));
        self::assertSame('4,5 / 5', $this->presentation->points('4.50').' / '.$this->presentation->points('5.00'));
    }

    public function testInstantUsesIstanbulWithoutMutatingTheSource(): void
    {
        $source = new \DateTimeImmutable('2026-10-01 15:46:00', new \DateTimeZone('UTC'));
        $before = $source->format('Y-m-d H:i:s P');
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            self::assertSame('01.10.2026 18:46', $this->presentation->instant($source));
            self::assertSame($before, $source->format('Y-m-d H:i:s P'));
            self::assertSame('UTC', $source->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testSubjectNameStaysSeparateFromTheCode(): void
    {
        $subject = Subject::create('matematik', 'Matematik', 'matematik', 'matematik', new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        self::assertSame('matematik', $subject->getCode());
        self::assertSame('Matematik', $this->presentation->subjectName($subject));
        self::assertSame('matematik', $subject->getCode());
        self::assertSame(SubjectStatus::Active, $subject->getStatus());
        self::assertSame('', $this->presentation->subjectName(null));
    }
}

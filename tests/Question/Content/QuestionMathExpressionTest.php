<?php

declare(strict_types=1);

namespace App\Tests\Question\Content;

use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use App\Question\Content\QuestionContentValidator;
use App\Question\Content\QuestionContentView;
use App\Question\Content\QuestionMathExpression;
use App\Question\Content\QuestionPlainText;
use PHPUnit\Framework\TestCase;

final class QuestionMathExpressionTest extends TestCase
{
    public function testLegacyParagraphStaysReadable(): void
    {
        $document = ['schemaVersion' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Duz metin']]];
        (new QuestionContentValidator())->validate(QuestionContentDocument::fromArray($document));
        self::assertSame(['Duz metin'], QuestionPlainText::lines($document));
    }

    public function testTemplatesParseAndDangerousCommandsFail(): void
    {
        foreach ([
            '\frac{a}{b}',
            '\sqrt{x}',
            'x^{2}',
            'x_{n}',
            '\left(x\right)',
            '\sum_{i=1}^{n}',
            '\int_{a}^{b}',
            '\le',
            '\pi',
            '\angle ABC',
            '90^{\circ}',
            'AB \parallel CD',
            'AB \perp CD',
            '\overline{AB}',
            '\triangle ABC',
            'H_{2}O',
            'Fe^{3+}',
            'A \rightarrow B',
            'A \leftrightarrow B',
            'CO_{2}',
            'a \times 10^{n}',
            '2 + 2 = 4',
            'x < y',
            'x > y',
            '\frac{1}{\frac{2}{3}}',
        ] as $latex) {
            $view = QuestionMathExpression::view($latex);
            self::assertNotSame('', $view['kind'] ?? null, $latex);
        }
        $fraction = QuestionMathExpression::view('\frac{a}{b}');
        self::assertSame('frac', $fraction['kind']);
        foreach (['\href{http://evil}', '\input{secret}', '\includegraphics{a}', '<script>', 'javascript:alert(1)', 'a<b>c', str_repeat('a', 401)] as $unsafe) {
            $accepted = false;
            try {
                QuestionMathExpression::assertSafe($unsafe);
                $accepted = true;
            } catch (\InvalidArgumentException) {
                $accepted = false;
            }
            self::assertFalse($accepted, $unsafe);
        }
    }

    public function testInlineDocumentRoundTripAndMarkupRejection(): void
    {
        $validator = new QuestionContentValidator();
        $document = QuestionContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [[
                'type' => 'paragraph',
                'inlines' => [
                    ['type' => 'text', 'text' => 'Sonuc ', 'marks' => ['bold']],
                    ['type' => 'math', 'latex' => 'x^{2}'],
                ],
            ]],
        ]);
        $validator->validate($document);
        self::assertSame(['Sonuc  x^{2}'], QuestionPlainText::lines($document->toArray()));
        try {
            $validator->validate(QuestionContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [['type' => 'math', 'latex' => '\write18{rm}']],
            ]));
            self::fail('dangerous math was accepted');
        } catch (QuestionException $exception) {
            self::assertSame('Invalid math expression.', $exception->getMessage());
        }
    }

    public function testComparisonSignsStayReadableAndHostileNestingIsRejected(): void
    {
        $validator = new QuestionContentValidator();
        $validator->validate(QuestionContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [['type' => 'paragraph', 'text' => '3 < 5 ve 7 > 2']],
        ]));
        $nested = '1';
        for ($depth = 0; $depth < 8; ++$depth) {
            $nested = '\frac{'.$nested.'}{1}';
        }
        QuestionMathExpression::assertSafe($nested);
        $tooDeep = '\frac{'.$nested.'}{1}';
        try {
            QuestionMathExpression::assertSafe($tooDeep);
            self::fail('deep math was accepted');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $view = QuestionContentView::blocks([
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'paragraph', 'inlines' => [['type' => 'math', 'latex' => 'x < y']]],
                ['type' => 'image_reference', 'mediaId' => '22222222-2222-4222-8222-222222222222'],
            ],
        ], false);
        self::assertCount(1, $view);
        self::assertSame('paragraph', $view[0]['kind']);
        $encoded = json_encode($view);
        self::assertIsString($encoded);
        self::assertStringContainsString('<', $encoded);
        self::assertStringNotContainsString('22222222-2222-4222-8222-222222222222', $encoded);
        self::assertStringNotContainsString('image_reference', $encoded);
    }
}

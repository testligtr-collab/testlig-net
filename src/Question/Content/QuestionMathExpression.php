<?php

declare(strict_types=1);

namespace App\Question\Content;

/**
 * Allowlisted math grammar. Unknown commands and markup are rejected.
 * Rendering returns a view tree; it does not execute the expression.
 *
 * @phpstan-type MathNode array<string, mixed>
 */
final class QuestionMathExpression
{
    public const MAX_LENGTH = 400;

    public static function assertSafe(string $latex): void
    {
        self::parse($latex);
    }

    /**
     * @return MathNode
     */
    public static function view(string $latex): array
    {
        try {
            return self::parse($latex);
        } catch (\InvalidArgumentException) {
            return ['kind' => 'text', 'text' => $latex];
        }
    }

    /**
     * @return MathNode
     */
    private static function parse(string $latex): array
    {
        if (mb_strlen($latex) > self::MAX_LENGTH || 1 === preg_match('/[<>]/', $latex)) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        $parser = new self($latex);
        $node = $parser->sequence(0, false);
        $parser->skip();
        if ($parser->i < $parser->n) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }

        return $node;
    }

    private function __construct(private readonly string $source)
    {
        $this->n = \strlen($source);
    }

    private int $i = 0;
    private readonly int $n;

    /**
     * @return MathNode
     */
    private function sequence(int $depth, bool $group): array
    {
        if ($depth > 8) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        $children = [];
        while ($this->i < $this->n) {
            $this->skip();
            if ($this->i >= $this->n) {
                break;
            }
            $char = $this->source[$this->i];
            if ($group && ('}' === $char || ')' === $char || ']' === $char)) {
                break;
            }
            if ($this->startsWith('\\right')) {
                break;
            }
            if (' ' === $char || "\n" === $char || "\t" === $char) {
                ++$this->i;
                $children[] = ['kind' => 'text', 'text' => ' '];
                continue;
            }
            $children[] = $this->scripts($this->atom($depth), $depth);
        }
        if ([] === $children) {
            return ['kind' => 'text', 'text' => ''];
        }
        if (1 === \count($children)) {
            return $children[0];
        }

        return ['kind' => 'row', 'children' => $children];
    }

    /**
     * @param MathNode $base
     *
     * @return MathNode
     */
    private function scripts(array $base, int $depth): array
    {
        while ($this->i < $this->n) {
            $this->skip();
            if ($this->i >= $this->n) {
                break;
            }
            $char = $this->source[$this->i];
            if ('^' !== $char && '_' !== $char) {
                break;
            }
            ++$this->i;
            $script = $this->scriptBody($depth);
            $base = [
                'kind' => '^' === $char ? 'sup' : 'sub',
                'base' => $base,
                'exp' => $script,
            ];
        }

        return $base;
    }

    /**
     * @return MathNode
     */
    private function scriptBody(int $depth): array
    {
        $this->skip();
        if ($this->i >= $this->n) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        if ('{' === $this->source[$this->i]) {
            return $this->group($depth);
        }

        return $this->atom($depth);
    }

    /**
     * @return MathNode
     */
    private function atom(int $depth): array
    {
        $this->skip();
        if ($this->i >= $this->n) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        $char = $this->source[$this->i];
        if ('{' === $char) {
            return $this->group($depth);
        }
        if ('(' === $char || '[' === $char) {
            return $this->delimited($depth, $char, '(' === $char ? ')' : ']');
        }
        if ('\\' === $char) {
            return $this->command($depth);
        }
        if (1 === preg_match('/[A-Za-z0-9]/', $char)) {
            $start = $this->i;
            while ($this->i < $this->n && 1 === preg_match('/[A-Za-z0-9]/', $this->source[$this->i])) {
                ++$this->i;
            }

            return ['kind' => 'text', 'text' => substr($this->source, $start, $this->i - $start)];
        }
        if (\in_array($char, ['+', '-', '=', ',', '.', '/', '|'], true)) {
            ++$this->i;

            return ['kind' => 'text', 'text' => $char];
        }

        throw new \InvalidArgumentException('Unsafe math expression.');
    }

    /**
     * @return MathNode
     */
    private function group(int $depth): array
    {
        if ($this->i >= $this->n || '{' !== $this->source[$this->i]) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        ++$this->i;
        $body = $this->sequence($depth + 1, true);
        $this->skip();
        if ($this->i >= $this->n || '}' !== $this->source[$this->i]) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        ++$this->i;

        return $body;
    }

    /**
     * @return MathNode
     */
    private function delimited(int $depth, string $open, string $close): array
    {
        ++$this->i;
        $body = $this->sequence($depth + 1, true);
        $this->skip();
        if ($this->i >= $this->n || $close !== $this->source[$this->i]) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        ++$this->i;

        return [
            'kind' => 'row',
            'children' => [
                ['kind' => 'text', 'text' => $open],
                $body,
                ['kind' => 'text', 'text' => $close],
            ],
        ];
    }

    /**
     * @return MathNode
     */
    private function command(int $depth): array
    {
        ++$this->i;
        $start = $this->i;
        while ($this->i < $this->n && 1 === preg_match('/[A-Za-z]/', $this->source[$this->i])) {
            ++$this->i;
        }
        if ($start === $this->i) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        $name = substr($this->source, $start, $this->i - $start);

        return match ($name) {
            'frac' => [
                'kind' => 'frac',
                'num' => $this->group($depth),
                'den' => $this->group($depth),
            ],
            'sqrt' => ['kind' => 'sqrt', 'body' => $this->group($depth)],
            'overline' => ['kind' => 'over', 'body' => $this->group($depth)],
            'left' => $this->leftRight($depth),
            'sum' => ['kind' => 'text', 'text' => '∑'],
            'int' => ['kind' => 'text', 'text' => '∫'],
            'pi' => ['kind' => 'text', 'text' => 'π'],
            'alpha' => ['kind' => 'text', 'text' => 'α'],
            'beta' => ['kind' => 'text', 'text' => 'β'],
            'gamma' => ['kind' => 'text', 'text' => 'γ'],
            'theta' => ['kind' => 'text', 'text' => 'θ'],
            'Delta' => ['kind' => 'text', 'text' => 'Δ'],
            'le' => ['kind' => 'text', 'text' => '≤'],
            'ge' => ['kind' => 'text', 'text' => '≥'],
            'neq' => ['kind' => 'text', 'text' => '≠'],
            'times' => ['kind' => 'text', 'text' => '×'],
            'cdot' => ['kind' => 'text', 'text' => '·'],
            'pm' => ['kind' => 'text', 'text' => '±'],
            'degree', 'circ' => ['kind' => 'text', 'text' => '°'],
            'angle' => ['kind' => 'text', 'text' => '∠'],
            'triangle' => ['kind' => 'text', 'text' => '△'],
            'parallel' => ['kind' => 'text', 'text' => '∥'],
            'perp' => ['kind' => 'text', 'text' => '⊥'],
            'rightarrow', 'to' => ['kind' => 'text', 'text' => '→'],
            'leftrightarrow' => ['kind' => 'text', 'text' => '⇌'],
            'infty' => ['kind' => 'text', 'text' => '∞'],
            default => throw new \InvalidArgumentException('Unsafe math expression.'),
        };
    }

    /**
     * @return MathNode
     */
    private function leftRight(int $depth): array
    {
        $this->skip();
        if ($this->i >= $this->n || !\in_array($this->source[$this->i], ['(', '['], true)) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        $open = $this->source[$this->i];
        ++$this->i;
        $body = $this->sequence($depth + 1, false);
        if (!$this->startsWith('\\right')) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        $this->i += 6;
        $this->skip();
        $close = '(' === $open ? ')' : ']';
        if ($this->i >= $this->n || $close !== $this->source[$this->i]) {
            throw new \InvalidArgumentException('Unsafe math expression.');
        }
        ++$this->i;

        return [
            'kind' => 'row',
            'children' => [
                ['kind' => 'text', 'text' => $open],
                $body,
                ['kind' => 'text', 'text' => $close],
            ],
        ];
    }

    private function skip(): void
    {
        while ($this->i < $this->n && \in_array($this->source[$this->i], [' ', "\n", "\t"], true)) {
            ++$this->i;
        }
    }

    private function startsWith(string $needle): bool
    {
        return str_starts_with(substr($this->source, $this->i), $needle);
    }
}

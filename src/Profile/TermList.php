<?php

declare(strict_types=1);

namespace TgJobParser\Profile;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, Term> */
final class TermList implements IteratorAggregate, Countable
{
    /** @param list<Term> $terms */
    public function __construct(public readonly array $terms = [])
    {
    }

    /** @param iterable<mixed> $definitions */
    public static function from(iterable $definitions): self
    {
        $terms = [];
        foreach ($definitions as $definition) {
            $terms[] = Term::from($definition);
        }

        return new self($terms);
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_map(static fn (Term $t): string => $t->label, $this->terms);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->terms);
    }

    public function count(): int
    {
        return count($this->terms);
    }
}

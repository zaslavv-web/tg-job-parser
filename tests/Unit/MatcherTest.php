<?php

declare(strict_types=1);

namespace TgJobParser\Tests\Unit;

use TgJobParser\Profile\Matcher;
use TgJobParser\Tests\TestCase;

final class MatcherTest extends TestCase
{
    public function testWordBoundaries(): void
    {
        $text = Matcher::normalize('Ищем PM в команду, опыт с ML');
        $this->assertTrue(Matcher::matchesPattern('pm', $text));
        $this->assertTrue(Matcher::matchesPattern('ml', $text));
        $this->assertFalse(Matcher::matchesPattern('ai', Matcher::normalize('Mailchimp и email')), '«ai» не должно находиться внутри слов');
    }

    public function testWildcardAndYo(): void
    {
        $text = Matcher::normalize('Полная УДАЛЁНКА, можно из любой точки');
        $this->assertTrue(Matcher::matchesPattern('удален*', $text));
        $this->assertTrue(Matcher::matchesPattern('удалёнка', $text));
        $this->assertFalse(Matcher::matchesPattern('удален', $text), 'без * — только целое слово');
    }

    public function testSpecialCharactersInPatterns(): void
    {
        $this->assertTrue(Matcher::matchesPattern('#remote', Matcher::normalize('#job #remote')));
        $this->assertTrue(Matcher::matchesPattern('p&l', Matcher::normalize('Отвечает за P&L продукта')));
        $this->assertTrue(Matcher::matchesPattern('b2b saas', Matcher::normalize('Мы — B2B   SaaS')));
    }

    public function testRegexPatternsAndInvalidRegexIsHarmless(): void
    {
        $this->assertTrue(Matcher::matchesPattern('/\d+\s?лет/', Matcher::normalize('опыт 5 лет')));
        $this->assertFalse(Matcher::isValidPattern('/(unclosed/'));
        $this->assertFalse(Matcher::matchesPattern('/(unclosed/', 'anything'));
    }
}

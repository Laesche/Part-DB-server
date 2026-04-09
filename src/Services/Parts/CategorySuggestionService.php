<?php

declare(strict_types=1);

namespace App\Services\Parts;

use App\Entity\Parts\Category;
use Doctrine\ORM\EntityManagerInterface;

final class CategorySuggestionService
{
    /** @var Category[]|null */
    private ?array $categories = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function guessCategoryPathFromTexts(?string ...$texts): ?string
    {
        $haystack = $this->normalizeText(implode(' ', array_filter($texts, static fn (?string $text): bool => is_string($text) && trim($text) !== '')));
        if ($haystack === '') {
            return null;
        }

        $words = $this->extractWords($haystack);
        if ($words === []) {
            return null;
        }

        $bestCategory = null;
        $bestScore = 0;
        $secondScore = 0;

        foreach ($this->getSelectableCategories() as $category) {
            $score = $this->scoreCategory($category, $haystack, $words);
            if ($score > $bestScore) {
                $secondScore = $bestScore;
                $bestScore = $score;
                $bestCategory = $category;
            } elseif ($score > $secondScore) {
                $secondScore = $score;
            }
        }

        if (!$bestCategory instanceof Category || $bestScore < 24) {
            return null;
        }

        if ($secondScore > 0 && $bestScore < $secondScore + 8 && $bestScore < 80) {
            return null;
        }

        return $bestCategory->getFullPath(' -> ');
    }

    /**
     * @return Category[]
     */
    private function getSelectableCategories(): array
    {
        if ($this->categories === null) {
            $this->categories = array_values(array_filter(
                $this->em->getRepository(Category::class)->findAll(),
                static fn (mixed $category): bool => $category instanceof Category && !$category->isNotSelectable()
            ));
        }

        return $this->categories;
    }

    /**
     * @param array<string, true> $words
     */
    private function scoreCategory(Category $category, string $haystack, array $words): int
    {
        $bestScore = 0;
        foreach ($this->getCandidatePhrases($category) as $phrase) {
            $normalizedPhrase = $this->normalizeText($phrase);
            if ($normalizedPhrase === '') {
                continue;
            }

            if (str_contains(' ' . $haystack . ' ', ' ' . $normalizedPhrase . ' ')) {
                $bestScore = max($bestScore, 120 + strlen($normalizedPhrase));
            }

            $tokens = array_keys($this->extractWords($normalizedPhrase));
            if ($tokens === []) {
                continue;
            }

            $matchedTokens = [];
            foreach ($tokens as $token) {
                if (isset($words[$token])) {
                    $matchedTokens[] = $token;
                }
            }

            $matchedCount = count($matchedTokens);
            if ($matchedCount === 0) {
                continue;
            }

            $lengthScore = array_sum(array_map(static fn (string $token): int => strlen($token), $matchedTokens));

            if ($matchedCount === count($tokens) && $matchedCount >= 2) {
                $bestScore = max($bestScore, 60 + $lengthScore);
                continue;
            }

            if ($matchedCount >= 2) {
                $bestScore = max($bestScore, 24 + ($matchedCount * 8) + intdiv($lengthScore, 2));
                continue;
            }

            if (strlen($matchedTokens[0]) >= 7) {
                $bestScore = max($bestScore, 10 + strlen($matchedTokens[0]));
            }
        }

        return $bestScore;
    }

    /**
     * @return string[]
     */
    private function getCandidatePhrases(Category $category): array
    {
        $phrases = [$category->getName()];
        $alternativeNames = $category->getAlternativeNames();
        if (is_string($alternativeNames) && trim($alternativeNames) !== '') {
            $phrases = array_merge($phrases, preg_split('/[;,\r\n]+/', $alternativeNames) ?: []);
        }

        return array_values(array_filter(array_map(
            static fn (string $phrase): string => trim($phrase),
            $phrases
        ), static fn (string $phrase): bool => $phrase !== ''));
    }

    /**
     * @return array<string, true>
     */
    private function extractWords(string $text): array
    {
        $parts = preg_split('/\s+/', $text) ?: [];
        $words = [];
        foreach ($parts as $part) {
            $word = trim($part);
            if (strlen($word) < 3 || isset(self::STOP_WORDS[$word])) {
                continue;
            }

            $words[$word] = true;
        }

        return $words;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return $text;
    }

    private const STOP_WORDS = [
        'and' => true,
        'der' => true,
        'die' => true,
        'das' => true,
        'ein' => true,
        'eine' => true,
        'for' => true,
        'mit' => true,
        'the' => true,
        'und' => true,
        'with' => true,
    ];
}

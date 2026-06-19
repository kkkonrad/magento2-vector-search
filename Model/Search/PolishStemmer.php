<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

class PolishStemmer
{
    /**
     * Remove noun endings.
     */
    private static function removeNouns(string $word): string
    {
        $len = mb_strlen($word);
        if ($len > 7 && in_array(mb_substr($word, -5), ["zacja", "zacją", "zacji"], true)) {
            return mb_substr($word, 0, -4);
        }
        if ($len > 6 && in_array(mb_substr($word, -4), ["acja", "acji", "acją", "tach", "anie", "enie", "eniu", "aniu"], true)) {
            return mb_substr($word, 0, -4);
        }
        if ($len > 6 && mb_substr($word, -4) === "tyka") {
            return mb_substr($word, 0, -2);
        }
        if ($len > 5 && in_array(mb_substr($word, -3), ["ach", "ami", "nia", "niu", "cia", "ciu"], true)) {
            return mb_substr($word, 0, -3);
        }
        if ($len > 5 && in_array(mb_substr($word, -3), ["cji", "cja", "cją"], true)) {
            return mb_substr($word, 0, -2);
        }
        if ($len > 5 && in_array(mb_substr($word, -2), ["ce", "ta"], true)) {
            return mb_substr($word, 0, -2);
        }
        return $word;
    }

    /**
     * Remove diminutive endings.
     */
    private static function removeDiminutive(string $word): string
    {
        $len = mb_strlen($word);
        if ($len > 6) {
            if (in_array(mb_substr($word, -5), ["eczek", "iczek", "iszek", "aszek", "uszek"], true)) {
                return mb_substr($word, 0, -5);
            }
            if (in_array(mb_substr($word, -4), ["enek", "ejek", "erek"], true)) {
                return mb_substr($word, 0, -2);
            }
        }
        if ($len > 4) {
            if (in_array(mb_substr($word, -2), ["ek", "ak"], true)) {
                return mb_substr($word, 0, -2);
            }
        }
        return $word;
    }

    /**
     * Remove adjective endings.
     */
    private static function removeAdjectiveEnds(string $word): string
    {
        $len = mb_strlen($word);
        if ($len > 7 && mb_substr($word, 0, 3) === "naj" && in_array(mb_substr($word, -3), ["sze", "szy"], true)) {
            return mb_substr($word, 3, -3);
        }
        if ($len > 7 && mb_substr($word, 0, 3) === "naj" && mb_substr($word, 0, 5) === "szych") {
            return mb_substr($word, 3, -5);
        }
        if ($len > 6 && mb_substr($word, -4) === "czny") {
            return mb_substr($word, 0, -4);
        }
        if ($len > 5 && in_array(mb_substr($word, -3), ["owy", "owa", "owe", "ych", "ego", "emu"], true)) {
            return mb_substr($word, 0, -3);
        }
        if ($len > 5 && mb_substr($word, -2) === "ej") {
            return mb_substr($word, 0, -2);
        }
        return $word;
    }

    /**
     * Remove verb endings.
     */
    private static function removeVerbsEnds(string $word): string
    {
        $len = mb_strlen($word);
        if ($len > 5 && mb_substr($word, -3) === "bym") {
            return mb_substr($word, 0, -3);
        }
        if ($len > 5 && in_array(mb_substr($word, -3), ["esz", "asz", "cie", "eść", "aść", "łem", "amy", "emy"], true)) {
            return mb_substr($word, 0, -3);
        }
        if ($len > 3 && in_array(mb_substr($word, -3), ["esz", "asz", "eść", "aść", "eć", "ać"], true)) {
            return mb_substr($word, 0, -2);
        }
        if ($len > 3 && in_array(mb_substr($word, -2), ["aj"], true)) {
            return mb_substr($word, 0, -1);
        }
        if ($len > 3 && in_array(mb_substr($word, -2), ["ać", "em", "am", "ał", "ił", "ić", "ąc"], true)) {
            return mb_substr($word, 0, -2);
        }
        return $word;
    }

    /**
     * Remove adverb endings.
     */
    private static function removeAdverbsEnds(string $word): string
    {
        $len = mb_strlen($word);
        if ($len > 4 && in_array(mb_substr($word, -3), ["nie", "wie", "rze"], true)) {
            return mb_substr($word, 0, -2);
        }
        return $word;
    }

    /**
     * Remove plural endings.
     */
    private static function removePluralForms(string $word): string
    {
        $len = mb_strlen($word);
        if ($len > 4 && in_array(mb_substr($word, -2), ["ów", "om"], true)) {
            return mb_substr($word, 0, -2);
        }
        if ($len > 4 && mb_substr($word, -3) === "ami") {
            return mb_substr($word, 0, -3);
        }
        if ($len > 5 && mb_substr($word, -4) === "owie") {
            return mb_substr($word, 0, -4);
        }
        return $word;
    }

    /**
     * Remove general inflection case endings.
     */
    private static function removeGeneralEnds(string $word): string
    {
        $len = mb_strlen($word);
        if ($len > 4 && in_array(mb_substr($word, -2), ["ia", "ie"], true)) {
            return mb_substr($word, 0, -2);
        }
        if ($len > 4 && in_array(mb_substr($word, -1), ["u", "ą", "i", "a", "ę", "y", "ł", "e"], true)) {
            return mb_substr($word, 0, -1);
        }
        return $word;
    }

    /**
     * In-process cache of stemmed words.
     * @var array<string, string>
     */
    private array $stemCache = [];

    /**
     * Stems a single Polish word.
     */
    public function stem(string $word): string
    {
        $word = mb_strtolower($word);
        if (isset($this->stemCache[$word])) {
            return $this->stemCache[$word];
        }
        $stem = $word;
        $stem = self::removeNouns($stem);
        $stem = self::removeDiminutive($stem);
        $stem = self::removePluralForms($stem);
        $stem = self::removeAdjectiveEnds($stem);
        $stem = self::removeVerbsEnds($stem);
        $stem = self::removeAdverbsEnds($stem);
        $stem = self::removeGeneralEnds($stem);
        return $this->stemCache[$word] = $stem;
    }


    /**
     * Stems all Polish words in a given text while preserving punctuation and spacing.
     */
    public function stemText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $words = preg_split('/([^\p{L}\p{N}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $stemmed = [];
        foreach ($words as $word) {
            if (preg_match('/^\p{L}+$/u', $word)) {
                $stemmed[] = $this->stem($word);
            } else {
                $stemmed[] = $word;
            }
        }
        return implode('', $stemmed);
    }
}

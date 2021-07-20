<?php

namespace S25\DomTrawler {

    abstract class XPath
    {
        public static function setUp(\DOMXPath $xpath)
        {
            $xpath->registerNamespace("php", "http://php.net/xpath");
            $xpath->registerPhpFunctions(
                [
                    self::class . '::funcSameLocalNameCount',
                    self::class . '::funcSameLocalNameExists',
                    self::class . '::funcNthOfType'
                ]
            );
        }

        /**
         * @param string $selector
         * @return string
         * @throws \Exception
         */
        public static function fromSelector(string $selector): string
        {
            // Удаляем начальные и концевые пробелы
            $selector = preg_replace('/^\s+|\s+$/u', '', $selector);

            $query = new XPathQuery();

            while ($selector) {
                if (
                    self::parseTag($selector, $query)
                    ||
                    self::parseId($selector, $query)
                    ||
                    self::parseClass($selector, $query)
                    ||
                    self::parseAttribute($selector, $query)
                    ||
                    self::parsePseudoClass($selector, $query)
                    ||
                    self::parseComma($selector, $query)
                    ||
                    self::parseAdjacentSibling($selector, $query)
                    ||
                    self::parseGeneralSibling($selector, $query)
                    ||
                    self::parseChild($selector, $query)
                    ||
                    self::parseDescendant($selector, $query)
                ) {
                    continue;
                }

                throw new \Exception(sprintf("Unsupported selector: «%s»", mb_substr($selector, 0, 30, 'utf-8')));
            }

            return $query->toString();
        }

        // region Parsers

        private static function parseTag(string &$selector, XPathQuery $query): bool
        {
            // tag HTML тэг
            if (preg_match('/^([*]|[\w-]+)/ui', $selector, $match) !== 1) {
                return false;
            }

            $query->addSelector("self::{$match[0]}");
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        private static function parseId(string &$selector, XPathQuery $query): bool
        {
            // #id HTML идентификатор
            if (preg_match('/^#([\w-]+)/ui', $selector, $match) !== 1) {
                return false;
            }

            $query->addSelector("self::*[@id='{$match[1]}']");
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        private static function parseClass(string &$selector, XPathQuery $query): bool
        {
            // .css-class CSS класс
            if (preg_match('/^\.([\w-]+)/ui', $selector, $match) !== 1) {
                return false;
            }

            $query->addSelector("self::*[contains(concat(' ', normalize-space(@class), ' '), ' {$match[1]} ')]");
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        /**
         * @param string $selector
         * @param XPathQuery $query
         * @return bool
         * @throws \Exception
         */
        private static function parseAttribute(string &$selector, XPathQuery $query): bool
        {
            /**
             * [attr]        Represents an element with an attribute name of attr.
             * [attr=value]  Represents an element with an attribute name of attr and whose value is exactly "value".
             * [attr~=value] Represents an element with an attribute name of attr
             *   whose value is a whitespace-separated list of words, one of which is exactly "value".
             * [attr|=value] Represents an element with an attribute name of attr.
             *   Its value can be exactly “value” or can begin with “value” immediately followed by “-” (U+002D).
             *   It can be used for language subcode matches.
             * [attr^=value] Represents an element with an attribute name of attr and whose first value is prefixed by "value".
             * [attr$=value] Represents an element with an attribute name of attr and whose last value is suffixed by "value".
             * [attr*=value] Represents an element with an attribute name of attr
             *   and whose value contains at least one occurrence of string "value" as substring.
             * [attr operator value i]
             */
            $pcre = <<<PCRE
/^(?J) \[\s*
  (?<name>[\w-]+)
  \s*
  (?:
    (?<operator>[~|^$*]?=)
    \s*
    (?: "(?<value>[^"]*)" | '(?<value>[^']*)' | (?<value>\w+) )
    (?<ignore_case>\s* i)?
  )?
\s*\] /xui
PCRE;
            if (preg_match($pcre, $selector, $match) !== 1) {
                return false;
            }

            $name = '@' . $match['name'];
            $operator = $match['operator'] ?? 'none';
            $value = $match['value'] ?? null;
            $ignoreCase = boolval($match['ignore_case'] ?? false);

            if ($ignoreCase) {
                $transTable = self::extractCaseSensitiveChars($value);

                if ($transTable) {
                    $name = sprintf(
                        'translate(%s,"%s","%s")',
                        $name,
                        join(array_keys($transTable)),
                        join(array_values($transTable))
                    );
                    $value = mb_strtolower($value, 'utf8');
                }
            }

            if (is_string($value)) {
                $value = self::escape($value);
            }

            $expr = 'self::*';

            switch ($operator) {
                case 'none':
                    $expr .= "[$name]";
                    break;
                case '=':
                    $expr .= "[$name=$value]";
                    break;
                case '^=':
                    $expr .= "[starts-with($name, $value)]";
                    break;
                case '$=':
                    $expr .= "[substring($name, string-length($name) - string-length($value) + 1) = $value]";
                    break;
                case '*=':
                    $expr .= "[contains($name, $value)]";
                    break;
                default:
                    throw new \Exception(
                        sprintf("Unsupported attribute operator: «%s»", mb_substr($selector, 0, 30, 'utf-8'))
                    );
            }

            $query->addSelector($expr);
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        /**
         * @param string $selector
         * @param XPathQuery $query
         * @return bool
         * @throws \Exception
         */
        private static function parsePseudoClass(string &$selector, XPathQuery $query): bool
        {
            if (preg_match('/^:([\w-]+)/', $selector, $match) !== 1) {
                return false;
            }

            if (
                self::parseFirstLastOnly($selector, $query)
                ||
                self::parseNthChild($selector, $query)
            ) {
                return true;
            }

            throw new \Exception(sprintf("Unsupported pseudo-class: «%s»", mb_substr($selector, 0, 30, 'utf-8')));
        }

        private static function parseFirstLastOnly(string &$selector, XPathQuery $query)
        {
            // Первый, последний или единственный "отпрыск"
            if (preg_match('/^:(first|last|only)-(child|of-type)/ui', $selector, $match) !== 1) {
                return false;
            }

            [, $side, $mode] = $match;
            $first = $side === 'first' || $side === 'only' ? 'preceding-sibling::*' : '';
            $last = $side === 'last' || $side === 'only' ? 'following-sibling::*' : '';
            if ($mode === 'of-type') {
                $first = $first ? sprintf(
                    "php:function('%s::funcSameLocalNameExists', self::*, $first)",
                    self::class
                ) : '';
                $last = $last ? sprintf(
                    "php:function('%s::funcSameLocalNameExists', self::*, $last)",
                    self::class
                ) : '';
            }
            $first = $first ? "[not($first)]" : '';
            $last = $last ? "[not($last)]" : '';

            $query->addSelector("self::*$first$last");
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        private static function parseNthChild(string &$selector, XPathQuery $query)
        {
            $pcre = <<<PCRE
/^ (?J)
    :nth(?<side>-last)?-(?<mode>child|of-type)
    \(\s*(
      (?<word>even|odd)
      |
      (?<a>[+-]?\d*)n
      |
      (?<b>[+-]?\d+)
      |
      (?<a>[+-]?\d*)n \s* (?<s>[+-]) \s* (?<b>\d+)
    )\s*\)
/xui
PCRE;
            if (preg_match($pcre, $selector, $match) !== 1) {
                return false;
            }

            // Далее функция всегда возвращает true, заранее удаляем из селектора обработанную часть строки
            $selector = self::cutOff($selector, $match[0]);

            switch ($match['word'] ?? 'an_b') {
                case 'odd':
                    [$a, $b] = [2, 1];
                    break;
                case 'even':
                    [$a, $b] = [2, 0];
                    break;
                default:
                    $a = intval($match['a']);
                    $b = intval(($match['s'] ?? '') . ($match['b'] ?? ''));
                    break;
            }

            $axis = ($match['side'] ?? '') !== '-last' ? 'preceding-sibling' : 'following-sibling';

            if ($match['mode'] === 'of-type') {
                $query->addSelector(
                    sprintf("self::*[php:function('%s::nthOfType', self::*, $axis::*, $a, $b)]", self::class)
                );
                return true;
            }

            if ($a === 0) {
                $b = strval($b - 1);
                $query->addSelector("self::*[count($axis::*) = $b]");
                return true;
            }

            // a * n + b > 0 where n = 1, 2, 3...

            // (a-b) / n >= 0 and (a-b) % n == 0

            //    (a-b) % n == 0
            //  and
            //      x >= b if a > 0
            //    or
            //      x <= b if a < 0

            if ($b === 0) {
                $query->addSelector(
                    $a < 0
                        ? 'self::*[false()]' // Селектор -Xa + 0, где X = 1, 2.. не выбирает ничего;
                        : "self::*[(count($axis::*)+1) mod $a = 0]"
                );
                return true;
            }

            $minusC = -$b + 1;
            $minusC = strval($minusC > 0 ? '+' . $minusC : ($minusC === 0 ? '' : $minusC));

            $query->addSelector(
                join(
                    [
                        "self::*[(count($axis::*){$minusC}) mod $a = 0]",
                        $a > 0 ? "[(count($axis::*){$minusC}) >= 0]" : "[(count($axis::*){$minusC}) <= 0]"
                    ]
                )
            );
            return true;
        }

        private static function parseComma(string &$selector, XPathQuery $query): bool
        {
            // * , * Или
            if (preg_match('/^\s*[,]\s*/ui', $selector, $match) !== 1) {
                return false;
            }
            $query->nextList();
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        private static function parseAdjacentSibling(string &$selector, XPathQuery $query): bool
        {
            // * + * Первый следующий элемент
            if (preg_match('/^\s*[+]\s*/ui', $selector, $match) !== 1) {
                return false;
            }

            $query->addCombinator('following-sibling::*[1]');
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        private static function parseGeneralSibling(string &$selector, XPathQuery $query): bool
        {
            // * ~ * Поледующие элементы с общим родителем
            if (preg_match('/^\s*[~]\s*/ui', $selector, $match) !== 1) {
                return false;
            }

            $query->addCombinator('following-sibling::*');
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        private static function parseChild(string &$selector, XPathQuery $query): bool
        {
            // * > * Дочерние элементы
            if (preg_match('/^\s*[>](?!>)\s*/ui', $selector, $match) !== 1) {
                return false;
            }
            $query->addCombinator('child::*');
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        private static function parseDescendant(string &$selector, XPathQuery $query): bool
        {
            // * * или * >> * Потомоки
            if (preg_match('/^(\s*[>][>]\s*|\s+)/ui', $selector, $match) !== 1) {
                return false;
            }
            $query->addCombinator('descendant-or-self::*');
            $selector = self::cutOff($selector, $match[0]);
            return true;
        }

        // endregion Parsers

        // region Registered functions

        /**
         * @param \DOMNode[] $contextNodes
         * @param \DOMNode[] $nodes
         * @return int
         */
        public static function funcSameLocalNameCount(array $contextNodes, array $nodes): int
        {
            if (count($contextNodes) !== 1) {
                return false;
            }
            $localName = $contextNodes[0]->localName;
            $count = 0;
            foreach ($nodes as $node) {
                if ($node->localName === $localName) {
                    $count++;
                }
            }
            return $count;
        }

        /**
         * @param \DOMNode[] $contextNodes
         * @param \DOMNode[] $nodes
         * @return bool
         */
        public static function funcSameLocalNameExists(array $contextNodes, array $nodes): bool
        {
            if (count($contextNodes) !== 1) {
                return false;
            }
            $localName = $contextNodes[0]->localName;

            foreach ($nodes as $node) {
                if ($node->localName === $localName) {
                    return true;
                }
            }
            return false;
        }

        /**
         * @param \DOMNode[] $contextNodes
         * @param \DOMNode[] $nodes
         * @param mixed $a
         * @param mixed $b
         * @return bool
         */
        public static function funcNthOfType(array $contextNodes, array $nodes, $a, $b): bool
        {
            $index = self::funcSameLocalNameCount($contextNodes, $nodes) + 1;
            $a = intval($a);
            $b = intval($b);
            $diff = $index - $b;
            return $diff / $a >= 0 && $diff % $a === 0;
        }

        // endregion Registered functions

        private static function cutOff(string $string, string $pattern): string
        {
            return mb_substr($string, mb_strlen($pattern, 'utf-8'), null, 'utf-8');
        }

        /**
         * Выбирает чувствительные к регистру символы и возвращает ассоциативный массив,
         * где ключ - символ в верхнем регистре, значение - символ в нижнем регистре.
         *
         * @param $string
         * @return array
         */
        private static function extractCaseSensitiveChars(string $string): array
        {
            if (strlen($string) === 0) {
                return [];
            }

            $table = array_filter(
                array_combine(
                    preg_split('/(?<!^)(?!$)/u', mb_strtoupper($string, 'utf8')),
                    preg_split('/(?<!^)(?!$)/u', mb_strtolower($string, 'utf8'))
                ),
                function ($char, $lowerChar) {
                    return $char !== strval($lowerChar);
                },
                ARRAY_FILTER_USE_BOTH
            );

            return $table;
        }

        /**
         * Экранирует символы ' и " в строке
         * В XPath 1.0 есть только один способ экранировать строки содержащие оба символа - через функцию concat()
         * @param $string
         * @return string
         */
        public static function escape(string $string): string
        {
            $pieces = preg_split('/([\'"])/u', $string, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

            $quote = null;
            $buffer = '';
            $bufferArray = [];

            foreach ($pieces as $piece) {
                if ($piece === '"' || $piece === "'") {
                    if ($quote === null || $quote === $piece) {
                        $quote = $piece;
                        $buffer .= $piece;
                    } else {
                        $bufferArray[] = $piece . $buffer . $piece;
                        $buffer = $piece;
                        $quote = $piece;
                    }
                } else {
                    $buffer .= $piece;
                }
            }

            if (strlen($buffer)) {
                $quote = $quote ?? '"';
                $opposite = $quote === '"' ? "'" : '"';
                $bufferArray[] = $opposite . $buffer . $opposite;
            } else {
                return "''";
            }

            return count($bufferArray) > 1 ? 'concat(' . implode(',', $bufferArray) . ')' : $bufferArray[0];
        }
    }
}

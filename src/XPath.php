<?php

namespace S25\DomTrawler
{
  abstract class XPath
  {
    /**
     * @param string $selector
     * @return string
     * @throws \Exception
     */
    public static function selectorToQuery(string $selector): string
    {
      // Удаляем начальные и концевые пробелы
      $selector = preg_replace('/^\s+|\s+$/u', '', $selector);

      $alters = [];
      $query = [];

      while ($selector)
      {
        if (
          self::parseTag($selector, $query)
          ||
          self::parseId($selector, $query)
          ||
          self::parseClass($selector, $query)
          ||
          self::parseAttribute($selector, $query)
          ||
          self::parseComma($selector, $query, $alters)
          ||
          self::parsePlus($selector, $query)
          ||
          self::parseTilda($selector, $query)
          ||
          self::parseChildren($selector, $query)
          ||
          self::parseDescendants($selector, $query)
        )
        {
          continue;
        }

        throw new \Exception("Unsupported selector: «%s»", mb_substr($selector, 15, null, 'utf-8'));
      }
      $alters[] = $query;

      $query = implode('|', array_map(function ($query) {
        return 'descendant-or-self::*/' . implode('/', $query);
      }, $alters));

      return $query;
    }

    private static function parseTag(string &$selector, array &$query): bool
    {
      // tag HTML тэг
      if (preg_match('/^([*]|[\w-]+)/ui', $selector, $match) !== 1)
      {
        return false;
      }

      $query[] = "self::{$match[0]}";
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function parseId(string &$selector, array &$query): bool
    {
      // #id HTML идентификатор
      if (preg_match('/^#([\w-]+)/ui', $selector, $match) !== 1)
      {
        return false;
      }

      $id = $match[1];
      $query[] = "self::*[@id='{$id}']";
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function parseClass(string &$selector, array &$query): bool
    {
      // .css-class CSS класс
      if (preg_match('/^\.([\w-]+)/ui', $selector, $match) !== 1)
      {
        return false;
      }

      $class = $match[1];
      $query[] = "self::*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function parseAttribute(string &$selector, array &$query): bool
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
      $attrPcre = <<<PCRE
/^\[
  \s*( [\w-]+ )\s* # name
  (?:
    ([~|^$*]?=)    # operator
    "([^"]*)"      # value
  )?
\]/xui
PCRE;
      if (preg_match($attrPcre, $selector, $match) !== 1)
      {
        return false;
      }

      $attrName     = $match[1];
      $attrOperator = $match[2] ?? null;
      $attrValue    = $match[3] ?? null;

      $component = 'self::*';
      if ($attrOperator === null)
      {
        $component .= "[@$attrName]";
      }
      else
      {
        switch ($attrOperator)
        {
          case '=':
            $component .= "[@$attrName='$attrValue']";
            break;
          case '^=':
            $component .= "[starts-with(@$attrName, '$attrValue')]";
            break;
          case '$=':
            $component .= "[substring(@$attrName, string-length(@$attrName) - string-length('$attrValue') + 1) = '$attrValue']";
            break;
          default:
            throw new \Exception("Unsupported attribute operator: «%s»", mb_substr($selector, 15, null, 'utf-8'));
        }
      }
      $query[] = $component;
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function parseComma(string &$selector, array &$query, array &$alters): bool
    {
      // * , * Или
      if (preg_match('/^\s*[,]\s*/ui', $selector, $match) !== 1)
      {
        return false;
      }
      $alters[] = $query;
      $query = [];
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function parsePlus(string &$selector, array &$query): bool
    {
      // * + * Первый следующий элемент
      if (preg_match('/^\s*[+]\s*/ui', $selector, $match) !== 1)
      {
        return false;
      }

      $query[] = 'following-sibling::*[1]';
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function parseTilda(string &$selector, array &$query): bool
    {
      // * ~ * Поледующие элементы с общим родителем
      if (preg_match('/^\s*[~]\s*/ui', $selector, $match) !== 1)
      {
        return false;
      }

      $query[] = 'following-sibling::*';
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function parseChildren(string &$selector, array &$query): bool
    {
      // * > * Дочерние элементы
      if (preg_match('/^\s*[>]\s*/ui', $selector, $match) !== 1)
      {
        return false;
      }
      $query[] = 'child::*';
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function parseDescendants(string &$selector, array &$query): bool
    {
      // * * или * >> * Потомоки
      if (preg_match('/^(\s+|\s*[>][>]\s*)/ui', $selector, $match) !== 1)
      {
        return false;
      }
      $query[] = 'descendant-or-self::*';
      $selector = self::cutOff($selector, $match[0]);
      return true;
    }

    private static function cutOff(string $string, string $pattern): string
    {
      return mb_substr($string, mb_strlen($pattern, 'utf-8'), null, 'utf-8');
    }
  }
}
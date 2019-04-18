<?php

namespace S25\DomTrawler
{
  class XPathQuery
  {
    private $alters = [];
    private $query = [];

    public function addSelector(string $expression)
    {
      if (empty($this->query))
      {
        $this->query[] = 'descendant-or-self::*';
      }
      $this->query[] = $expression;
    }

    public function addCombinator(string $expression)
    {
      $this->query[] = $expression;
    }

    public function nextList()
    {
      $this->alters[] = $this->query;
      $this->query = [];
    }

    public function toString(): string
    {
      return implode('|',
        array_map(function ($query) { return implode('/', $query); },
        array_merge($this->alters, [$this->query])
      ));
    }
  }
}
<?php

namespace S25\DomTrawler
{
  class XPathQuery
  {
    /** @var string[] */
    private array $alters = [];
    /** @var string[] */
    private array $query = [];

    public function addSelector(string $expression): void
    {
      if (empty($this->query))
      {
        $this->query[] = 'descendant-or-self::*';
      }
      $this->query[] = $expression;
    }

    public function addCombinator(string $expression): void
    {
      $this->query[] = $expression;
    }

    public function nextList(): void
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

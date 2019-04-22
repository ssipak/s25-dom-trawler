<?php

namespace S25\DomTrawler
{
  class DomTrawler implements \IteratorAggregate, \ArrayAccess, \Countable
  {
    /** @var \DOMNode[] */
    protected $nodeList;
    /** @var \DOMXPath */
    protected $xpath;
    /** @var bool - флаг, указывающий, что в наборе только один элемент, влияет на результат функции evaluate */
    protected $single;

    private function __construct(\DOMXPath $xpath, array $nodeList, bool $single = false)
    {
      $this->xpath = $xpath;
      $this->nodeList = array_values($nodeList);
      $this->single = $single;
    }

    public static function fromDocument(\DOMDocument $document): self
    {
      $nodeList = [$document];
      $xpath = new \DOMXpath($document);
      XPath::setUp($xpath);
      return new self($xpath, $nodeList, true);
    }

    public static function fromHtml($html): self
    {
      $document = new \DOMDocument();
      $lastErrors = libxml_use_internal_errors(true);
      $document->loadHtml($html);
      libxml_clear_errors();
      libxml_use_internal_errors($lastErrors);

      return self::fromDocument($document);
    }

    private function createSub(array $nodeList, bool $single = false): self
    {
      return new self($this->xpath, $nodeList, $single);
    }

    // region Transforming methods

    /**
     * @param $index - zero-based index of node
     * @return DomTrawler
     */
    public function item($index): self
    {
      return $this->createSub(array_filter([$this->node($index)]), true);
    }

    public function first(): self
    {
      return $this->item(0);
    }

    public function unique(): self
    {
      $unique = [];
      foreach ($this->nodeList as $node)
      {
        $unique[spl_object_hash($node)] = $node;
      }
      return $this->createSub(array_values($unique), $this->single);
    }

    public function children(): self
    {
      $childNodes = [];
      foreach ($this->nodeList as $node)
      {
        $childNodes[] = iterator_to_array($node->childNodes);
      }

      return $this->createSub(array_reduce($childNodes, 'array_merge', []));
    }

    public function parent(): self
    {
      $parents = [];
      foreach ($this->nodeList as $node)
      {
        $parents[] = $node->parentNode;
      }

      return $this->createSub($parents, $this->single);
    }

    public function query(string $query): self
    {
      $nodeListArray = [];
      foreach ($this->nodeList as $node)
      {
        $nodeListArray[] = iterator_to_array($this->xpath->query($query, $node));
      }
      return $this->createSub(array_reduce($nodeListArray, 'array_merge', []));
    }

    /**
     * @param string $selector
     * @return DomTrawler
     * @throws \Exception
     */
    public function select(string $selector): self
    {
      static $selectorToQueryMap = [];

      $query = $selectorToQueryMap[$selector] ?? null;
      if ($query === null)
      {
        $query = XPath::fromSelector($selector);
        $selectorToQueryMap[$selector] = $query;
      }

      return $this->query($query);
    }

    // endregion

    // region Extracting data methods

    public function html(): string
    {
      $html = '';
      foreach ($this->nodeList as $node)
      {
        $html .= $node->ownerDocument->saveHTML($node);
      }
      return $html;
    }

    public function text(): string
    {
      $text = '';
      foreach ($this->nodeList as $node)
      {
        $text .= $node->nodeValue;
      }
      return $text;
    }

    public function evaluate(string $expression)
    {
      if ($this->single)
      {
        return empty($this->nodeList)
          ? null
          : $this->xpath->evaluate($expression, $this->nodeList[0]);
      }

      $dataArray = [];
      foreach ($this->nodeList as $node)
      {
        $dataArray[] = $this->xpath->evaluate($expression, $node);
      }
      return $dataArray;
    }

    /**
     * @param $index - zero-based index of node
     * @return \DOMNode|null
     */
    public function node($index) // ?\DOMNode
    {
      return $this->nodeList[$index] ?? null;
    }

    /**
     * @return \DOMNode[]
     */
    public function getNodes(): array
    {
      return $this->nodeList;
    }

    // endregion

    // region IteratorAggregate implementation

    /**
     * @return \Generator|self[]
     */
    public function getIterator(): \Generator
    {
      foreach ($this->nodeList as $node)
      {
        yield new self($this->xpath, [$node]);
      }
    }

    // endregion

    // region ArrayAccess implementation

    public function offsetExists($offset): bool
    {
      return isset($this->nodeList[$offset]);
    }

    public function offsetGet($offset): self // ?self
    {
      return $this->item($offset);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @throws \Exception
     */
    public function offsetSet($offset, $value)
    {
      throw new \Exception("%s items can't be set or unset", self::class);
    }

    /**
     * @param mixed $offset
     * @throws \Exception
     */
    public function offsetUnset($offset)
    {
      throw new \Exception("%s items can't be set or unset", self::class);
    }

    // endregion

    // region Countable implementation

    public function count(): int
    {
      return count($this->nodeList);
    }

    // endregion
  }
}
<?php

namespace S25\DomTrawler;

use JetBrains\PhpStorm\Pure;

class DomTrawler implements \IteratorAggregate, \ArrayAccess, \Countable, \Stringable
{
    /** @var \DOMNode[] */
    protected array $nodeList;

    protected \DOMXPath $xpath;
    /** @var bool - флаг, указывающий, что в наборе только один элемент, влияет на результат функции evaluate */
    protected bool $single;

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

    #[Pure] private function createSub(array $nodeList, bool $single = false): self
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

    #[Pure] public function unique(): self
    {
        $unique = [];
        foreach ($this->nodeList as $node) {
            $unique[spl_object_hash($node)] = $node;
        }
        return $this->createSub(array_values($unique), $this->single);
    }

    public function children(): self
    {
        $childNodes = [];
        foreach ($this->nodeList as $node) {
            $childNodes[] = iterator_to_array($node->childNodes);
        }

        return $this->createSub(array_reduce($childNodes, 'array_merge', []));
    }

    #[Pure] public function parent(): self
    {
        $parents = [];
        foreach ($this->nodeList as $node) {
            $parents[] = $node->parentNode;
        }

        return $this->createSub($parents, $this->single);
    }

    public function query(string $query): self
    {
        $nodeListArray = [];
        foreach ($this->nodeList as $node) {
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
        if ($query === null) {
            $query = XPath::fromSelector($selector);
            $selectorToQueryMap[$selector] = $query;
        }

        return $this->query($query);
    }

    public function __get(string $name)
    {
        // Prepend selector with '> ' if it doesn't already have it or '>>'
        return $this->select(preg_replace('/^(?!>)/u', '> ', $name));
    }

    // endregion

    // region Extracting data methods

    public function html(): string
    {
        $html = '';
        foreach ($this->nodeList as $node) {
            $html .= $node->ownerDocument->saveHTML($node);
        }
        return $html;
    }

    public function text(): string
    {
        $text = '';
        foreach ($this->nodeList as $node) {
            $text .= $node->nodeValue;
        }
        return $text;
    }

    public function evaluate(string $expression): array|string|null
    {
        return $this->mapNodes(
            function (\DOMNode $node) use ($expression) {
                return $this->xpath->evaluate($expression, $node);
            }
        );
    }

    public function attr(string $name): array|string|null
    {
        return $this->mapNodes(
            function (\DOMNode $node) use ($name) {
                return $node->attributes->getNamedItem($name)->nodeValue;
            }
        );
    }

    /**
     * @param $index - zero-based index of node
     * @return \DOMNode|null
     */
    public function node($index): ?\DOMNode
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

    private function mapNodes(callable $func): array|string|null
    {
        if ($this->single) {
            return empty($this->nodeList)
                ? null
                : $func($this->nodeList[0]);
        }

        $dataArray = [];
        foreach ($this->nodeList as $node) {
            $dataArray[] = $func($node);
        }
        return $dataArray;
    }

    // endregion

    // region IteratorAggregate implementation

    /**
     * @return \Generator<self>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->nodeList as $node) {
            yield $this->createSub([$node], true);
        }
    }

    // endregion

    // region ArrayAccess implementation

    public function offsetExists($offset): bool
    {
        return isset($this->nodeList[$offset]);
    }

    public function offsetGet($offset): ?self
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

    // region Stringable implementation

    #[Pure] public function __toString()
    {
        return $this->text();
    }

    // endregion
}

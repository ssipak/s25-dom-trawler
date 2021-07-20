<?php

use PHPUnit\Framework\TestCase;
use S25\DomTrawler\DomTrawler;

final class DomTrawlerTest extends TestCase
{
    const HTML = /** @lang HTML */
        <<<HTML
<!doctype html>
<html>
<head>
    <title>Example Domain</title>

    <meta charset="utf-8" />
    <meta http-equiv="Content-type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style type="text/css">
    body {
        background-color: #f0f0f2;
        margin: 0;
        padding: 0;
        font-family: "Open Sans", "Helvetica Neue", Helvetica, Arial, sans-serif;
    }
    div {
        width: 600px;
        margin: 5em auto;
        padding: 50px;
        background-color: #fff;
        border-radius: 1em;
    }
    a:link, a:visited {
        color: #38488f;
        text-decoration: none;
    }
    @media (max-width: 700px) {
        body {
            background-color: #fff;
        }
        div {
            width: auto;
            margin: 0 auto;
            border-radius: 0;
            padding: 1em;
        }
    }
    </style>    
</head>

<body>
<div>
    <h1>Example Domain</h1>
    <h2>Enjoy testing</h2>
    <p>This domain is established to be used for illustrative examples in documents. You may use this
    domain in examples without prior coordination or asking for permission.</p>
    <p><a href="http://www.iana.org/domains/example">More information...</a></p>
    <p><span class="test-css-selectors">Do they work as expected?</span></p>
    <ul>
        <li>1</li>
        <li data-attr="second">2</li>
        <li data-attr="third">3</li>    
    </ul>
    <div>Nested div</div>
</div>
</body>
</html>
HTML;

    private DomTrawler $trawler;

    /**
     * DomTrawlerTest constructor.
     */
    protected function setUp(): void
    {
        $this->trawler = DomTrawler::fromHtml(self::HTML);
    }

    public function test()
    {
        $a = $this->trawler->select('body > div, p')->select('a');
        $this->assertTrue($a->count() === 2, "Same node can be selected differently (e.g. {$a->count()})");
        $this->assertTrue($a->unique()->count() === 1, "«unique» method can be used to drop duplicates");
        $this->assertTrue(
            $this->trawler->select("h2 > span")->node(0) === null,
            "Selector does not match anything, but something was found"
        );
    }

    public function testClassSelector()
    {
        $this->assertEquals(
            $this->trawler->select('.test-css-selectors')->text(),
            'Do they work as expected?',
            "Unexpected behavior of CSS selector"
        );
    }

    public function testAttributeSelector()
    {
        $selected = $this->trawler->select('li[data-attr="second"]');
        $this->assertTrue(
            $selected->count() === 1 && $selected->first()->text() === '2',
            "Unexpected behavior of attribute selector"
        );
        $selected = $this->trawler->select('ul > li:nth-child(2)[data-attr]');
        $this->assertTrue(
            $selected->count() === 1 && $selected->first()->text() === '2',
            "Unexpected behavior of attribute selector"
        );
    }

    public function testEvaluate()
    {
        $this->assertEquals(
            $this->trawler->select('li')->evaluate('string(text())'),
            ['1', '2', '3'],
            "Unexpected behavior of evaluate method"
        );

        $this->assertEquals(
            $this->trawler->select('li[data-attr]')->evaluate('string(@data-attr)'),
            ['second', 'third'],
            "Unexpected behavior of evaluate method"
        );

        $this->assertEquals(
            $this->trawler->select('li[data-attr]')[1]->evaluate('string(@data-attr)'),
            'third',
            "Unexpected behavior of evaluate method"
        );
    }

    public function testAttr()
    {
        $this->assertEquals(
            $this->trawler->select('li[data-attr]')->attr('data-attr'),
            ['second', 'third'],
            "Unexpected behavior of attr function"
        );

        $this->assertEquals(
            $this->trawler->select('li[data-attr]')[1]->attr('data-attr'),
            'third',
            "Unexpected behavior of attr function"
        );
    }

    public function testContextCombinator()
    {
        $body = $this->trawler->select('body');
        $this->assertTrue($body->select('div')->count() === 2, "Unexpected behavior of tag selector");
        $this->assertTrue($body->select('> div')->count() === 1, "Unexpected behavior of context combinator");
    }

    public function testIteratorAndSingleMode()
    {
        $lis = $this->trawler->select('li');
        $this->assertTrue(
            $lis instanceof DomTrawler,
            sprintf("Select method didn't return %s instance", DomTrawler::class)
        );
        $this->assertTrue(
            $this->getPropertyValue($lis, 'single') === false,
            sprintf("Select method didn't return multiple %s instance", DomTrawler::class)
        );
        foreach ($lis as $li) {
            $this->assertTrue(
                $this->getPropertyValue($li, 'single') === true,
                sprintf("Select method didn't return multiple %s instance", DomTrawler::class)
            );
            break;
        }
    }


    public function testStringable()
    {
        $body = $this->trawler->select('body');

        $this->assertTrue($body->text() === (string)$body, "toString method should be an alias for text method");
    }
    public function getPropertyValue(&$object, $property)
    {
        $reflection = new \ReflectionClass(get_class($object));

        $property = $reflection->getProperty($property);
        $property->setAccessible(true);

        return $property->getValue($object);
    }
}

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
    <p>This domain is established to be used for illustrative examples in documents. You may use this
    domain in examples without prior coordination or asking for permission.</p>
    <p><a href="http://www.iana.org/domains/example">More information...</a></p>
    <p><span class="test-css-selectors">Do they work as expected?</span></p>
    <ul>
        <li>1</li>
        <li data-attr="second">2</li>
        <li data-attr="third">3</li>    
    </ul>
</div>
</body>
</html>
HTML;

  private $trawler;

  /**
   * DomTrawlerTest constructor.
   */
  protected function setUp()
  {
    $this->trawler = DomTrawler::fromHtml(self::HTML);
  }

  public function test()
  {
    $a = $this->trawler->select('body > div, p')->select('a');
    $this->assertTrue($a->count() > 1, "Same node can be selected differently (e.g. {$a->count()})");
    $this->assertTrue($a->unique()->count() === 1, "«unique» method can be used to drop duplicates");
  }

  public function testClassSelector()
  {
    $this->assertTrue(
      $this->trawler->select('.test-css-selectors')->text() === 'Do they work as expected?',
      "CSS selectors work as expected"
    );
  }

  public function testAttributeSelector()
  {
    $this->assertTrue(
      $this->trawler->select('li[data-attr="second"]')->first()->text() === '2',
      "Attribute selectors work as expected"
    );
  }
}
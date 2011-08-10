<?php

class PHPBackporter_Tests_Converter_ClosureTest extends PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider provideTestConversion
     */
    public function testConversion($in, $out) {
        $parser        = new PHPParser_Parser;
        $traverser     = new PHPParser_NodeTraverser;
        $prettyPrinter = new PHPParser_PrettyPrinter_Zend;

        $traverser->addVisitor(new PHPBackporter_Converter_Closure);

        $stmts = $parser->parse(new PHPParser_Lexer('<?php ' . $in));

        $traverser->traverse($stmts);

        $this->assertStringMatchesFormat($out, $prettyPrinter->prettyPrint($stmts));
    }

    public function provideTestConversion() {
        return array(
            array(
                '$f = function($a) use($b) { return $a + $b; };',
<<<EOC
\$f = array(new Closure_%x(array('b' => \$b)), 'call');
class Closure_%x
{
    private \$uses;
    public function __construct(array \$uses)
    {
        \$this->uses = \$uses;
    }
    public function call(\$a)
    {
        extract(\$this->uses, EXTR_REFS);
        return \$a + \$b;
    }
}
EOC
            ),
        );
    }
}
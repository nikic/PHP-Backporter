<?php

class PHPBackporter_Tests_Converter_LambdaTest extends PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider provideTestConversion
     */
    public function testConversion($in, $out) {
        $parser        = new PHPParser_Parser;
        $traverser     = new PHPParser_NodeTraverser;
        $prettyPrinter = new PHPParser_PrettyPrinter_Zend;

        $traverser->addVisitor(new PHPBackporter_Converter_Lambda);

        $stmts = $parser->parse(new PHPParser_Lexer('<?php ' . $in));

        $traverser->traverse($stmts);

        $this->assertStringMatchesFormat($out, $prettyPrinter->prettyPrint($stmts));
    }

    public function provideTestConversion() {
        return array(
            array(
                '$f = function($a, $b) { return $a + $b; };',
<<<EOC
\$f = 'lambda_%x';
function lambda_%x(\$a, \$b)
{
    return \$a + \$b;
}
EOC
            ),
        );
    }
}
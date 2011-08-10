<?php

class PHPBackporter_Tests_Converter_ConstTest extends PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider provideTestConversion
     */
    public function testConversion($in, $out) {
        $parser        = new PHPParser_Parser;
        $traverser     = new PHPParser_NodeTraverser;
        $prettyPrinter = new PHPParser_PrettyPrinter_Zend;

        $traverser->addVisitor(new PHPBackporter_Converter_Const);

        $stmts = $parser->parse(new PHPParser_Lexer('<?php ' . $in));

        $traverser->traverse($stmts);

        $this->assertEquals($out, $prettyPrinter->prettyPrint($stmts));
    }

    public function provideTestConversion() {
        return array(
            array('const FOO = BAR;', "define('FOO', BAR);"),
            array('const ONE = 1, TWO = 2;', "define('ONE', 1);\ndefine('TWO', 2);")
        );
    }
}
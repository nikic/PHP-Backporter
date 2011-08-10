<?php

class PHPBackporter_Tests_Converter_DirTest extends PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider provideTestConversion
     */
    public function testConversion($in, $out) {
        $parser        = new PHPParser_Parser;
        $traverser     = new PHPParser_NodeTraverser;
        $prettyPrinter = new PHPParser_PrettyPrinter_Zend;

        $traverser->addVisitor(new PHPBackporter_Converter_Dir);

        $stmts = $parser->parse(new PHPParser_Lexer('<?php ' . $in));

        $traverser->traverse($stmts);

        $this->assertEquals($out, $prettyPrinter->prettyPrint($stmts));
    }

    public function provideTestConversion() {
        return array(
            array('__DIR__;', "dirname(__FILE__);"),
            array('dirname(__DIR__);', "dirname(dirname(__FILE__));")
        );
    }
}
<?php

class PHPBackporter_Tests_CodeTest extends PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider provideTestConversion
     */
    public function testConversion($originalCode, $expectedCode, $expectedOutput) {
        $parser        = new PHPParser_Parser;
        $traverser     = new PHPParser_NodeTraverser;
        $prettyPrinter = new PHPParser_PrettyPrinter_Zend;

        $traverser->addVisitor(new PHPBackporter_Converter_Dir);
        $traverser->addVisitor(new PHPBackporter_Converter_Const);
        $traverser->addVisitor(new PHPBackporter_Converter_Lambda);
        $traverser->addVisitor(new PHPBackporter_Converter_Closure);
        $traverser->addVisitor(new PHPBackporter_Converter_Namespace);

        $stmts = $parser->parse(new PHPParser_Lexer('<?php ' . $originalCode));

        $traverser->traverse($stmts);

        $code = $prettyPrinter->prettyPrint($stmts);

        ob_start();
        eval($code);
        $output = trim(ob_get_clean());

        if (false === strpos($expectedCode, '%')) {
            $this->assertEquals($expectedCode, $code);
        } else {
            $this->assertStringMatchesFormat($expectedCode, $code);
        }

        if (false === strpos($expectedOutput, '%')) {
            $this->assertEquals($expectedOutput, $output);
        } else {
            $this->assertStringMatchesFormat($expectedOutput, $output);
        }
    }

    public function provideTestConversion() {
        $tests = array();

        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(dirname(__FILE__) . '/../../code'),
                RecursiveIteratorIterator::LEAVES_ONLY
            ) as $file
        ) {
            foreach (explode('-----', file_get_contents($file)) as $test) {
                $tests[] = array_map('trim', explode('---', $test));
            }
        }

        return $tests;
    }
}
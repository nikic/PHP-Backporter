<?php

class PHPBackporter_FunctionDataParser
{
    public function parse($code) {
        $result = array();
        $action = null;
        foreach (explode("\n", str_replace(array("\r\n", "\r"), "\n", $code)) as $i => $line) {
            // skip empty lines and comments
            if ('' === trim($line) || '#' === $line[0]) {
                continue;
            }

            if (preg_match('~^\[([a-z][a-zA-Z]*)\]$~', $line, $matches)) {
                $action = $matches[1];
                continue;
            }

            $parts = explode(':', $line);

            if (2 !== count($parts)) {
                throw new Exception(sprintf('Line %d is malformed ("%s")', $i + 1, $line));
            }

            $name = trim($parts[0]);
            $args = explode(',', trim($parts[1]));

            if (!preg_match('~^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$~', $name)) {
                throw new Exception(sprintf(
                    'Line %d is malformed ("%s"): Invalid function name',
                    $i + 1, $line
                ));
            }

            if (empty($args)) {
                throw new Exception(sprintf(
                    'Line %d is malformed ("%s"): No Arguments given',
                    $i + 1, $line
                ));
            }

            foreach ($args as $j => $arg) {
                $arg = trim($arg);

                if (!is_numeric($arg)) {
                    throw new Exception(sprintf(
                        'Line %d is malformed ("%s"): Argument %d is not a number',
                        $i + 1, $line, $j + 1
                    ));
                }

                $args[$j] = (int) $arg;
            }

            if (null === $action) {
                throw new Exception('Missing action block');
            }

            $result[] = array($name, $args, $action);
        }

        return $result;
    }
}
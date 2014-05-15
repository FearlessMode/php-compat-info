#!/usr/bin/env php
<?php
if (class_exists('Phar')) {
    Phar::mapPhar('phpcompatinfo.phar');
    Phar::interceptFileFuncs();

    if (!getenv("COMPATINFO")) {
        $files = array(
            realpath('./phpcompatinfo.json'),
            getenv('HOME').'/.config/phpcompatinfo.json',
            '/etc/phpcompatinfo.json',
        );
        foreach ($files as $file) {
            if (file_exists($file)) {
                putenv("COMPATINFO=$file");
                break;
            }
        }
    }
    require 'phar://' . __FILE__ . '/bin/compatinfo';
}
__HALT_COMPILER();
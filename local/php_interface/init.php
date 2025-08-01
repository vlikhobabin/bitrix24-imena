<?php
// Автозагрузка классов
spl_autoload_register(function ($class) {
    $prefix = "W4a\\";
    $baseDir = __DIR__ . "/../classes/";
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace("\\", "/", $relativeClass) . ".php";
    
    if (file_exists($file)) {
        require $file;
    }
});



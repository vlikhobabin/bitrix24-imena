<?php
// Подключение JavaScript для исправления множественного выбора UF полей в задачах
use Bitrix\Main\Page\Asset;

AddEventHandler("main", "OnProlog", function() {
    if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
        return;
    }
    
    global $APPLICATION;
    
    // Здесь могут быть дополнительные подключения JS/CSS при необходимости
});

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



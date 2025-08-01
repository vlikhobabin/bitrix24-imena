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

// Функция логирования (оставляем для отладки)
function logEnumFilterDebug($message, $data = null) {
    $logFile = $_SERVER["DOCUMENT_ROOT"] . "/local/enum_filter_debug.log";
    $logMessage = "[" . date("Y-m-d H:i:s") . "] " . $message . "\n";
    if ($data !== null) {
        $logMessage .= print_r($data, true) . "\n";
    }
    $logMessage .= "---\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

<?php
/**
 * Диагностический скрипт для анализа лицензионных ограничений Bitrix24
 * 
 * Использование: 
 * 1. Загрузите файл в папку /local/ на сервере
 * 2. Откройте в браузере: https://your-domain.com/local/license_diagnostic.php
 * 3. Или запустите через командную строку: php /path/to/license_diagnostic.php
 */

// Проверка запуска через командную строку или браузер
$isConsole = php_sapi_name() === 'cli';

if (!$isConsole) {
    // Запуск через браузер - подключаем Bitrix
    require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
    
    // Проверка прав администратора
    if (!$USER->IsAdmin()) {
        die("Access denied. Admin rights required.");
    }
    
    header('Content-Type: text/html; charset=utf-8');
    echo "<html><head><meta charset='utf-8'><title>License Diagnostic</title></head><body>";
    echo "<h1>🔍 Диагностика лицензии Bitrix24</h1>";
} else {
    // Запуск через консоль
    define("NO_KEEP_STATISTIC", true);
    define("NOT_CHECK_PERMISSIONS", true);
    require_once(__DIR__ . "/../bitrix/modules/main/include/prolog_before.php");
}

function output($text, $isHtml = false) {
    global $isConsole;
    if ($isConsole) {
        echo strip_tags($text) . "\n";
    } else {
        echo $isHtml ? $text : nl2br(htmlspecialchars($text)) . "<br>";
    }
}

function heading($text) {
    global $isConsole;
    if ($isConsole) {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo $text . "\n";
        echo str_repeat("=", 50) . "\n";
    } else {
        echo "<h2 style='color: #0066cc; margin-top: 30px;'>" . htmlspecialchars($text) . "</h2>";
    }
}

try {
    // Получение объекта лицензии
    $license = \Bitrix\Main\Application::getInstance()->getLicense();
    $connection = \Bitrix\Main\Application::getConnection();
    
    heading("1. Основные параметры лицензии");
    
    $maxUsers = $license->getMaxUsers();
    $activeUsers = $license->getActiveUsersCount();
    $licenseName = $license->getName();
    $licenseKey = $license->getKey();
    
    output("Максимум пользователей по лицензии: " . ($maxUsers == 0 ? "Без ограничений" : $maxUsers));
    output("Активных пользователей в системе: " . $activeUsers);
    output("Тип лицензии: " . $licenseName);
    output("Лицензионный ключ: " . substr($licenseKey, 0, 20) . "...");
    output("Демо-лицензия: " . ($license->isDemo() ? 'Да' : 'Нет'));
    output("Подсчет экстранет пользователей: " . ($license->isExtraCountable() ? 'Да' : 'Нет'));
    
    // Проверка превышения лимита
    heading("2. Анализ превышения лимита");
    
    if ($maxUsers == 0) {
        output("✅ СТАТУС: Лимит не установлен (безлимитная лицензия)");
    } else {
        if ($activeUsers <= $maxUsers) {
            output("✅ СТАТУС: Лимит НЕ превышен ({$activeUsers} из {$maxUsers})");
        } else {
            output("❌ СТАТУС: ЛИМИТ ПРЕВЫШЕН! ({$activeUsers} из {$maxUsers})");
            output("⚠️  Превышение на " . ($activeUsers - $maxUsers) . " пользователей");
        }
    }
    
    // Детальный подсчет пользователей
    heading("3. Детальный анализ пользователей");
    
    // Интранет пользователи
    $intranetSql = "
        SELECT COUNT(DISTINCT U.ID) as count
        FROM b_user U
            INNER JOIN b_user_field F ON F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT'
            INNER JOIN b_utm_user UF ON UF.FIELD_ID = F.ID AND UF.VALUE_ID = U.ID AND UF.VALUE_INT > 0
        WHERE U.ACTIVE = 'Y' AND U.LAST_LOGIN IS NOT NULL
    ";
    $intranetCount = $connection->queryScalar($intranetSql);
    output("Интранет пользователи (с логином): " . $intranetCount);
    
    // Экстранет пользователи
    $extranetSql = "
        SELECT COUNT(*) as count
        FROM b_user U
            INNER JOIN b_extranet_user EU ON EU.USER_ID = U.ID AND EU.CHARGEABLE = 'Y'
        WHERE U.ACTIVE = 'Y' AND U.LAST_LOGIN IS NOT NULL
    ";
    try {
        $extranetCount = $connection->queryScalar($extranetSql);
        output("Экстранет пользователи (платные): " . $extranetCount);
    } catch (Exception $e) {
        output("Экстранет пользователи: модуль extranet не установлен");
    }
    
    // Общая статистика
    $totalActiveSql = "SELECT COUNT(*) FROM b_user WHERE ACTIVE = 'Y'";
    $totalActive = $connection->queryScalar($totalActiveSql);
    
    $withLoginSql = "SELECT COUNT(*) FROM b_user WHERE ACTIVE = 'Y' AND LAST_LOGIN IS NOT NULL";
    $withLogin = $connection->queryScalar($withLoginSql);
    
    output("Всего активных пользователей: " . $totalActive);
    output("Активных пользователей с логином: " . $withLogin);
    output("Активных пользователей без логина: " . ($totalActive - $withLogin));
    
    // Настройки лицензии из БД
    heading("4. Настройки лицензии в БД");
    
    $optionsSql = "
        SELECT NAME, VALUE 
        FROM b_option 
        WHERE MODULE_ID = 'main' 
            AND NAME IN ('PARAM_MAX_USERS', '~license_name', '~license_codes', '~COUNT_EXTRA', '~PARAM_MAX_USERS')
        ORDER BY NAME
    ";
    $options = $connection->query($optionsSql);
    
    while ($option = $options->fetch()) {
        $name = $option['NAME'];
        $value = $option['VALUE'];
        
        switch ($name) {
            case 'PARAM_MAX_USERS':
                output("Лимит пользователей: " . ($value == '0' ? 'Без ограничений' : $value));
                break;
            case '~license_name':
                output("Название лицензии: " . $value);
                break;
            case '~license_codes':
                output("Коды лицензии: " . $value);
                break;
            case '~COUNT_EXTRA':
                output("Подсчет экстранет: " . ($value == 'Y' ? 'Включен' : 'Отключен'));
                break;
            case '~PARAM_MAX_USERS':
                output("Закодированный лимит: " . substr($value, 0, 20) . "...");
                break;
        }
    }
    
    // Проверка административных уведомлений
    heading("5. Административные уведомления");
    
    $notifySql = "
        SELECT ID, MODULE_ID, TAG, LEFT(MESSAGE, 100) as message_preview, ENABLE_CLOSE
        FROM b_admin_notify 
        WHERE MESSAGE LIKE '%exceeded%' 
            OR MESSAGE LIKE '%maximum%' 
            OR MESSAGE LIKE '%users%' 
            OR MESSAGE LIKE '%license%'
            OR MESSAGE LIKE '%превышен%'
            OR MESSAGE LIKE '%пользователей%'
        ORDER BY ID DESC
        LIMIT 5
    ";
    
    $notifications = $connection->query($notifySql);
    $notifyCount = 0;
    
    while ($notify = $notifications->fetch()) {
        $notifyCount++;
        output("ID: {$notify['ID']}, Модуль: {$notify['MODULE_ID']}, Сообщение: {$notify['message_preview']}...");
    }
    
    if ($notifyCount == 0) {
        output("✅ Административных уведомлений о лицензии не найдено");
    }
    
    // Bitrix24 специфичная информация
    if (class_exists('CBitrix24')) {
        heading("6. Информация Bitrix24");
        
        if (method_exists('CBitrix24', 'getLicenseType')) {
            output("Тип лицензии B24: " . CBitrix24::getLicenseType());
        }
        if (method_exists('CBitrix24', 'getPortalZone')) {
            output("Зона портала: " . CBitrix24::getPortalZone());
        }
        if (method_exists('CBitrix24', 'IsLicensePaid')) {
            output("Платная лицензия: " . (CBitrix24::IsLicensePaid() ? 'Да' : 'Нет'));
        }
    }
    
    // Рекомендации
    heading("7. Рекомендации");
    
    if ($maxUsers > 0 && $activeUsers > $maxUsers) {
        output("⚠️  ТРЕБУЕТСЯ ДЕЙСТВИЕ:");
        output("1. Обновите лицензию для поддержки большего количества пользователей");
        output("2. Или деактивируйте неиспользуемых пользователей");
        output("3. Или временно увеличьте лимит в настройках (не рекомендуется)");
    } else {
        output("✅ Система работает в пределах лицензионных ограничений");
        if ($maxUsers > 0) {
            output("Запас: " . ($maxUsers - $activeUsers) . " пользователей");
        }
    }
    
    heading("8. SQL запросы для ручной проверки");
    
    if (!$isConsole) {
        echo "<textarea style='width:100%; height:300px; font-family:monospace;'>";
    }
    
    output("-- Подсчет интранет пользователей", false);
    output($intranetSql, false);
    output("", false);
    output("-- Подсчет экстранет пользователей", false);
    output($extranetSql, false);
    output("", false);
    output("-- Настройки лицензии", false);
    output($optionsSql, false);
    output("", false);
    output("-- Административные уведомления", false);
    output($notifySql, false);
    
    if (!$isConsole) {
        echo "</textarea>";
    }
    
} catch (Exception $e) {
    heading("ОШИБКА");
    output("Произошла ошибка при выполнении диагностики:");
    output($e->getMessage());
    output("Файл: " . $e->getFile() . ":" . $e->getLine());
}

if (!$isConsole) {
    echo "<p style='margin-top: 30px; color: #666;'>";
    echo "Диагностика завершена. Время выполнения: " . date('Y-m-d H:i:s');
    echo "</p></body></html>";
} else {
    echo "\nДиагностика завершена: " . date('Y-m-d H:i:s') . "\n";
}
?>

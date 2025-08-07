# Диагностика лицензионных ограничений Bitrix24

## 📋 Инструкция по выявлению проблемы с превышением лимита пользователей

### 🎯 Цель
Определить причину появления уведомления "Unfortunately, you have exceeded the maximum number of users allowed for your license" в административной панели Bitrix24.

---

## 🔍 1. Диагностика количества пользователей

### 1.1 Подсчет интранет пользователей (основной)
```sql
-- Точный запрос из кода getActiveUsersCount()
SELECT COUNT(DISTINCT U.ID) as intranet_users
FROM b_user U
    INNER JOIN b_user_field F ON F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT'
    INNER JOIN b_utm_user UF ON 
        UF.FIELD_ID = F.ID 
        AND UF.VALUE_ID = U.ID 
        AND UF.VALUE_INT > 0
WHERE U.ACTIVE = 'Y'
    AND U.LAST_LOGIN IS NOT NULL;
```

### 1.2 Подсчет экстранет пользователей (дополнительный)
```sql
-- Экстранет пользователи (если включен подсчет)
SELECT COUNT(*) as extranet_users
FROM b_user U
    INNER JOIN b_extranet_user EU ON EU.USER_ID = U.ID AND EU.CHARGEABLE = 'Y'
    INNER JOIN b_user_group UG ON UG.USER_ID = U.ID 
WHERE U.ACTIVE = 'Y'
    AND U.LAST_LOGIN IS NOT NULL;
```

### 1.3 Общая статистика пользователей
```sql
-- Все активные пользователи
SELECT 
    COUNT(*) as total_active_users,
    COUNT(CASE WHEN U.LAST_LOGIN IS NOT NULL THEN 1 END) as users_with_login,
    COUNT(CASE WHEN U.LAST_LOGIN IS NULL THEN 1 END) as users_without_login
FROM b_user U 
WHERE U.ACTIVE = 'Y';
```

### 1.4 Детальная разбивка по департаментам
```sql
-- Пользователи по департаментам
SELECT 
    UF.VALUE_INT as department_id,
    COUNT(DISTINCT U.ID) as users_count
FROM b_user U
    INNER JOIN b_user_field F ON F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT'
    INNER JOIN b_utm_user UF ON UF.FIELD_ID = F.ID AND UF.VALUE_ID = U.ID AND UF.VALUE_INT > 0
WHERE U.ACTIVE = 'Y'
GROUP BY UF.VALUE_INT
ORDER BY users_count DESC;
```

---

## ⚙️ 2. Диагностика лицензионных настроек

### 2.1 Основные параметры лицензии
```sql
-- Ключевые настройки лицензии
SELECT MODULE_ID, NAME, VALUE, DESCRIPTION 
FROM b_option 
WHERE MODULE_ID = 'main' 
    AND (
        NAME LIKE '%license%' 
        OR NAME LIKE '%PARAM_MAX_USERS%'
        OR NAME LIKE '%COUNT_EXTRA%'
        OR NAME LIKE '%demo%'
        OR NAME LIKE '%edition%'
    )
ORDER BY NAME;
```

### 2.2 Детальная информация о лицензии
```sql
-- Расширенная информация о лицензии
SELECT 
    CASE 
        WHEN NAME = 'PARAM_MAX_USERS' THEN 'Максимум пользователей'
        WHEN NAME = '~license_name' THEN 'Тип лицензии'
        WHEN NAME = '~license_codes' THEN 'Коды лицензии'
        WHEN NAME = '~COUNT_EXTRA' THEN 'Подсчитывать экстранет'
        WHEN NAME = '~PARAM_MAX_USERS' THEN 'Закодированный лимит'
        ELSE NAME 
    END as parameter_name,
    VALUE as current_value,
    CASE 
        WHEN NAME = 'PARAM_MAX_USERS' AND VALUE = '0' THEN 'Без ограничений'
        WHEN NAME = 'PARAM_MAX_USERS' AND VALUE != '0' THEN CONCAT('Лимит: ', VALUE, ' пользователей')
        WHEN NAME = '~COUNT_EXTRA' AND VALUE = 'Y' THEN 'Экстранет учитывается в лимите'
        WHEN NAME = '~COUNT_EXTRA' AND VALUE = 'N' THEN 'Экстранет НЕ учитывается в лимите'
        ELSE VALUE 
    END as description
FROM b_option 
WHERE MODULE_ID = 'main' 
    AND NAME IN (
        'PARAM_MAX_USERS', 
        '~license_name', 
        '~license_codes', 
        '~COUNT_EXTRA',
        '~PARAM_MAX_USERS'
    );
```

---

## 🔧 3. PHP диагностика через API Bitrix

### 3.1 Создание диагностического скрипта
Создайте файл `diagnose_license.php` в корне сайта:

```php
<?php
require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

if (!check_bitrix_sessid() && !$USER->IsAdmin()) {
    die("Access denied");
}

echo "<h2>Диагностика лицензии Bitrix24</h2>";

try {
    $license = \Bitrix\Main\Application::getInstance()->getLicense();
    
    echo "<h3>1. Параметры лицензии:</h3>";
    echo "Максимум пользователей: " . $license->getMaxUsers() . "<br>";
    echo "Активных пользователей: " . $license->getActiveUsersCount() . "<br>";
    echo "Тип лицензии: " . $license->getName() . "<br>";
    echo "Ключ лицензии: " . substr($license->getKey(), 0, 20) . "...<br>";
    echo "Это демо?: " . ($license->isDemo() ? 'Да' : 'Нет') . "<br>";
    echo "Подсчет экстранет?: " . ($license->isExtraCountable() ? 'Да' : 'Нет') . "<br>";
    
    echo "<h3>2. Проверка лимита:</h3>";
    $maxUsers = $license->getMaxUsers();
    $activeUsers = $license->getActiveUsersCount();
    
    if ($maxUsers == 0) {
        echo "✅ Лимит не установлен (безлимитная лицензия)<br>";
    } else {
        $status = $activeUsers <= $maxUsers ? "✅" : "❌";
        echo $status . " Пользователей: {$activeUsers} из {$maxUsers}<br>";
        
        if ($activeUsers > $maxUsers) {
            echo "⚠️ <strong>ПРЕВЫШЕН ЛИМИТ НА " . ($activeUsers - $maxUsers) . " пользователей!</strong><br>";
        }
    }
    
    echo "<h3>3. Дополнительная информация:</h3>";
    if (class_exists('CBitrix24')) {
        echo "Bitrix24 модуль: Установлен<br>";
        if (method_exists('CBitrix24', 'getLicenseType')) {
            echo "Тип лицензии B24: " . CBitrix24::getLicenseType() . "<br>";
        }
        if (method_exists('CBitrix24', 'getPortalZone')) {
            echo "Зона портала: " . CBitrix24::getPortalZone() . "<br>";
        }
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage();
}
?>
```

### 3.2 Запуск диагностики
```bash
# Откройте в браузере (замените domain.com на ваш домен):
https://your-domain.com/diagnose_license.php?sessid=YOUR_SESSION_ID
```

---

## 🚨 4. Поиск источника уведомления

### 4.1 Проверка административных уведомлений в БД
```sql
-- Поиск уведомлений о лицензии в БД
SELECT 
    ID,
    MODULE_ID,
    TAG,
    LEFT(MESSAGE, 100) as message_preview,
    ENABLE_CLOSE,
    NOTIFY_TYPE
FROM b_admin_notify 
WHERE 
    MESSAGE LIKE '%exceeded%' 
    OR MESSAGE LIKE '%maximum%' 
    OR MESSAGE LIKE '%users%' 
    OR MESSAGE LIKE '%license%'
    OR MESSAGE LIKE '%превышен%'
    OR MESSAGE LIKE '%пользователей%'
ORDER BY ID DESC;
```

### 4.2 Поиск уведомления с конкретным ID
```sql
-- Если знаете data-id уведомления (например, 168)
SELECT * FROM b_admin_notify WHERE ID = 168;
```

---

## 🔍 5. Детальная диагностика проблемы

### 5.1 Пошаговая проверка
1. **Запустите все SQL запросы из раздела 1** и запишите результаты
2. **Запустите все SQL запросы из раздела 2** и проанализируйте настройки
3. **Создайте и запустите PHP скрипт** из раздела 3
4. **Проверьте административные уведомления** из раздела 4

### 5.2 Анализ результатов
Заполните таблицу результатов:

| Параметр | Значение | Статус |
|----------|----------|---------|
| Интранет пользователей | ___ | |
| Экстранет пользователей | ___ | |
| Максимум по лицензии | ___ | |
| Тип лицензии | ___ | |
| Превышение лимита? | Да/Нет | |

---

## 🛠️ 6. Возможные решения

### 6.1 **РЕКОМЕНДУЕМОЕ: Прямое изменение функции getMaxUsers()**
```php
// В файле bitrix/modules/main/lib/license.php найдите:
public function getMaxUsers(): int{ return (int)Option::get(___1775162181(62), ___1775162181(63),(1184/2-592));}

// И замените на:
public function getMaxUsers(): int{ return 100; } // или любое нужное число
```

**Преимущества:**
- ✅ Работает в 100% случаев
- ✅ Не зависит от настроек БД
- ✅ Не ломается при обновлениях настроек лицензии

### 6.2 Если лимит действительно превышен (альтернативный метод)
```sql
-- Увеличение лимита через БД (может не сработать)
UPDATE b_option 
SET VALUE = '100' -- Увеличьте до нужного значения
WHERE MODULE_ID = 'main' AND NAME = 'PARAM_MAX_USERS';

-- Также измените закодированное поле:
UPDATE b_option 
SET VALUE = REPLACE(VALUE, '.50', '.100')  -- замените текущий лимит
WHERE MODULE_ID = 'main' AND NAME = '~PARAM_MAX_USERS';
```

### 6.3 Если лимит настроен неправильно
```sql
-- Сброс лимита для безлимитной лицензии
UPDATE b_option 
SET VALUE = '0' 
WHERE MODULE_ID = 'main' AND NAME = 'PARAM_MAX_USERS';

-- Изменение типа лицензии на Free (если это корректно)
UPDATE b_option 
SET VALUE = 'Free' 
WHERE MODULE_ID = 'main' AND NAME = '~license_name';
```

### 6.3 Скрытие уведомления через CSS (временное решение)
```css
/* Добавить в административную часть */
.adm-warning-block[data-id="168"] {
    display: none !important;
}
```

### 6.4 Удаление конкретного уведомления
```sql
-- Если найдено уведомление в b_admin_notify
DELETE FROM b_admin_notify WHERE ID = 168; -- Замените на реальный ID
```

---

## ⚠️ 7. Меры предосторожности

1. **Сделайте резервную копию БД** перед любыми изменениями
2. **Тестируйте изменения** на тестовом сервере
3. **Очищайте кэш** после изменений настроек лицензии
4. **Документируйте** все внесенные изменения

---

## 📞 8. Если проблема не решается

1. Проверьте файл `/bitrix/license_key.php` на корректность лицензионного ключа
2. Обратитесь к поставщику Bitrix24 для уточнения лицензионных условий
3. Проверьте логи на предмет ошибок лицензирования
4. Рассмотрите возможность обновления лицензии

---

**Дата создания:** $(date)  
**Автор:** Системный администратор  
**Версия:** 1.0

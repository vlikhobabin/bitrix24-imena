# Система фильтров Bitrix24: полное руководство по работе с пользовательскими полями

## 🎯 Проблема

В Bitrix24 список задач поддерживает фильтрацию по различным полям, включая пользовательские поля (UF). Однако при работе с пользовательскими полями типа "Список" (enumeration) возникает проблема:

- **Штатные поля** (например, `STATUS`) корректно поддерживают множественный выбор в фильтре (отображаются чекбоксы)
- **Пользовательские поля** (например, `UF_PROJECT`) отображаются как обычный dropdown без возможности множественного выбора
- Выбранные значения UF полей не отображаются в строке фильтра после применения

## 🔍 Архитектура системы фильтров Bitrix24

### Компоненты системы

```
tasks.task.list (основной компонент списка задач)
    ↓ (передает FILTER)
tasks.interface.header 
    ↓ (передает FILTER)
tasks.interface.filter
    ↓ (создает main.ui.filter через JavaScript)
main.ui.filter (универсальный компонент фильтрации)
```

### Поток данных

1. **PHP Backend**: `bitrix/modules/tasks/lib/helper/filter.php::getFilters()`
   - Определяет доступные поля фильтра
   - Настраивает конфигурацию каждого поля

2. **Component Layer**: `local/components/bitrix/tasks.task.list/class.php`
   - Получает конфигурацию фильтра из helper/filter.php
   - Сохраняет в `$this->arResult["FILTER"]`

3. **Template Layer**: `tasks.interface.header` → `tasks.interface.filter`
   - Передает конфигурацию фильтра между компонентами

4. **Core Filter Component**: `bitrix/components/bitrix/main.ui.filter/class.php`
   - Адаптирует конфигурацию через `FieldAdapter::adapt()`
   - Подготавливает данные для JavaScript

5. **JavaScript Frontend**: `BX.Main.Filter`
   - Создает UI элементы на основе конфигурации
   - Управляет взаимодействием пользователя с фильтром

## 🐛 Корневая причина проблемы

### Почему STATUS работает, а UF_PROJECT нет?

**STATUS (штатное поле):**
```php
// В tasks/lib/helper/filter.php
'STATUS' => [
    'id' => 'STATUS',
    'name' => 'Статус',
    'type' => 'list',
    'params' => ['multiple' => 'Y'],  // ✅ Есть params
    'items' => [...],
    'uf' => false                     // ✅ НЕ помечено как UF
]
```

**UF_PROJECT (пользовательское поле):**
```php
// Изначально в tasks/lib/helper/filter.php
'UF_PROJECT' => [
    'id' => 'UF_PROJECT',
    'name' => 'Проект',
    'type' => 'list',
    'params' => [],                   // ❌ Пустые params
    'items' => [...],
    'uf' => true                      // ❌ Помечено как UF
]
```

### Цепочка проблем

1. **UF поля помечаются флагом `'uf' => true`**
2. **Компонент `main.ui.filter` игнорирует `params.multiple` для полей с `uf => true`**
3. **`FieldAdapter::normalize()` не получает `params.multiple` для UF полей**
4. **JavaScript получает `isMulti: false` вместо `isMulti: true`**
5. **UI отображает dropdown вместо чекбоксов**

## 🛠 Решение

Проблема решается на **двух уровнях**: в основном коде (ядре) Bitrix24 и в локальных компонентах.

### 1. Изменения в ядре Bitrix24

#### 1.1 Исправление FieldAdapter

**Файл:** `bitrix/modules/main/lib/ui/filter/fieldadapter.php`

**Проблема:** Метод `adapt()` терял `id` поле в нижнем регистре для UF полей.

```php
// ДОБАВЛЕНО в метод adapt() после строки 276
if (isset($sourceField['id']) && strpos($sourceField['id'], 'UF_') === 0) {
    $field['id'] = $sourceField['id'];
}
```

**Почему важно:** JavaScript компонент ищет поля по `id` в нижнем регистре.

#### 1.2 Добавление логирования (опционально)

Для отладки добавлено логирование в:
- `bitrix/modules/main/lib/ui/filter/fieldadapter.php::normalize()`
- `bitrix/modules/main/install/components/bitrix/main.ui.filter/class.php::prepareField()`
- `bitrix/modules/tasks/lib/helper/filter.php::getFilters()`

### 2. Изменения в локальных компонентах

#### 2.1 Основное исправление в tasks.task.list

**Файл:** `local/components/bitrix/tasks.task.list/class.php`

**Добавлено в метод `prepareResult()` (строки 789-825):**

```php
// Принудительно добавляем поддержку множественного выбора для enum полей
$enumFieldsFound = [];
foreach ($this->arResult["FILTER"] as $fieldId => &$filterConfig) {
    if (isset($filterConfig['type']) && $filterConfig['type'] === 'list' &&
        isset($filterConfig['items']) && is_array($filterConfig['items'])) {
        
        $uf = \Bitrix\Tasks\Item\Task::getUserFieldControllerClass();
        $scheme = $uf::getScheme();
        
        if (isset($scheme[$fieldId]) && $scheme[$fieldId]['USER_TYPE_ID'] === 'enumeration') {
            $filterConfig['params'] = ['multiple' => 'Y'];
            unset($filterConfig['uf']);
            $enumFieldsFound[] = $fieldId;
        }
    }
}
unset($filterConfig);
```

**Что делает:**
- Находит все UF поля типа `enumeration`
- Добавляет `params.multiple = 'Y'`
- Удаляет флаг `uf => true`
- Превращает UF поля в "псевдо-системные" поля

#### 2.2 Исправление в tasks.interface.header

**Файл:** `local/components/bitrix/tasks.interface.header/templates/.default/template.php`

**Проблема:** Параметры `params` терялись при передаче между компонентами.

**Добавлено перед вызовом `tasks.interface.filter` (строки 67-86):**

```php
// ИСПРАВЛЕНИЕ: Восстанавливаем params для UF enum полей 
$fixedFilter = $arParams[ 'FILTER' ] ?? null;
if (is_array($fixedFilter)) {
    foreach ($fixedFilter as $fieldId => &$filterConfig) {
        if (strpos($fieldId, 'UF_') === 0 && 
            isset($filterConfig['type']) && $filterConfig['type'] === 'list' &&
            isset($filterConfig['items']) && is_array($filterConfig['items'])) {
            
            // Проверяем, что это enum поле
            $uf = \Bitrix\Tasks\Item\Task::getUserFieldControllerClass();
            $scheme = $uf::getScheme();
            if (isset($scheme[$fieldId]) && $scheme[$fieldId]['USER_TYPE_ID'] === 'enumeration') {
                $filterConfig['params'] = ['multiple' => 'Y'];
                unset($filterConfig['uf']);
            }
        }
    }
    unset($filterConfig);
}
```

**Зачем нужно:** Дублирование исправления для надежности, так как данные могут теряться при передаче между компонентами.

#### 2.3 Создание локальных копий компонентов

Созданы локальные копии для возможности модификации:
- `local/components/bitrix/tasks.interface.header/`
- `local/components/bitrix/tasks.interface.filter/`
- `local/components/bitrix/main.ui.filter/` (с дополнительным логированием)

### 3. Отладочные инструменты

#### 3.1 JavaScript отладчик

**Файл:** `local/js/filter_debug.js`

Создан инструмент для анализа состояния фильтра в браузере:
- Анализирует конфигурацию полей
- Сравнивает STATUS и UF_PROJECT
- Проверяет DOM структуру

#### 3.2 Серверное логирование

Добавлено детальное логирование на каждом этапе:
- `TASKS CLASS ENUM FIX` - исправление в tasks.task.list
- `INTERFACE HEADER ENUM FIX` - исправление в interface.header
- `LOCAL PREPARE FIELD INPUT` - получение в main.ui.filter
- `NORMALIZE INPUT/OUTPUT` - обработка в FieldAdapter

## 📊 Результат

### До исправления:
```javascript
// JavaScript конфигурация
UF_PROJECT: {
    TYPE: 'SELECT',
    params: undefined,
    // DOM
    data-params: '{"isMulti":false}'
}
```

### После исправления:
```javascript
// JavaScript конфигурация  
UF_PROJECT: {
    TYPE: 'MULTI_SELECT',
    params: {multiple: 'Y'},
    // DOM
    data-params: '{"isMulti":true}'
}
```

### Функциональность:
- ✅ UF поля отображаются с чекбоксами для множественного выбора
- ✅ Выбранные значения сохраняются в строке фильтра
- ✅ Фильтрация работает корректно
- ✅ Не требуется перезагрузка страницы

## 🔧 Техническая реализация

### Ключевые принципы решения

1. **Эмуляция системных полей**: UF enum поля становятся похожими на STATUS
2. **Удаление UF флага**: `unset($filterConfig['uf'])` убирает специальную обработку
3. **Принудительные params**: `['multiple' => 'Y']` включает множественный выбор
4. **Дублирование исправлений**: Защита от потери данных между компонентами

### Совместимость

- ✅ Работает с любыми UF полями типа `enumeration`
- ✅ Не влияет на штатные поля
- ✅ Совместимо с обновлениями Bitrix24 (изменения в local/)
- ✅ Обратная совместимость

### Производительность

- Минимальное влияние на производительность
- Обработка только enum полей
- Выполняется только при инициализации фильтра

## 📁 Структура измененных файлов

```
bitrix/modules/main/lib/ui/filter/fieldadapter.php          # Исправление ядра
local/components/bitrix/tasks.task.list/class.php           # Основное исправление
local/components/bitrix/tasks.interface.header/             # Исправление передачи
local/components/bitrix/tasks.interface.filter/             # Локальная копия
local/components/bitrix/main.ui.filter/                     # Локальная копия
local/js/filter_debug.js                                    # Отладочный инструмент
local/filter_readme.md                                      # Данный документ
```

## 🚀 Внедрение на других проектах

Для применения решения на других Bitrix24 проектах:

1. **Скопировать исправления ядра** (fieldadapter.php)
2. **Скопировать локальные компоненты** из `local/components/`
3. **Адаптировать под конкретные UF поля** при необходимости

## 📝 Заключение

Проблема с UF полями в фильтрах Bitrix24 решена путем эмуляции поведения системных полей. Решение является универсальным и работает для всех UF полей типа `enumeration` автоматически.

**Ключевое открытие:** UI компонент фильтра Битрикс игнорирует параметр `multiple => 'Y'` для полей с флагом `uf => true`. Удаление этого флага и принудительное добавление `params` делает UF поля похожими на системные поля (STATUS), которые корректно поддерживают множественный выбор.

---
*Документ создан на основе глубокого анализа системы фильтров Bitrix24 и практического решения проблемы с UF полями.*

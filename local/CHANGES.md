# Изменения в файлах ядра Bitrix24

## Основное исправление

**Файл:** `bitrix/modules/tasks/lib/helper/filter.php`

### Измененные строки:

```php
// Строка 1010 - добавлен 'enumeration' в availableTypes
$availableTypes = ['datetime', 'string', 'double', 'boolean', 'enumeration'];

// Строка 1026 - преобразование enumeration в list
else if ($type === 'enumeration')
{
    $type = 'list';
}

// Строки 1042-1062 - автоматическое получение значений enum
else if ($type === 'list' && $item['USER_TYPE_ID'] === 'enumeration')
{
    // Получаем значения enum из базы данных
    $enumItems = [];
    $enumValues = \CUserFieldEnum::GetList(
        ['SORT' => 'ASC'],
        ['USER_FIELD_ID' => $item['ID']]
    );
    while ($enumValue = $enumValues->Fetch())
    {
        $enumItems[$enumValue['ID']] = $enumValue['VALUE'];
    }
    
    $filter[$item['FIELD_NAME']] = [
        'id' => $item['FIELD_NAME'],
        'name' => $item['EDIT_FORM_LABEL'],
        'type' => 'list',
        'items' => $enumItems,
        'uf' => true,
    ];
}

// Строка 365 - получение схемы UF полей
$ufScheme = $this->getUF();

// Строки 380-388 - динамическое определение enum полей
$isEnumField = isset($ufScheme[$fieldId]) && $ufScheme[$fieldId]['USER_TYPE_ID'] === 'enumeration';

if ($isEnumField) {
    // Для enum полей не добавляем префикс % (для точного совпадения)
    $ufFilter[$fieldId] = $this->getFilterFieldData($fieldId);
} else {
    // Для обычных строковых полей добавляем префикс % (для LIKE поиска)
    $ufFilter["%{$fieldId}"] = $this->getFilterFieldData($fieldId);
}
```

## Проблема

До исправления enum поля:
1. Принудительно преобразовывались в тип 'string'
2. Получали префикс `%` для LIKE поиска 
3. Не загружались значения из базы для UI

## Решение

После исправления enum поля:
1. ✅ Распознаются как тип 'enumeration' 
2. ✅ Преобразуются в тип 'list' для UI
3. ✅ Автоматически загружаются значения из БД
4. ✅ Используют точное совпадение по ID без префикса `%`

## Результат

- 🎯 **Универсальность** - работает для всех enum полей автоматически
- 🔒 **Точность** - точное совпадение вместо LIKE поиска
- 🎨 **UI** - корректное отображение выпадающих списков
- ⚡ **Производительность** - эффективные SQL запросы
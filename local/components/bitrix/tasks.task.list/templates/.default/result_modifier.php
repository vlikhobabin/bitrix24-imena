<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/** @var CBitrixComponentTemplate $this */
/** @var array $arParams */
/** @var array $arResult */
/** @global CDatabase $DB */
/** @global CUser $USER */
/** @global CMain $APPLICATION */

use Bitrix\Main;
use Bitrix\Main\Grid;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Extension;
use Bitrix\Tasks\Flow\FlowFeature;
use Bitrix\Tasks\Grid\Task;
use Bitrix\Tasks\Integration\SocialNetwork;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\Type\DateTime;
use Bitrix\Tasks\Util\User;
use Bitrix\UI\Toolbar\Facade\Toolbar;
use Bitrix\Main\Text\HtmlFilter;

// ================== ПРИНУДИТЕЛЬНАЯ ОБРАБОТКА UF_PROJECT ==================
// ОБРАБАТЫВАЕМ UF_PROJECT ДО НАЧАЛА ОСНОВНОЙ ЛОГИКИ КОМПОНЕНТА

// Функция логирования (объявляем здесь для раннего использования) - УПРОЩЕННАЯ
function logEnumFilterDebugEarly($message, $data = null) {
    // Логируем только если есть UF_PROJECT или ошибки
    if (strpos($message, 'UF_PROJECT') === false && strpos($message, 'error') === false) {
        return;
    }
    
    $logFile = $_SERVER["DOCUMENT_ROOT"] . "/local/enum_filter_debug.log";
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] EARLY: $message";
    if ($data !== null) {
        $logMessage .= "\n" . print_r($data, true);
    }
    $logMessage .= "\n---\n";
    @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Принудительная обработка UF_PROJECT в самом начале
$ufProjectFound = null;

// 1. Проверяем POST данные
if (isset($_POST['data']['additional']['UF_PROJECT']) && $_POST['data']['additional']['UF_PROJECT'] !== '') {
    $ufProjectFound = $_POST['data']['additional']['UF_PROJECT'];
    logEnumFilterDebugEarly("Found UF_PROJECT in POST[additional]", ['value' => $ufProjectFound]);
}
elseif (isset($_POST['data']['fields']['UF_PROJECT']) && $_POST['data']['fields']['UF_PROJECT'] !== '') {
    $ufProjectFound = $_POST['data']['fields']['UF_PROJECT'];
    logEnumFilterDebugEarly("Found UF_PROJECT in POST[fields]", ['value' => $ufProjectFound]);
}
// 2. Проверяем уже существующие GET/REQUEST
elseif (isset($_GET['UF_PROJECT']) && $_GET['UF_PROJECT'] !== '') {
    $ufProjectFound = $_GET['UF_PROJECT'];
    logEnumFilterDebugEarly("Found UF_PROJECT in GET", ['value' => $ufProjectFound]);
}
// 3. Ищем в SESSION
else {
    if (isset($_SESSION)) {
        foreach ($_SESSION as $sessionKey => $sessionData) {
            if (strpos($sessionKey, 'main.ui.filter') !== false && is_array($sessionData)) {
                if (isset($sessionData['TASKS_GRID_ROLE_ID_4096_0_ADVANCED_N']['filters'])) {
                    $filters = $sessionData['TASKS_GRID_ROLE_ID_4096_0_ADVANCED_N']['filters'];
                    foreach ($filters as $presetName => $presetData) {
                        if (isset($presetData['additional']['UF_PROJECT']) && $presetData['additional']['UF_PROJECT'] !== '') {
                            $ufProjectFound = $presetData['additional']['UF_PROJECT'];
                            logEnumFilterDebugEarly("Found UF_PROJECT in SESSION", [
                                'value' => $ufProjectFound,
                                'preset' => $presetName
                            ]);
                            break 2;
                        }
                    }
                }
            }
        }
    }
}

// Если найден UF_PROJECT - ПРИНУДИТЕЛЬНО модифицируем arParams И ПОДГОТАВЛИВАЕМ arResult
if ($ufProjectFound !== null) {
    // Инициализируем фильтр если не существует
    if (!isset($arParams['FILTER'])) {
        $arParams['FILTER'] = array();
    }
    
    // КРИТИЧНО: Bitrix ожидает фильтры как массивы!
    // Конвертируем значение в массив для совместимости с CTasks::GetList
    $ufProjectArray = is_array($ufProjectFound) ? $ufProjectFound : [$ufProjectFound];
    
    // Принудительно добавляем UF_PROJECT в параметры компонента
    $arParams['FILTER']['UF_PROJECT'] = $ufProjectArray;
    $arParams['FORCED_UF_PROJECT'] = $ufProjectFound;
    
    // Также добавляем в REQUEST/GET для полной совместимости
    $_GET['UF_PROJECT'] = $ufProjectArray;
    $_REQUEST['UF_PROJECT'] = $ufProjectArray;
    
    // ПОДГОТАВЛИВАЕМ ДАННЫЕ ДЛЯ ПРАВИЛЬНОГО arResult['FILTER'] (основная логика будет в конце)
    $GLOBALS['UF_PROJECT_FORCE_VALUE'] = $ufProjectFound;
    
    logEnumFilterDebugEarly("APPLIED UF_PROJECT to component parameters", [
        'value' => $ufProjectFound,
        'arParams_FILTER' => $arParams['FILTER'],
        'GET_UF_PROJECT' => $_GET['UF_PROJECT'],
        'REQUEST_UF_PROJECT' => $_REQUEST['UF_PROJECT'],
        'GLOBALS_set' => 'yes'
    ]);
} else {
    logEnumFilterDebugEarly("No UF_PROJECT found in any source for early processing", [
        'POST_data' => $_POST['data'] ?? 'not_set',
        'GET_UF_PROJECT' => $_GET['UF_PROJECT'] ?? 'not_set',
        'SESSION_checked' => 'yes'
    ]);
}

Extension::load(["ui.notification", "ui.icons", "ui.avatar",]);
$isExtranetUser = \Bitrix\Tasks\Integration\Extranet\User::isExtranet();
if (Main\ModuleManager::isModuleInstalled('rest') && !$isExtranetUser)
{
	$APPLICATION->IncludeComponent(
		'bitrix:app.placement',
		'menu',
		[
			'PLACEMENT' => 'TASK_LIST_CONTEXT_MENU',
			'PLACEMENT_OPTIONS' => [],
			//'INTERFACE_EVENT' => 'onCrmLeadListInterfaceInit',
			'MENU_EVENT_MODULE' => 'tasks',
			'MENU_EVENT' => 'onTasksBuildContextMenu',
		],
		null,
		['HIDE_ICONS' => 'Y']
	);
}

CJSCore::Init(['tasks_util_query', 'task_popups']);

//region TITLE
if ($arParams['PROJECT_VIEW'] === 'Y')
{
	$title = $shortTitle = Loc::getMessage('TASKS_TITLE_PROJECT');
}
elseif ($arParams['GROUP_ID'] > 0)
{
	$shortTitle = Loc::getMessage('TASKS_TITLE_GROUP_TASKS');
	$title = $shortTitle;

	if (
		Main\Loader::includeModule('socialnetwork')
		&& method_exists(Bitrix\Socialnetwork\ComponentHelper::class, 'getWorkgroupPageTitle')
	)
	{
		$title = \Bitrix\Socialnetwork\ComponentHelper::getWorkgroupPageTitle([
			'WORKGROUP_ID' => $arParams['GROUP_ID'],
			'TITLE' => $shortTitle
		]);
	}
}
elseif ((int)$arParams['USER_ID'] === User::getId())
{
	$title = $shortTitle = Loc::getMessage('TASKS_TITLE_MY');
}
else
{
	$shortTitle = Loc::getMessage('TASKS_TITLE');
	$title = CUser::FormatName($arParams['NAME_TEMPLATE'], $arResult['USER'], true, false).": ".$shortTitle;
}

if ($arResult["IS_COLLAB"])
{
	Toolbar::deleteFavoriteStar();
	$shortTitle = Loc::getMessage('TASKS_TITLE');

	$this->SetViewTarget('in_pagetitle') ?>

	<div class="sn-collab-icon__wrapper">
		<div id="sn-collab-icon-<?=HtmlFilter::encode($arResult["OWNER_ID"])?>" class="sn-collab-icon__hexagon-bg"></div>
	</div>
	<div class="sn-collab__subtitle"><?=HtmlFilter::encode($arResult["COLLAB_NAME"])?></div>
	<?php $this->EndViewTarget();
}

$APPLICATION->SetPageProperty('title', $title);
$APPLICATION->SetTitle($shortTitle);

if (isset($arParams['SET_NAVCHAIN']) && $arParams['SET_NAVCHAIN'] !== 'N')
{
	$APPLICATION->AddChainItem(Loc::getMessage('TASKS_TITLE'));
}

//endregion TITLE

if (!function_exists('formatDateFieldsForOutput'))
{
	/**
	 * @param $row
	 * @throws Main\ObjectException
	 */
	function formatDateFieldsForOutput(&$row): void
	{
		$dateFields = array_filter(
			CTasks::getFieldsInfo(),
			static function ($item) {
				return ($item['type'] === 'datetime' ? $item : null);
			}
		);

		$localOffset = (new \DateTime())->getOffset();
		$userOffset = CTimeZone::GetOffset(null, true);
		$offset = $localOffset + $userOffset;

		foreach ($dateFields as $fieldName => $fieldData)
		{
			if (isset($row[$fieldName]) && is_string($row[$fieldName]) && $row[$fieldName])
			{
				$date = new DateTime($row[$fieldName]);
				if ($date)
				{
					$newOffset = ($offset > 0? '+' : '') . UI::formatTimeAmount($offset, 'HH:MI');
					$row[$fieldName] = mb_substr($date->format('c'), 0, -6).$newOffset;
				}
			}
		}
	}
}

$request = Bitrix\Main\Context::getCurrent()?->getRequest();
if ($request->get('my_tasks_column') === 'Y')
{
	$arParams['FLOW_MY_TASKS'] = 'Y';
	$arParams['demoSuffix'] = FlowFeature::isFeatureEnabledByTrial() ? 'Y' : 'N';
}

if ($request->get('show_counters_toolbar') === 'N')
{
	$arParams['SHOW_COUNTERS_TOOLBAR'] = 'N';
}

$grid = (new Bitrix\Tasks\Grid\Task\Grid($arResult['LIST'], $arParams))
	->setScope($arParams['CONTEXT'] ?? null);

$arResult['HEADERS'] = $grid->prepareHeaders();
$arResult['TEMPLATE_DATA'] = [
	'EXTENSION_ID' => 'tasks_task_list_component_ext_'.md5($this->GetFolder()),
];
$arResult['ROWS'] = [];
$arResult['EXPORT_LIST'] = $arResult['LIST'];

if (!empty($arResult['LIST']))
{
	$users = [];
	$groups = [];

	foreach ($arResult['LIST'] as $row)
	{
		$users[] = $row['CREATED_BY'];
		$users[] = $row['RESPONSIBLE_ID'];

		if ($arResult['GROUP_BY_PROJECT'] && ($groupId = (int)$row['GROUP_ID']))
		{
			$groups[$groupId] = $groupId;
		}
	}

	$groups = SocialNetwork\Group::getData($groups, ['TYPE'], ['WITH_CHAT']);
	$preparedRows = $grid->prepareRows();
	$prevGroupId = $arResult['LAST_GROUP_ID'];

	foreach ($arResult['LIST'] as $key => $row)
	{
		$taskId = (int)$row['ID'];
		$groupId = (int)$row['GROUP_ID'];

		if ($arResult['GROUP_BY_PROJECT'] && $groupId !== $prevGroupId)
		{
			$groupName = htmlspecialcharsbx($groups[$groupId]['NAME']);
			$groupType = $groups[$groupId]['TYPE'] ?? null;
			$groupChatId = $groups[$groupId]['CHAT_ID'] ?? 0;
			$groupUrl = SocialNetwork\Collab\Url\UrlManager::getUrlByType($groupId, $groupType, ['chatId' => $groupChatId]);

			$actionCreateTask = SocialNetwork\Group::ACTION_CREATE_TASKS;
			$actionEditTask = SocialNetwork\Group::ACTION_EDIT_TASKS;

			$arResult['ROWS'][] = [
				'id' => "group_{$groupId}",
				'group_id' => $groupId,
				'parent_id' => 0,
				'has_child' => true,
				'not_count' => true,
				'draggable' => false,
				'custom' => "<div class='tasks-grid-wrapper'><a href='{$groupUrl}' class='tasks-grid-group-link'>{$groupName}</a></div>",
				'attrs' => [
					'data-type' => 'group',
					'data-group-id' => $groupId,
					'data-can-create-tasks' => (SocialNetwork\Group::can($groupId, $actionCreateTask) ? 'true' : 'false'),
					'data-can-edit-tasks' => (SocialNetwork\Group::can($groupId, $actionEditTask) ? 'true' : 'false'),
				],
			];
		}

		$preparedRow = $preparedRows[$key];

		$parentId = $row['PARENT_ID'] ?? 0;
		$arResult['ROWS'][] = [
			'id' => $taskId,
			'has_child' => array_key_exists($taskId, $arResult['SUB_TASK_COUNTERS']),
			'parent_id' => (Grid\Context::isInternalRequest() ? $parentId : 0),
			'parent_group_id' => $groupId,
			'columns' => $preparedRow['content'],
			'actions' => $preparedRow['actions'],
			'cellActions' => $preparedRow['cellActions'],
			'counters' => $preparedRow['counters'],
			'attrs' => [
				'data-type' => 'task',
				'data-group-id' => $groupId,
				'data-can-edit' => ($row['ACTION']['EDIT'] === true ? 'true' : 'false'),
			],
		];

		formatDateFieldsForOutput($arResult['LIST'][$key]);

		$prevGroupId = $groupId;
	}

	$arResult['LAST_GROUP_ID'] = $prevGroupId;
}

$disabledActions = [];
if (
	isset($arResult['VIEW_STATE']['SPECIAL_PRESET_SELECTED']['CODENAME'])
	&& $arResult['VIEW_STATE']['SPECIAL_PRESET_SELECTED']['CODENAME'] === 'FAVORITE'
	&& isset($arResult['VIEW_STATE']['SECTION_SELECTED']['CODENAME'])
	&& $arResult['VIEW_STATE']['SECTION_SELECTED']['CODENAME'] === 'VIEW_SECTION_ADVANCED_FILTER'
)
{
	$disabledActions = [Task\GroupAction::ACTION_ADD_FAVORITE];
}

$arResult['LIST'] = Bitrix\Main\Engine\Response\Converter::toJson()->process($arResult['LIST']);
$arResult['GROUP_ACTIONS'] = (new Task\GroupAction())->prepareGroupActions($arParams['GRID_ID'], $disabledActions);

if ($arResult["IS_COLLAB"]): ?>
<script>
	BX.ready(() => {
		const collabImagePath = "<?=$arResult["COLLAB_IMAGE"] ?>" || null;
		const collabName = "<?=HtmlFilter::encode($arResult["COLLAB_NAME"])?>";
		const ownerId = "<?=HtmlFilter::encode($arResult["OWNER_ID"])?>";
		const avatar = new BX.UI.AvatarHexagonGuest({
			size: 42,
			userName: collabName.toUpperCase(),
			baseColor: '#19CC45',
			userpicPath: collabImagePath,
		});
		avatar.renderTo(BX('sn-collab-icon-' + ownerId));
	});
</script>
<?php endif;

// ================== КАСТОМИЗАЦИЯ ENUM ПОЛЕЙ (из форума Битрикс) ==================

// Получение всех полей типа "Список"
function getEnumFields() {
    global $USER_FIELD_MANAGER;
    $arFields = $USER_FIELD_MANAGER->GetUserFields("TASKS_TASK");
    $result = [];
    foreach($arFields as $value){
        if($value['USER_TYPE_ID'] == "enumeration"){
            $result[] = $value['FIELD_NAME'];
        }
    }
    return $result;
}

$ufFieldId = getEnumFields();
$ufFieldsCount = count($ufFieldId);

// Сначала собираем данные всех enum полей
$allEnumData = [];

for ($i = 0; $i < $ufFieldsCount; $i++) {
    $fieldName = $ufFieldId[$i];
    $priorityItems = [];
    $priorityItemIDs = [];

    // ИСПРАВЛЕНО: Правильное получение USER_FIELD_ID - МУЛЬТИМЕТОД
    global $USER_FIELD_MANAGER;
    $arFields = $USER_FIELD_MANAGER->GetUserFields("TASKS_TASK");
    $userFieldInfo = $arFields[$fieldName] ?? null;
    
    $userFieldId = null;
    
    // Метод 1: Через USER_FIELD_MANAGER
    if ($userFieldInfo && isset($userFieldInfo['ID'])) {
        $userFieldId = $userFieldInfo['ID'];
        logEnumFilterDebugRM("Field info found via USER_FIELD_MANAGER", [
            'field_name' => $fieldName,
            'user_field_id' => $userFieldId,
            'method' => 'USER_FIELD_MANAGER'
        ]);
    }
    // Метод 2: Прямой запрос к CUserTypeEntity
    else {
        $rsUserField = CUserTypeEntity::GetList(
            array(), 
            array("ENTITY_ID" => "TASKS_TASK", "FIELD_NAME" => $fieldName)
        );
        if ($arUserField = $rsUserField->Fetch()) {
            $userFieldId = $arUserField['ID'];
            logEnumFilterDebugRM("Field info found via CUserTypeEntity", [
                'field_name' => $fieldName,
                'user_field_id' => $userFieldId,
                'method' => 'CUserTypeEntity',
                'full_info' => $arUserField
            ]);
        } else {
            logEnumFilterDebugRM("Field info NOT found by any method", [
                'field_name' => $fieldName,
                'user_field_manager_result' => $userFieldInfo ? 'exists_but_no_ID' : 'not_exists',
                'available_fields' => array_keys($arFields)
            ]);
        }
    }
    
    // Получаем enum значения если нашли ID
    if ($userFieldId) {
        $ufCustomPriority = CUserFieldEnum::GetList(array(), array("USER_FIELD_ID" => $userFieldId));
        
        // Получаем значения enum поля
        while ($arEnum = $ufCustomPriority->Fetch()) {
            $priorityItems[$arEnum['ID']] = $arEnum['VALUE'];
            $priorityItemIDs[] = $arEnum['ID'];
        }
        
        logEnumFilterDebugRM("Enum values retrieved", [
            'field_name' => $fieldName,
            'user_field_id' => $userFieldId,
            'items_found' => count($priorityItems),
            'items' => $priorityItems
        ]);
    }

    // Сохраняем данные для обработки строк
    $allEnumData[$fieldName] = [
        'items' => $priorityItems,
        'item_ids' => $priorityItemIDs
    ];

    // Изменяем фильтр - КЛЮЧЕВАЯ ЛОГИКА из форума!
    if (isset($arResult['FILTER'][$fieldName])) {
        $originalFilter = $arResult['FILTER'][$fieldName];
        
        $arResult['FILTER'][$fieldName] = array(
            'id' => $fieldName,
            'name' => $originalFilter['name'] ?? $fieldName,
            'type' => 'list',
            'items' => $priorityItems,  // ВОТ КЛЮЧ! Правильные значения для UI
        );
        
        logEnumFilterDebugRM("Modified filter for field", [
            'field' => $fieldName,
            'original_filter' => $originalFilter,
            'new_filter' => $arResult['FILTER'][$fieldName],
            'items_count' => count($priorityItems)
        ]);
    } else {
        // Если фильтра нет в arResult - создаем его
        $arResult['FILTER'][$fieldName] = array(
            'id' => $fieldName,
            'name' => $fieldName,
            'type' => 'list',
            'items' => $priorityItems,
        );
        
        logEnumFilterDebugRM("Created new filter for field", [
            'field' => $fieldName,
            'created_filter' => $arResult['FILTER'][$fieldName],
            'items_count' => count($priorityItems)
        ]);
    }
}

// Теперь ОДИН РАЗ обрабатываем все строки для всех enum полей
if (!empty($allEnumData)) {
    $arResultRows = [];
    foreach ($arResult['ROWS'] as $row) {
        $arResultRow = $row;
        $rowColumns = $row['columns'] ?? [];
        
        // Обрабатываем все enum поля для этой строки
        foreach ($allEnumData as $fieldName => $enumData) {
            $priorityItemId = isset($rowColumns[$fieldName]) ? $rowColumns[$fieldName] : null;
            
            // Конвертируем ID в текстовое значение
            if ($priorityItemId && in_array($priorityItemId, $enumData['item_ids'])) {
                $rowColumns[$fieldName] = $enumData['items'][$priorityItemId];
                
                logEnumFilterDebugRM("Converted row value", [
                    'field' => $fieldName,
                    'from_id' => $priorityItemId,
                    'to_value' => $enumData['items'][$priorityItemId],
                    'row_id' => $row['id'] ?? 'unknown'
                ]);
            }
        }
            
        $arResultRow['columns'] = $rowColumns;
        $arResultRows[] = $arResultRow;
    }
    
    // Изменяем вывод в Гриде
    $arResult['ROWS'] = $arResultRows;
}

// ================== ОБРАБОТКА ENUM ФИЛЬТРОВ ==================

// Логирование для отладки - ТОЛЬКО ВАЖНЫЕ СОБЫТИЯ
function logEnumFilterDebugRM($message, $data = null) {
    // УПРОЩЕННОЕ ЛОГИРОВАНИЕ - только при фильтрации или ошибках
    $importantEvents = [
        'Field info found',
        'Field info NOT found', 
        'Modified filter for field',
        'FINAL RESULT',
        'Applied UF_PROJECT filter'
    ];
    
    $isImportant = false;
    foreach ($importantEvents as $event) {
        if (strpos($message, $event) !== false) {
            $isImportant = true;
            break;
        }
    }
    
    // Также логируем если есть фильтрация
    if (isset($_REQUEST['apply_filter']) && $_REQUEST['apply_filter'] === 'Y') {
        $isImportant = true;
    }
    
    if (!$isImportant) {
        return; // Пропускаем не важные события
    }
    
    $logFile = $_SERVER["DOCUMENT_ROOT"] . "/local/enum_filter_debug.log";
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] RESULT_MODIFIER: $message";
    if ($data !== null) {
        $logMessage .= "\n" . print_r($data, true);
    }
    $logMessage .= "\n---\n";
    
    @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// ПРИНУДИТЕЛЬНАЯ ОБРАБОТКА UF_PROJECT В COMPONENT - ПРЯМОЙ ПОДХОД
function forceUfProjectProcessing() {
    logEnumFilterDebugRM("forceUfProjectProcessing called", [
        'REQUEST_URI' => $_SERVER['REQUEST_URI'],
        'POST_data' => $_POST['data'] ?? 'not_set',
        'GET_UF_PROJECT' => $_GET['UF_PROJECT'] ?? 'not_set',
        'apply_filter' => $_REQUEST['apply_filter'] ?? 'not_set'
    ]);
    
    $ufProjectValue = null;
    
    // 1. Проверяем POST данные (из AJAX)
    if (isset($_POST['data']['additional']['UF_PROJECT'])) {
        $ufProjectValue = $_POST['data']['additional']['UF_PROJECT'];
        logEnumFilterDebugRM("Found UF_PROJECT in POST[data][additional]", [
            'value' => $ufProjectValue,
            'source' => 'POST_additional'
        ]);
    }
    // 2. Проверяем POST данные (из fields)
    elseif (isset($_POST['data']['fields']['UF_PROJECT'])) {
        $ufProjectValue = $_POST['data']['fields']['UF_PROJECT'];
        logEnumFilterDebugRM("Found UF_PROJECT in POST[data][fields]", [
            'value' => $ufProjectValue,
            'source' => 'POST_fields'
        ]);
    }
    // 3. Проверяем GET параметры
    elseif (isset($_GET['UF_PROJECT'])) {
        $ufProjectValue = $_GET['UF_PROJECT'];
        logEnumFilterDebugRM("Found UF_PROJECT in GET", [
            'value' => $ufProjectValue,
            'source' => 'GET'
        ]);
    }
    // 4. Проверяем REQUEST
    elseif (isset($_REQUEST['UF_PROJECT'])) {
        $ufProjectValue = $_REQUEST['UF_PROJECT'];
        logEnumFilterDebugRM("Found UF_PROJECT in REQUEST", [
            'value' => $ufProjectValue,
            'source' => 'REQUEST'
        ]);
    }
    // 5. Ищем в SESSION
    else {
        if (isset($_SESSION)) {
            foreach ($_SESSION as $sessionKey => $sessionData) {
                if (strpos($sessionKey, 'main.ui.filter') !== false && is_array($sessionData)) {
                    if (isset($sessionData['TASKS_GRID_ROLE_ID_4096_0_ADVANCED_N']['filters'])) {
                        $filters = $sessionData['TASKS_GRID_ROLE_ID_4096_0_ADVANCED_N']['filters'];
                        foreach ($filters as $presetName => $presetData) {
                            if (isset($presetData['additional']['UF_PROJECT'])) {
                                $ufProjectValue = $presetData['additional']['UF_PROJECT'];
                                logEnumFilterDebugRM("Found UF_PROJECT in SESSION", [
                                    'value' => $ufProjectValue,
                                    'source' => 'SESSION',
                                    'preset' => $presetName
                                ]);
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Применяем UF_PROJECT если найден
    if ($ufProjectValue !== null && $ufProjectValue !== '') {
        // Добавляем в GET для передачи компоненту
        $_GET['UF_PROJECT'] = $ufProjectValue;
        $_REQUEST['UF_PROJECT'] = $ufProjectValue;
        
        logEnumFilterDebugRM("FORCED UF_PROJECT processing", [
            'value' => $ufProjectValue,
            'GET_UF_PROJECT' => $_GET['UF_PROJECT'],
            'REQUEST_UF_PROJECT' => $_REQUEST['UF_PROJECT']
        ]);
        
        return $ufProjectValue;
    } else {
        logEnumFilterDebugRM("No UF_PROJECT found in any source", [
            'POST_data' => $_POST['data'] ?? 'not_set',
            'GET_UF_PROJECT' => $_GET['UF_PROJECT'] ?? 'not_set',
            'REQUEST_UF_PROJECT' => $_REQUEST['UF_PROJECT'] ?? 'not_set'
        ]);
        return null;
    }
}

// ПРИНУДИТЕЛЬНО ОБРАБАТЫВАЕМ UF_PROJECT ДО ВСЕГО ОСТАЛЬНОГО
$forcedUfProject = forceUfProjectProcessing();

logEnumFilterDebugRM("result_modifier.php executed", [
    'REQUEST_URI' => $_SERVER['REQUEST_URI'],
    'GET' => $_GET,
    'POST' => $_POST,
    'apply_filter_GET' => $_GET['apply_filter'] ?? 'not_set',
    'apply_filter_POST' => $_POST['apply_filter'] ?? 'not_set',
    'apply_filter_REQUEST' => $_REQUEST['apply_filter'] ?? 'not_set',
    'forced_UF_PROJECT' => $forcedUfProject
]);

// ПРЯМАЯ ОБРАБОТКА ФИЛЬТРОВ В RESULT_MODIFIER - ИСПОЛЬЗОВАНИЕ ПРИНУДИТЕЛЬНОЙ ОБРАБОТКИ
$ufProjectValue = $forcedUfProject; // Используем результат принудительной обработки
$filterDetected = false;

// Детектируем применение фильтра различными способами
if (isset($_REQUEST['apply_filter']) && $_REQUEST['apply_filter'] === 'Y') {
    $filterDetected = true;
    logEnumFilterDebugRM("Filter application detected via apply_filter", [
        'GET' => $_GET,
        'POST' => $_POST,
        'forced_UF_PROJECT' => $forcedUfProject
    ]);
}

// Или если есть принудительно обработанный UF_PROJECT
if ($forcedUfProject !== null) {
    $filterDetected = true;
    $ufProjectValue = $forcedUfProject;
    logEnumFilterDebugRM("Filter detected via forced processing", [
        'UF_PROJECT' => $ufProjectValue
    ]);
}

// Или если есть POST данные с фильтром
if (isset($_POST['data']['fields']['UF_PROJECT']) || isset($_POST['data']['additional']['UF_PROJECT'])) {
    $filterDetected = true;
    $postUfProject = $_POST['data']['fields']['UF_PROJECT'] ?? $_POST['data']['additional']['UF_PROJECT'];
    if ($ufProjectValue === null) {
        $ufProjectValue = $postUfProject;
    }
    logEnumFilterDebugRM("Filter detected via POST data", [
        'UF_PROJECT' => $ufProjectValue,
        'POST_UF_PROJECT' => $postUfProject
    ]);
}

// Или проверяем arParams (переданные из OnBeforeComponentStart)
if (isset($arParams['FORCED_UF_PROJECT'])) {
    $filterDetected = true;
    if ($ufProjectValue === null) {
        $ufProjectValue = $arParams['FORCED_UF_PROJECT'];
    }
    logEnumFilterDebugRM("Filter detected via arParams", [
        'UF_PROJECT' => $ufProjectValue
    ]);
}

if ($filterDetected) {
    // Если не нашли в POST/arParams, ищем в SESSION
    if ($ufProjectValue === null && isset($_SESSION)) {
        foreach ($_SESSION as $sessionKey => $sessionData) {
            if (strpos($sessionKey, 'main.ui.filter') !== false && is_array($sessionData)) {
                logEnumFilterDebugRM("Found filter session key", [
                    'sessionKey' => $sessionKey
                ]);
                
                if (isset($sessionData['TASKS_GRID_ROLE_ID_4096_0_ADVANCED_N']['filters'])) {
                    $filters = $sessionData['TASKS_GRID_ROLE_ID_4096_0_ADVANCED_N']['filters'];
                    
                    logEnumFilterDebugRM("Found filters in SESSION", [
                        'filters' => $filters
                    ]);
                    
                    foreach ($filters as $presetName => $presetData) {
                        if (isset($presetData['additional']['UF_PROJECT'])) {
                            $ufProjectValue = $presetData['additional']['UF_PROJECT'];
                            
                            logEnumFilterDebugRM("Found UF_PROJECT in SESSION", [
                                'preset' => $presetName,
                                'value' => $ufProjectValue,
                                'type' => gettype($ufProjectValue)
                            ]);
                            break;
                        }
                    }
                }
            }
        }
    }
    
    // Применяем фильтр к результату
    if ($ufProjectValue !== null) {
        // Модифицируем arResult
        if (!isset($arResult['FILTER_FIELDS'])) {
            $arResult['FILTER_FIELDS'] = array();
        }
        $arResult['FILTER_FIELDS']['UF_PROJECT'] = $ufProjectValue;
        
        // КРИТИЧНО: Bitrix ожидает фильтры как массивы!
        // Конвертируем значение в массив для совместимости с CTasks::GetList
        $ufProjectValueArray = is_array($ufProjectValue) ? $ufProjectValue : [$ufProjectValue];
        
        // НОВОЕ РЕШЕНИЕ: Компонент НЕ использует arParams['FILTER']!
        // Он использует $this->filter->process()! Нужно модифицировать фильтр после его создания
        
        // КРИТИЧНО: Попытаемся модифицировать component object напрямую
        if (isset($component) && is_object($component)) {
            // Попытаемся получить доступ к listParameters
            $reflection = new ReflectionClass($component);
            if ($reflection->hasProperty('listParameters')) {
                $listParamsProp = $reflection->getProperty('listParameters');
                $listParamsProp->setAccessible(true);
                $listParams = $listParamsProp->getValue($component);
                
                if (!isset($listParams['filter'])) {
                    $listParams['filter'] = array();
                }
                $listParams['filter']['UF_PROJECT'] = $ufProjectValueArray;
                $listParamsProp->setValue($component, $listParams);
                
                logEnumFilterDebugRM("MODIFIED COMPONENT listParameters directly", [
                    'listParams_filter' => $listParams['filter']
                ]);
            }
        }
        
        // Также модифицируем arParams (если доступны)
        if (!isset($arParams['FILTER'])) {
            $arParams['FILTER'] = array();
        }
        $arParams['FILTER']['UF_PROJECT'] = $ufProjectValueArray;
        
        logEnumFilterDebugRM("Applied UF_PROJECT filter", [
            'value' => $ufProjectValue,
            'arResult_FILTER_FIELDS' => $arResult['FILTER_FIELDS'],
            'arParams_FILTER' => $arParams['FILTER']
        ]);
    } else {
        logEnumFilterDebugRM("No UF_PROJECT value found despite filter detection", [
            'POST_data' => $_POST['data'] ?? 'not_set',
            'arParams_FORCED' => $arParams['FORCED_UF_PROJECT'] ?? 'not_set'
        ]);
    }
}

// ================== ФИНАЛЬНАЯ ДИАГНОСТИКА ==================
logEnumFilterDebugRM("FINAL RESULT - arResult FILTER state", [
    'arResult_FILTER_keys' => array_keys($arResult['FILTER'] ?? []),
    'UF_PROJECT_filter' => $arResult['FILTER']['UF_PROJECT'] ?? 'not_set',
    'total_filters' => count($arResult['FILTER'] ?? []),
    'forced_value_from_early_processing' => $GLOBALS['UF_PROJECT_FORCE_VALUE'] ?? 'not_set'
]);

// Дополнительная проверка - есть ли в arResult['FILTER'] наше поле UF_PROJECT
if (isset($arResult['FILTER']['UF_PROJECT'])) {
    logEnumFilterDebugRM("SUCCESS: UF_PROJECT found in final arResult FILTER", [
        'filter_content' => $arResult['FILTER']['UF_PROJECT']
    ]);
} else {
    logEnumFilterDebugRM("WARNING: UF_PROJECT NOT found in final arResult FILTER", [
        'available_filters' => array_keys($arResult['FILTER'] ?? [])
    ]);
}

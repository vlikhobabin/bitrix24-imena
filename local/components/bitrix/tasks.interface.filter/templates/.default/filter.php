<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\UI\Filter\Theme;

$isBitrix24Template = SITE_TEMPLATE_ID === "bitrix24";
$filterId = $arParams["FILTER_ID"] ?? null;
?>

<div class="tasks-interface-filter pagetitle-container<?php if (!$isBitrix24Template): ?> pagetitle-container-light<? endif ?> pagetitle-flexible-space">
	<?php
	$filterData = $arParams["FILTER"] ?? null;
	
	// Временная диагностика для UF полей
	if (is_array($filterData)) {
		$ufFields = array_filter($filterData, function($field) {
			return isset($field['id']) && strpos($field['id'], 'UF_') === 0;
		});
		error_log('FILTER DATA UF FIELDS: ' . count($ufFields) . ' UF fields found: ' . implode(', ', array_column($ufFields, 'id')));
	} else {
		error_log('FILTER DATA: not an array or null');
	}

	$filterComponentData = [
		"FILTER_ID" => $filterId,
		"GRID_ID" => $arParams["GRID_ID"] ?? null,
		"FILTER" => $filterData,
		"FILTER_PRESETS" => $arParams["PRESETS"] ?? null,
		"ENABLE_LABEL" => true,
		'ENABLE_LIVE_SEARCH' => isset($arParams['USE_LIVE_SEARCH']) && $arParams['USE_LIVE_SEARCH'] === 'Y',
		'RESET_TO_DEFAULT_MODE' => true,
		'THEME' => Theme::MUTED,
	];

	if (isset($arResult['LIMIT_EXCEEDED']))
	{
		$filterComponentData['LIMITS'] = $arResult['LIMITS'];
	}

	$APPLICATION->IncludeComponent(
		"bitrix:main.ui.filter",
		"",
		$filterComponentData,
		$component,
		["HIDE_ICONS" => true]
	); ?>
</div>

<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

$APPLICATION->IncludeComponent("bitrix:sender.yandex.toloka", ".default", array(
	'SEF_FOLDER' => '#SITE_DIR#marketing/toloka/',
	'SEF_MODE' => 'Y',
));

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
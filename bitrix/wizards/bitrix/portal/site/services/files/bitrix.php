<?
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)
	die();

if(mb_strlen(rtrim($_SERVER["DOCUMENT_ROOT"], "/")) <= mb_strlen(rtrim(WIZARD_SITE_PATH, '/')) || WIZARD_B24_TO_CP)
	CopyDirFiles(
		WIZARD_ABSOLUTE_PATH."/site/public/".LANGUAGE_ID."/bitrix/",
		WIZARD_SITE_PATH."/bitrix/",
		$rewrite = false,
		$recursive = true,
		$delete_after_copy = false,
		$exclude = "urlrewrite.php"
	);
?>
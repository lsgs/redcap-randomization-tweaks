<?php
/**
 * REDCap External Module: Randomization Tweaks
 * Action tags @RANDNO and @RANDTIME.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\RandomizationTweaks\RandomizationTweaks)) { exit(); }

$record = htmlspecialchars($_GET['record'], ENT_QUOTES);
$event_id = htmlspecialchars($_GET['event_id'], ENT_QUOTES);
$fields = htmlspecialchars($_GET['fields'], ENT_QUOTES);

header("Content-Type: application/json");
echo json_encode(array('data' => $module->getFieldData($record, $event_id, $fields)));
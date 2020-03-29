<?php
/**
 * REDCap External Module: Randomization Tweaks
 * Action tags @RANDNO and @RANDTIME.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\RandomizationTweaks\RandomizationTweaks)) { exit(); }

$record = $_GET['record'];
$event_id = $_GET['event_id'];
$fields = $_GET['fields'];

header("Content-Type: application/json");
echo json_encode(array('data' => $module->getFieldData($record, $event_id, $fields)));
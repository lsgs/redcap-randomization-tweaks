<?php
/**
 * REDCap External Module: Randomization Tweaks
 * Action tags @RANDNO and @RANDTIME.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\RandomizationTweaks;

use ExternalModules\AbstractExternalModule;

class RandomizationTweaks extends AbstractExternalModule
{
        public function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {

                if (PAGE!=='Randomization/randomize_record.php') { return; }
                
                $randtimeField = $this->getTaggedField('@RANDTIME');
                $randnoField = $this->getTaggedField('@RANDNO');
                
                if (!empty($randtimeField)) {
                        $this->setRandTime($record, $event_id, $randtimeField);
                }
                if (!empty($randnoField)) {
                        $this->setRandno($project_id, $record, $event_id, $randnoField);
                }
        }
        
        protected function getTaggedField($tag) {
                $dd = \REDCap::getDataDictionary('array', false);
                foreach ($dd as $fieldName => $fieldAttr) {
                        if (preg_match("/$tag/", $fieldAttr['field_annotation'])) { 
                                return $fieldName;
                        }
                }
                return null;
        }
        
        protected function setRandTime($record, $event_id, $field_name) {
                $this->save($record, $event_id, $field_name, date('Y-m-d H:i:s'));
        }
        
        protected function setRandno($project_id, $record, $event_id, $field_name) {
                $aid = db_result(
                        db_query(
                            "select aid "
                                . "from redcap_randomization_allocation ra "
                                . "inner join redcap_randomization r "
                                . " on r.rid=ra.rid "
                                . "inner join redcap_projects p "
                                . " on r.project_id=p.project_id "
                                . " and ra.project_status=p.status "
                                . "where r.project_id=".db_escape($project_id)." and is_used_by='".db_escape($record)."'"
                        )
                    , 0);
                $randnoPid = $this->getSystemSetting('randno-project');
                if (!empty($randnoPid) && !empty($aid)) {
                        $randnoData = \REDCap::getData(array(
                                'project_id' => $randnoPid,
                                'return_format' => 'array', 
                                'records' => $aid,
                                'fields' => 'randno'
                        ));
                        reset($randnoData[$aid]);
                        $eid = key($randnoData[$aid]);
                        $this->save($record, $event_id, $field_name, $randnoData[$aid][$eid][$field_name]);
                }
        }
        
        protected function save($record, $event_id, $field_name, $value) {
                $saveResult = \REDCap::saveData(
                        'array', 
                        array($record => array($event_id => array($field_name => $value)))
                );

                if (count($saveResult['errors'])>0) {
                        \REDCap::logEvent("ERROR in Randomization Tweaks external module: could not save value to field | pid=$record, event=$event_id, field=$field_name, value=$value ");
                        /*TODO Email admin*/
                }
        }
}
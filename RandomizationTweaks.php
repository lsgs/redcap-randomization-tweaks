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
        protected $data_dictionary;
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
                $dd = $this->getDD();
                foreach ($dd as $fieldName => $fieldAttr) {
                        if (preg_match("/$tag/", $fieldAttr['field_annotation'])) { 
                                return $fieldName;
                        }
                }
                return null;
        }
        
        protected function getDD() {
                if (!is_array($this->data_dictionary)) { 
                        $this->data_dictionary = \REDCap::getDataDictionary('array', false);
                }                    
                return $this->data_dictionary;
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
                        $this->save($record, $event_id, $field_name, $randnoData[$aid][$eid]['randno']);
                }
        }
        
        protected function save($record, $event_id, $field_name, $value) {
                $saveResult = \REDCap::saveData(
                        'array', 
                        array($record => array($event_id => array($field_name => $value)))
                );

                if (count($saveResult['errors'])>0) {
                        \REDCap::logEvent("ERROR in Randomization Tweaks external module: could not save value to field","record=$record, event=$event_id, field=$field_name, value=$value <br>saveResult=".print_r($saveResult, true));
                        $this->sendAdminEmail("ERROR in Randomization Tweaks external module", "Could not save value to field: record=$record, event=$event_id, field=$field_name, value=$value <br>saveResult=".print_r($saveResult, true));
                }
        }

        public function redcap_data_entry_form($project_id, $record=null, $instrument, $event_id, $group_id=null, $repeat_instance=1) {
                // If currentpage has randomisation button and either a 
                // randomisation time or number field then add script to run 
                // when the randomisation dialog is closed:
                // Need to get the field values via ajax and populate the inputs
                if ($this->currentFormHasAllocationAndTaggedField($project_id, $instrument)) {
                        global $Proj;
                        $randtimeField = $this->getTaggedField('@RANDTIME');
                        $randnoField = $this->getTaggedField('@RANDNO');
                        $ajaxUrl = $this->getUrl('read_saved_data_ajax.php');
                        ?>
<script type="text/javascript">
    $(document).ready(function(){
        if ($('#redcapRandomizeBtn').length) { // not already randomised (button is displayed)
            var ajaxUrl = '<?php echo $ajaxUrl;?>';
            var record = '<?php echo $record;?>';
            var event_id = '<?php echo $event_id;?>';
            var randtimeField = '<?php echo (is_null($randtimeField))?null:$randtimeField;?>';
            var randnoField = '<?php echo (is_null($randnoField))?null:$randnoField;?>';

            if (record==='') { // record created by randomisation
                record = $('tr[sq_id=<?php echo $Proj->table_pk;?>] > td:nth-child(2)')[0].innerText;
            }
            
            // if a jQuery UI Dialog has never been opened before on a page, then the overlay div won't exist in the DOM. Hence, you may consider doing something like this instead: https://stackoverflow.com/questions/171928/jquery-ui-dialog-how-to-hook-into-dialog-close-event
            $('body').on('dialogclose', '.ui-dialog', function(event) {
                if (event.target.id=='randomizeDialog' && $('#redcapRandomizeBtn').length==0) {
                    populateModuleFields();
                }
            });
            
            function populateModuleFields() {
                var getdata = {};
                var fields = [];
                getdata.record = record;
                getdata.event_id = event_id;
                if (randtimeField!=='' && findInput(randtimeField).length) fields.push(randtimeField);
                if (randnoField!=='' && findInput(randtimeField).length) fields.push(randnoField);
                getdata.fields = fields;
                $.ajax({
                    url: ajaxUrl,
                    data: getdata,
                    success: function(response) {
                        Object.keys(response.data).forEach(function(fld) {
                            var input = findInput(fld);
                            var fv = input.attr('fv');
                            var val = response.data[fld];
                            if (fv!=undefined) {
                                var dateparts = val.slice(0, 9).split('-');
                                var timepart = val.slice(10); // includes the space separator ' 03:04:05' 
                                switch(fv.slice(-3)) {
                                  case 'dmy' : val = dateparts[2]+'-'+dateparts[1]+'-'+dateparts[0]+timepart; break; // 2000-11-22 03:04:05 -> 22-11-2000 03:04:05
                                  case 'mdy': val = dateparts[1]+'-'+dateparts[2]+'-'+dateparts[0]+timepart; break; // 2000-11-22 03:04:05 -> 11-22-2000 03:04:05
                                  default: break;
                                }
                            }
                            input.val(val).trigger('blur');
                        });
                    },
                    dataType: 'json'
                });
            }
            
            function findInput(fld) {
                return $('input[name='+fld+']');
            }
        }
    });
</script>
                        <?php
                }
        }

        protected function currentFormHasAllocationAndTaggedField($project_id, $instrument) {
                $randtimeField = $this->getTaggedField('@RANDTIME');
                $randnoField = $this->getTaggedField('@RANDNO');
                $target = db_result(db_query(
                            "select target_field from redcap_randomization where project_id=".db_escape($project_id)
                          ), 0);
                $dd = $this->getDD();
                return $dd[$target]['form_name']==$instrument && (
                            $dd[$randtimeField]['form_name']==$instrument ||
                            $dd[$randnoField]['form_name']==$instrument 
                        );
        }
        
        public function getFieldData(string $record, string $event_id, array $fields) {
                $recordData = \REDCap::getData(array(
                        'return_type' => 'array',
                        'records' => $record,
                        'fields' => $fields
                ));
                $return = array();
                foreach ($fields as $f) {
                        $return[$f] = $recordData[$record][$event_id][$f];
                }
                return $return;
        }
}
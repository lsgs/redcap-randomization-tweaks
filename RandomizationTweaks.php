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
        protected $logging = false;
        protected $record;
        protected $event_id;
        
        public function __construct() {
                parent::__construct();
                if (defined('PROJECT_ID')) {
                       $this->logging = (bool)$this->framework->getProjectSetting('logging');
                }
        }
        
        /**
         * redcap_save_record
         * - On randomisation look up randomisation number and set datetime in 
         * tagged fields.
         * - If a save of a data entry form with tagged fields, check allocation
         * and if randomised then
         */
        public function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {

                // delay in case randomisation occuring in current save via another EM e.g. Realtime Randomization
                if ($this->delayModuleExecution()) { 
                    // Boolean true is returned if the hook was successfully delayed, false if the hook cannot be delayed any longer and this is the module's last chance to perform any required actions. 
                    // If delay successful, return; immediately to stop the current execution of hook
                    return; 
                }

                $this->record = $record;
                $this->event_id = $event_id;
                $this->logmsg("redcap_save_record $project_id, $record, $instrument, $event_id: PAGE=".PAGE, 'Randomization Tweaks external module', false);
                if (PAGE==='Randomization/randomize_record.php') { 

                        $this->logmsg('Seeking tagged fields', 'Randomization Tweaks external module', false);

                        $randtimeField = $this->getTaggedField('@RANDTIME');
                        $randnoField = $this->getTaggedField('@RANDNO');

                        if (!empty($randtimeField)) {
                                $this->setRandTime($record, $event_id, $randtimeField);
                        }
                        if (!empty($randnoField)) {
                                $this->setRandno($project_id, $record, $event_id, $randnoField);
                        }
                        
                } else if (PAGE==='DataEntry/index.php' || PAGE==='surveys/index.php') {
                
                        $randtimeField = $this->getTaggedField('@RANDTIME');
                        $randnoField = $this->getTaggedField('@RANDNO');
                        $target = $this->getTargetField($project_id);

                        $randFields = array($target);
                        if (!empty($randtimeField)) {
                                $randFields[] = $randtimeField;
                        }
                        if (!empty($randnoField)) {
                                $randFields[] = $randnoField;
                        }

                        $savedData = $this->getFieldData($record, $event_id, $randFields);
                        
                        if (!empty($savedData[$target]) && !empty($randtimeField) && empty($savedData[$randtimeField])) {
                                $this->logmsg("Found randomised record with empty randomisation time field $randtimeField", 'Randomization Tweaks external module', false);
                                $this->setRandTime($record, $event_id, $randtimeField);
                        }
                        if (!empty($savedData[$target]) && !empty($randnoField) && empty($savedData[$randnoField])) {
                                $this->logmsg("Found randomised record with empty randomisation number field $randtimeField", 'Randomization Tweaks external module', false);
                                $this->setRandno($project_id, $record, $event_id, $randnoField);
                        }
                }
        }
        
        /**
         * redcap_every_page_before_render
         * If a save of the data entry form containing tagged fields, then do 
         * not allow their values to be updated with blank 
         * e.g. due to slow connection (saving form before read_saved_data_ajax
         * call completes).
         */
        public function redcap_every_page_before_render($project_id=null) {
                if (PAGE==='DataEntry/index.php' && isset($_POST['submit-action'])) {
                        $taggedFields = array(
                            '@RANDTIME' => $this->getTaggedField('@RANDTIME'),
                            '@RANDNO' => $this->getTaggedField('@RANDNO')
                        );
                        
                        foreach ($taggedFields as $tf) {
                                if (isset($_POST[$tf]) && empty($_POST[$tf])) { 
                                        $this->logmsg("Removing empty field '$tf' from save values", false);
                                        unset($_POST[$tf]); 
                                }
                        }
                }
        }

        protected function getTaggedField($tag) {
                $dd = $this->getDD();
                foreach ($dd as $fieldName => $fieldAttr) {
                        if (preg_match("/$tag/", $fieldAttr['field_annotation'])) { 
                                //$this->logmsg("Found field '$fieldName' tagged $tag", false);
                                return $fieldName;
                        }
                }
                $this->logmsg("No field found with tag $tag", false);
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
                $this->logmsg("Looking up randomisation number for record '$record'", false);
                $sql = "select aid "
                        . "from redcap_randomization_allocation ra "
                        . "inner join redcap_randomization r "
                        . " on r.rid=ra.rid "
                        . "inner join redcap_projects p "
                        . " on r.project_id=p.project_id "
                        . " and ra.project_status=p.status "
                        . "where r.project_id=? and is_used_by=?";
                $q = $this->query($sql, [ $project_id, $record ]);
                while ($row = $q->fetch_assoc()) {
                        $aid = $row['aid'];
                }
                $this->logmsg("Randomisation allocation id for record '$record' is $aid", false);
                $randnoPidS = $this->getSystemSetting('randno-project');
                $randnoPidP = $this->getProjectSetting('randno-project-override');
                $randnoPid = (empty($randnoPidP)) ? $randnoPidS : $randnoPidP;
                if (!empty($randnoPid) && !empty($aid)) {
                        $randnoData = \REDCap::getData(array(
                                'project_id' => $randnoPid,
                                'return_format' => 'array', 
                                'records' => $aid,
                                'fields' => 'randno'
                        ));
                        if (count($randnoData)) {
                                reset($randnoData[$aid]);
                                $eid = key($randnoData[$aid]);
                                $randno = $randnoData[$aid][$eid]['randno'];
                                $this->logmsg("Found randomisation number $randno", false);
                                $this->save($record, $event_id, $field_name, $randno);
                        } else {
                            $this->logmsg("***ERROR!*** Could not find randomisation number for aid=$aid in pid=$randnoPid", true);
                        }
                }
        }
        
        protected function save($record, $event_id, $field_name, $value) {
                $recArray = array($record => array($event_id => array($field_name => $value)));
                $this->logmsg("Saving $field_name => $value");
                $saveResult = \REDCap::saveData(
                        'array', 
                        $recArray
                );

                if (count($saveResult['errors'])>0) {
                        $this->logmsg("ERROR! Could not save value to field: record=$record, event=$event_id, field=$field_name, value=$value <br>saveResult=".print_r($saveResult, true), true);
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
                        
                        $this->record = $record;
                        $this->event_id = $event_id;
                
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
                                var dateparts = val.slice(0, 10).split('-');
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
                $target = $this->getTargetField($project_id);
                $dd = $this->getDD();
                return $dd[$target]['form_name']==$instrument && (
                            $dd[$randtimeField]['form_name']==$instrument ||
                            $dd[$randnoField]['form_name']==$instrument 
                        );
        }
        
        protected function getTargetField($project_id) {
                return db_result(db_query(
                            "select target_field from redcap_randomization where project_id=".db_escape($project_id)
                          ), 0);
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
        
        protected function logmsg($msg, $always=false) {
                if ($this->logging || $always) {
                        $desc='Randomization Tweaks external module';
                        \REDCap::logEvent($desc, $msg, '', $this->record, $this->event_id);
                }
        }
}
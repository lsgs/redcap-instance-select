<?php
/**
 * InstanceSelect
 *
 * Custom action tags can be used on text fields:
 * @RECORDINSTANCE=myarmnum
 * @FORMINSTANCE=myformname or @FORMINSTANCE=myeventname_arm_n:myformname
 * @EVENTINSTANCE=myeventname_arm_n
 *
 * Records are not labelled in survey view in case custom label contains PHI
 */
namespace MCRI\InstanceSelect;

use ExternalModules\AbstractExternalModule;
use Form;
use Piping;
use RCView;
use Records;
use REDCap;

class InstanceSelect extends AbstractExternalModule
{
        protected static $Tags = array(
            '@EVENTINSTANCE' =>
                'Specify the unique event name of a repeating event and the select list will show instances of the specified event for the current record:<br>* @EVENTINSTANCE=myeventname_arm_n : Select an instance of myeventname_arm_n<br>If the event is not a repeating event then the action tag will be ignored and you will see only the unvalidated text field.'
            ,'@FORMINSTANCE' =>
                'Specify a form name or unique event name/form name pair and the select list will show instances of the specified form for the current record:<br>* @FORMINSTANCE=myformname : Select an instance of myformname from all events in which it is designated<br>* @FORMINSTANCE=myevent_arm_1:myformname : Select an instance of myformname from the myevent_arm_1 event only<br>If the (event/)form is not a repeating form then the action tag will be ignored and you will see only the unvalidated text field.'
            ,'@RECORDINSTANCE' =>
                'Select another record from the current project. A comma-separated list of arm numbers may be specified, if desired:<br>* @RECORDINSTANCE : Select a record from the current arm<br>* @RECORDINSTANCE=\'2,3\' or,<br>* @RECORDINSTANCE=2,3 : Select a record from arm 2 and arm 3 records<br>Invalid arm numbers will be ignored.<br>Users assigned to a DAG will see only records assigned to the same DAG.'
            );

        protected $isSurvey=false;
        protected $taggedFields=array();

        protected $Proj;
        protected $lang;
        protected $user_rights;
        protected $event_id;
        protected $record;
        protected $instrument;
        protected $repeat_instance;

        public function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
	    	$this->initHook($record, $instrument, $event_id, $repeat_instance);
            $this->pageTop();
        }

        public function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
    		$this->initHook($record, $instrument, $event_id, $repeat_instance, true);
            $this->pageTop();
        }

        protected function initHook($record, $instrument, $event_id, $repeat_instance, $isSurvey=false) {
            global $Proj, $lang, $user_rights;
            $this->Proj = $Proj;
            $this->lang = &$lang; //nb. $lang is an array which is apparently not an object, so & required to assign by reference
            $this->user_rights = &$user_rights;
            $this->record = $record;
            $this->instrument = $instrument;
            $this->event_id = $event_id;
            $this->repeat_instance = $repeat_instance;
            $this->isSurvey = $isSurvey;
        }

        protected function pageTop() {
                $this->setTaggedFields();

                if (count($this->taggedFields)>0) {
                        // write the JavaScript to the page
                        $this->insertJS();
                }
        }

        protected function setTaggedFields() {

                // find tagged fields

                foreach(array_keys($this->Proj->forms[$this->instrument]['fields']) as $field) {
                        $fieldMetadata = $this->Proj->metadata[$field];

                        if ($fieldMetadata['element_type']!=='text' ||
                            $fieldMetadata['element_validation_type']!==null ||
                            $fieldMetadata['misc']===null ) {
                                continue;
                        }

                        list($tag, $param) = $this->getTagAndParam($fieldMetadata['misc']);

                        if (is_null($tag)) { continue; }

                        $optionList = $this->getLookupOptions($tag, $param, $this->record, $this->event_id);

                        if (!is_array($optionList)) { continue; } // skip this field if not correctly specified

                        $recordData = REDCap::getData(
                                'array',
                                $this->record,
                                $field,
                                $this->event_id
                        );

                        if ($this->Proj->isRepeatingForm($this->event_id, $this->instrument)) {
                                $currentValue = $recordData[$this->record]['repeat_instances'][$this->event_id][$this->instrument][$this->repeat_instance][$field];
                        } else if ($this->Proj->isRepeatingEvent($this->event_id)) {
                                $currentValue = $recordData[$this->record]['repeat_instances'][$this->event_id][null][$this->repeat_instance][$field];
                        } else {
                                $currentValue = $recordData[$this->record][$this->event_id][$field];
                        }

                        $this->taggedFields[] = array(
                                'name' => $field,
                                'lookup' => $optionList,
                                'currentValue' => $currentValue
                        );
                }
        }

        protected function insertJS() {
          $parent_instance = ($_GET['parent_instance'] == null
            || empty($_GET['parent_instance'])) ? -1 : $_GET['parent_instance'];
?>
<script type='text/javascript'>
$(document).ready(function() {
    var taggedFields = <?php print json_encode($this->taggedFields); ?>;
    //console.log(taggedFields);

    // Loop through each field_name
    $(taggedFields).each(function(i, taggedField) {
        //console.log('i: ' + i); console.log(taggedField);

        var replaceField = $('<select name="'+taggedField.name+'" style="max-width:90%;">');
        if (taggedField.lookup.length===0) {
            // disable if nothing to select
            replaceField.append($("<option>").attr('value','').text('No instances to select'));
            replaceField.prop('disabled', true).addClass('disabled');
        } else {
            // Make a select list with the appropriate options
            replaceField.append($("<option>"));
            // pick up parent instance from URL, possibly set by companion EM InstanceTable
            var parent_instance = <?php echo $parent_instance?>;
            var parent_selected = false;
            for (var optVal in taggedField.lookup) {
                if (optVal===taggedField.currentValue || optVal===parent_instance.toString()) {
                    replaceField.append($("<option>").attr('value',optVal).text(taggedField.lookup[optVal]).prop('selected', true));
                    parent_selected = true;
                } else {
                    replaceField.append($("<option>").attr('value',optVal).text(taggedField.lookup[optVal]));
                }
            }
            if (!parent_selected) {
              if (taggedField.currentValue) {
                replaceField.append($("<option>")
                  .attr('value', taggedField.currentValue).text(taggedField.currentValue + ': DELETED').prop
                  ('selected', true));
              } else if (parent_instance !== -1 ){
                replaceField.append($("<option>")
                  .attr('value', parent_instance).text(parent_instance + ': NEW').prop('selected', true));
              }
            }
        }

        // Replace the field text box input
        $('input:text[name="' + taggedField.name + '"]').replaceWith(replaceField);
    });
});
</script>
<?php
        }

        protected function getTagAndParam($fieldAnnotation) {
            foreach (array_keys(static::$Tags) as $tag) {
                    if (strpos($fieldAnnotation, $tag) !== false) {
                            return array($tag, Form::getValueInActionTag($fieldAnnotation, $tag));
                    }
            }
            return array(null, null);
        }

        protected function getLookupOptions($term, $param) {
                switch ($term) {
                    case '@RECORDINSTANCE': return $this->getArmRecordInstances($param); break;
                    case '@FORMINSTANCE': return $this->getFormInstances($param); break;
                    case '@EVENTINSTANCE': return $this->getEventInstances($param); break;
                    default: break;
                }
                return false;
        }

        protected function getArmRecordInstances($param) {
                global $custom_record_label;

                // $param can be comma-separated list of arm numbers (or if empty use arm of current record/event)
                // include arm name in labels if multiple in project
                $param = (is_null($param) || $param=='') ? ''.$this->Proj->eventInfo[$this->event_id]['arm_num'] : str_replace("'", '', $param);

                $recordArms = array();
                foreach (explode(',', $param) as $arm) {
                        if (array_key_exists($arm, $this->Proj->events)) { $recordArms[] = $arm; }
                }

                // get the event ids corresponding to the arms we need (i.e. first in each arm)
                $armEvents = array();
                foreach ($recordArms as $armNum) {
                        $armDetails = $this->Proj->events[$armNum];
                        $armEvents[key($armDetails['events'])] = array( // first event of each arm we need
                                'num' => $armNum,
                                'armlabel' => ($this->Proj->multiple_arms) ? $armDetails['name'] : '', // Include arm name in record label if project has multiple
                                'records' => array()
                        );
                }

                // read the record ids for the arms' first events (filtered for user DAG)
                $pk = REDCap::getRecordIdField();
                $recordIds = REDCap::getData('array', null, $pk, array_keys($armEvents), $this->user_rights['group_id']);

                foreach ($recordIds as $recordId => $eventData) {
                        foreach ($eventData as $eventId => $fieldData) {
                                if ($fieldData[$pk] !== '') { $armEvents[$eventId]['records'][] = $recordId; }
                        }
                }

                // get the record labels for the records
                $armRecordIdsAndLabels = array();
                foreach ($armEvents as $armEventId => $arm) {
                        if (count($arm['records'])>0) {
                                if ($this->isSurvey || empty($custom_record_label)) { // do not label records if survey in case record label contains PHI
                                        $armRecordIdsAndLabels[$armEventId] = array_fill_keys($arm['records'], ''); // array with record ids as keys, blank values (labels)
                                } else {
                                        $armRecordIdsAndLabels[$armEventId] = Records::getCustomRecordLabelsSecondaryFieldAllRecords($arm['records'], true, $arm['num']);
                                }
                        }
                }

                // reformat the array (remove arm level) into value, label
                $recordIdsAndLabels = array();
                foreach ($armRecordIdsAndLabels as $armEventId => $armRecs) {
                        $idArmPrefix = (count($recordArms)>1) ? $armEvents[$armEventId]['num'].':' : ''; // prefix record id with arm num if selecting from multiple
                        ksort($armRecs);
                        foreach ($armRecs as $rec => $recLabel) {
                                if (empty($custom_record_label)) {
                                        $recordIdsAndLabels[$idArmPrefix.$rec] = removeDDEending($rec).' ('.$armEvents[$armEventId]['armlabel'].')';
                                } else {
                                        $recordIdsAndLabels[$idArmPrefix.$rec] = removeDDEending($rec).' '.$recLabel.'('.$armEvents[$armEventId]['armlabel'].')';
                                }
                        }
                }
                return $recordIdsAndLabels;
        }

        protected function getFormInstances($param) {
                // if form repeats in more than one event can specify event with
                // @FORMINSTANCE=event_1_arm_1:my_form
                // otherwise return all values of eventx:form
                $lookupParams = explode(':', $param);
                if (count($lookupParams)>1) {
                        $eventId = REDCap::getEventIdFromUniqueEvent($lookupParams[0]);
                        $lookupForm = $lookupParams[1];
                        $lookupEvents = array(
                                $eventId =>
                                $this->Proj->RepeatingFormsEvents[$eventId][$lookupForm] // the repeating form label
                        );
                } else {
                        $lookupForm = $lookupParams[0];
                        $lookupEvents = array();
                        foreach ($this->Proj->RepeatingFormsEvents as $rptEventId => $thingsThatRepeat) { // an array of form names or 'WHOLE' for repeating events
                                if (is_array($thingsThatRepeat) && array_key_exists($lookupForm, $thingsThatRepeat)) {
                                        $lookupEvents[$rptEventId] = $thingsThatRepeat[$lookupForm]; // the repeating form label (might be different for form repeating in different events))
                                }
                        };
                }

                $eventPipedFormLabels = array();

                // annoyingly, RepeatInstance::getPipedCustomRepeatingFormLabels() gives
                // an array with one unlabelled instance if record exists in another
                // arm but has no form instances in that other arm
                foreach (array_keys($lookupEvents) as $eventId) {
                        $eventPipedFormLabels[$eventId] = $this->RepeatInstanceGetPipedCustomRepeatingFormLabelsMod($this->record, $eventId, $lookupForm);
                }


                // Make a list of events/forms for display
                // 1st determine whether there is more than one event to display
                // - if so we need value=event:instance and label=eventname: instance label
                // - if not we want value=instance and label=instance label
                if (count($eventPipedFormLabels) === 0) {
                        return false; // perhaps event incorrectly specified in param?
                } else if (count($eventPipedFormLabels) === 1) {
                        $displayEvent = false;
                } else {
                        $displayEvent = true; // form repeats in multiple events so show all
                }

                $selectItems = array();

                reset($eventPipedFormLabels);
                foreach ($eventPipedFormLabels as $eventId => $recordInstances) {
                        if ($displayEvent) {
                                $eventRef = REDCap::getEventNames(true, $this->Proj->multiple_arms, $eventId);
                                $eventName = REDCap::getEventNames(false, $this->Proj->multiple_arms, $eventId);
                        } else {
                                $eventRef = '';
                                $eventName = '';
                        }

                        foreach ($recordInstances[$this->record] as $instanceNum => $instanceLabel) {
                                $instanceLabel = (trim($instanceLabel)==='') ? $instanceLabel : ": $instanceLabel";
                                if ($displayEvent) {
                                        $selectItems[$eventRef.':'.$instanceNum] = "$eventName $instanceNum $instanceLabel";
                                } else {
                                        $selectItems[$instanceNum] = "$instanceNum $instanceLabel";
                                }
                        }
                }

                return $selectItems;
        }

        protected function getEventInstances($param) {
                $eventId = REDCap::getEventIdFromUniqueEvent($param);

                if ($eventId === false || !$this->Proj->isRepeatingEvent($eventId)) { return false; } // event ref incorrectly specified

                // find event instances
                $recordData = REDCap::getData('array', $this->record);//, REDCap::getRecordIdField()); //fields, events);

                $instancesHolder = $recordData[$this->record]['repeat_instances'][$eventId]; // repeating events have blank key at level between event and array of instances

                $selectItems = array();
                foreach ($instancesHolder as $instances) {
                        foreach (array_keys($instances) as $instance) {
                                $custom_event_label = Piping::replaceVariablesInLabel($this->Proj->eventInfo[$eventId]['custom_event_label'], $this->record, $eventId, $instance, $recordData, false, null, false);
                                $selectItems[$instance] = (trim($custom_event_label)==='') ? $instance : $instance.': '.filter_tags($custom_event_label, false, true);
                        }
                }

                return $selectItems;
        }

        // Retrieve the Custom Repeating Form Labels (for repeating instruments) with data piped in for one or more records on specified event/form.
        // Return array with record name as key, instance # as sub-array key with piped data as sub-array value.
        // If Custom Repeating Form Labels do not exist for this form, then return empty array.
        // This is a modified version of v7.5.2 RepeatInstance::getPipedCustomRepeatingFormLabels()
        // that does not return an instance in an alternative arm where no instance exists
        protected function RepeatInstanceGetPipedCustomRepeatingFormLabelsMod($records=array(), $event_id, $form_name)
        {
                $pipedFormLabels = array();
                // If not a repeating form, then return empty array
                if (!$this->Proj->isRepeatingForm($event_id, $form_name)) return array();
                // Gather field names of all custom form labels (if any)
                $pre_piped_label = $this->Proj->RepeatingFormsEvents[$event_id][$form_name];
                $custom_form_label_fields = array_keys(getBracketedFields($pre_piped_label, true, false, true));
                // Get piping data for this record
                $piping_data = Records::getData('array', $records, (count($custom_form_label_fields)===0)?array($form_name.'_complete'):$custom_form_label_fields, array_keys($this->Proj->RepeatingFormsEvents));
                // Loop through records/instances and add as piped to $pipedFormLabels
                foreach ($piping_data as $record=>&$attr) {
                        if (isset($attr['repeat_instances'][$event_id][$form_name])) {
                                // Loop through instances
                                foreach (array_keys($attr['repeat_instances'][$event_id][$form_name]) as $instance) {
                                        $pipedLabel = trim(Piping::replaceVariablesInLabel($pre_piped_label, $record, $event_id, $instance, $piping_data, false, null, false, $form_name));
                                        $pipedFormLabels[$record][$instance] = strip_tags($pipedLabel);
                                }
                        }
                }
                // Return the array containing the piped repeating form labels
                return $pipedFormLabels;
        }
}

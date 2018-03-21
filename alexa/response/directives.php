<?php
/**
 * Defines the dialog directive being sent. No updatedIntent allowed.
 */
 
/*
Example of a dialog directive:

"directives": [
		{
		  "type": "Dialog.ElicitSlot",
		  "slotToElicit": "fromCity",
		  
		  "updatedIntent": {
			 "name": "PlanMyTrip",
			 "confirmationStatus": "NONE",
			 "slots": {
				"toCity": {
				  "name": "toCity",
				  "confirmationStatus": "NONE"
				},
				"travelDate": {
				  "name": "travelDate",
				  "confirmationStatus": "NONE",
				  "value": "2017-04-21"
				},
				"fromCity": {
				  "name": "fromCity",
				  "confirmationStatus": "NONE"
				},
				"activity": {
				  "name": "activity",
				  "confirmationStatus": "NONE"
				},
				"travelMode": {
				  "name": "travelMode",
				  "confirmationStatus": "NONE"
				}
			 }
		  }
		}
	 ]
*/



namespace Alexa\Response;

class Directives {
	public $type = 'Dialog.ElicitSlot';
	public $slotToElicit = 'SlotA';

	/**
	 * Returns array of text for output with defined type of PlainText of SSML
	 * @return array
	 */
	public function render() {
		switch ( $this->type ) {
			case 'Dialog.ElicitSlot':
				return array(
					'type' => $this->type,
					'slotToElicit' => $this->slotToElicit,
					// updatedIntent would go here.
				);
			case 'Dialog.ConfirmIntent':
				return array(
					'type' => "Dialog." . $this->type,
					// updatedIntent would go here.
				);
		}
	}
	
	
}

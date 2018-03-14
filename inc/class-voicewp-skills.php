<?php

namespace Alexa;


/**
 * Class file for a list of Skill Types for VoiceWP.
 *
 * @package VoiceWP
 */

/**
 * 
 */
class Skills {

	public $types = array();

	/**
	 * Set up.
	 *
	 * @return array       class names : class title for display
	 */
	public function __construct( ) {
		$this->update_list();
	}

	/**
	 * Rebuild the list of skill types
	 *
	 * @return array       (skill types)
	 */
	public function update_list( ) {
		$this->types = $this->get_skill_list();
		return $this->types;
	}

	/**
	 * Build the list of classes from the class file names in the alexa/skill dir.
	 *
	 * @return array       (skill types)
	 */
	private function get_skill_list() {
		
		// Path to skill class files
		$path = VOICEWP_PATH . DIRECTORY_SEPARATOR . 'alexa' . DIRECTORY_SEPARATOR . 'skill';

		$types = array();
		
		foreach (glob("{$path}/*.php") as $filename)
		{	
			$class_name = str_replace(".php", "", basename($filename) );
			$class_title = ucwords(str_replace(['_', '-'], " ", $class_name));
			$types[$class_name] = $class_title;
		}
		return $types;
	}
	
}

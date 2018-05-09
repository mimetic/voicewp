<?php

namespace Alexa\Skill;

use Alexa\Request\IntentRequest;

/**
 * Class that creates a custom skill allowing WordPress content to be consumed via Alexa
 */
class Explore {

	/**
	 * @var array
	 * Intents supported by this skill type
	 */
	public $intents = array(
		'Latest',
		'LatestTerm',
		'ReadPost',
		'ReadPostByKeyword',
		'ReadPostByID',
		'AMAZON.StopIntent',
		'AMAZON.HelpIntent',
		'AMAZON.CancelIntent',
	);
	
	private $request;
	private $response;
	private $card = "";

	/**
	 * Figures out what kind of intent we're dealing with from the request
	 * Handles grabbing the needed data and delivering the response
	 * @param AlexaEvent $event
	 */
	public function skill_request( $request, $response ) {

error_log("\n\n**********************************************************************");
error_log("------- " . __FUNCTION__ . "-------");

		if ( $request instanceof \Alexa\Request\IntentRequest ) {
			$intent = $request->intent_name;
			$dialog_state = $request->dialog_state;
			
		// Get the list of related posts
		!empty($request->session->attributes['post_id_list'])
		? $post_id_list = $request->session->attributes['post_id_list']
		: $post_id_list = array();

		// Get the list of related posts
		!empty($request->session->attributes['read_posts_list'])
		? $read_posts_list = $request->session->attributes['read_posts_list']
		: $read_posts_list = array();
		
		// Just to be sure, probably unnecessary
		gettype($read_posts_list) != "array" && $read_posts_list = array ( $read_posts_list );



			error_log("------- explore::skill_request( $intent ) -------");
			error_log("------- explore::dialog_state( $dialog_state ) -------");

			
			switch ( $intent ) {
			
				case 'ResetReadPostsList':
					$read_posts_list = array();
					$response
							->respond_ssml( "OK, I reset the list of read posts." )
							->with_card( "Reset read posts list", $this->card)
							->add_session_attribute('read_posts_list', $read_posts_list);
					break;
			
				case 'LatestTerm':
					$term_slot = strtolower( sanitize_text_field( $request->getSlot( 'TermName' ) ) );
					$term_slot = apply_filters( 'voicewp_filter_term_slot_result', $term_slot );
					
					$tax_query = $this->get_latest_term_query ($term_slow, $request, $response);
					

					// No break. Logic continues into Latest case
				case 'Latest':
					/* Since the above switch statement doesn't break,
					 * it will continue running into this block,
					 * which allows the below $tax_query var to be set,
					 * so at first glance it may look slightly confusing,
					 * but this keeps the code DRY
					 */

					// Search for posts, then return a list of posts for the user to choose from.					
					$args = array(
						'post_type' => voicewp_news_post_types(),
						'posts_per_page' => 5,
					);

					if ( isset( $tax_query ) ) {
						$args['tax_query'] = array( $tax_query );
					}

					$result = $this->endpoint_content( $args );

					$voicewp_settings = get_option( 'voicewp-settings' );
					$skill_name = ( ! empty( $voicewp_settings['skill_name'] ) ) ? $voicewp_settings['skill_name'] : get_bloginfo( 'name' );
					$prompt = ( ! empty( $voicewp_settings['list_prompt'] ) ) ? $voicewp_settings['list_prompt'] : __( 'Which article would you like to hear?', 'voicewp' );

					$response
						->respond( $result['content'] . $prompt )
						/* translators: %s: site title */
						->with_card( sprintf( __( 'Latest from %s', 'voicewp' ), $skill_name ), ( ( ! empty( $result['card_content'] ) ) ? $result['card_content'] : '' ) )
						->add_session_attribute( 'post_id_list', $result['ids'] );
					break;
					
					
				// ========================================
				// Read a post
				// Uses dialog to choose criteria: keyword or search term.
				// If the keyword provided is in the list of tags, then use the session post_id_list to
				// get the post ID. The post_id_list come from the last-read post; if this is a new search,
				// there will not be any post_id_list in the session.
				// If not, use it for text search.
				// "...posts about {Keyword}"
				case 'ReadPostByKeyword':
					
					$posts = null;
					$keyword = strtolower( sanitize_text_field( $request->getSlot( 'Keyword' ) ) );
					

					if ( empty($keyword) ) {

					// ========================================
					// No keyword (how is this possible?)
					// Ask for user response: keyword, stop, next, or yes/no (for 'continue?').
					// ========================================

$this->addToCard("ReadPostByKeyword : A : No Keyword");
$this->addToCard("No keyword, ask for a keyword (or stop, or next, or yes/no.");


						// If there is a list of related articles, user should choose one.
						// If no list of related articles, we choose one.
						$post_id_list
						? $speech = "<speak>Search for which topic?</speak>"
						: $speech = "<speak>Continue reading the news?</speak>";

						$response
							->respond_ssml( $speech )
							->with_directives ( 'Dialog.ElicitSlot', 'Keyword')
							->add_session_attribute('post_id_list', null);





					} elseif ( $keyword && !empty($post_id_list) ) {

						// ========================================					
						// Keyword + List of Posts: use the keyword to choose from the list,
						// (or play next, or stop)
						// (or choose by ordinal number, e.g. "First"
						// If there is no match, do a search.
						// ========================================
						$keys = array_keys($post_id_list);

						// Convert an ordinal keyword ("1st") to an index, to see if it refers to 
						// one of the items in the list of posts.
						$index = (int)$this->ordinal_to_integer( $keyword );
						if ( $index && ($index < count($post_id_list)) ) {
							$index = $index - 1;
							$keyword = $keys[$index];

							$this->addToCard("ORDINAL");
							$this->addToCard ("Keys:" . implode(" | ", $keys));
							$this->addToCard("keyword: $keyword, index #".$index);
							$this->addToCard("post_list key = " . $keys[$index]);

						}
							
						//$post_id = $post_id_list[$keys[$index]];

						
						// Is the keyword in the list of related post keywords?

						if ( !empty($post_id_list[$keyword]) ) {
							$post_id = $post_id_list[$keyword];
							
						} elseif ("next" == $keyword || "yes" == $keyword) {
							// Choose first item in list of related posts (should only be one!)
							$post_id = $post_id_list[$keys[0]];
						
						} elseif ("no" == $keyword ) {
							// Use did not want something from the current list, user should ask for
							// a new topic.
							$speech = "Ask me for posts about a new subject.";
							$speech = "<speak>{$speech}</speak>"; 
							$response
									->respond_ssml( $speech )
									->with_card( "ReadPostByKeyword : 'No' reply", $this->card);
							break;
						
						} elseif ("stop" == $keyword ) {
							$this->addToCard("STOP response.");
							$this->message( $response, 'stop_intent' );
							break;
							
						} elseif ( in_array($keyword, [ "reset", "clear", "forget"] ) ) {
error_log("Cleared the read posts list.");
							// don't need, just don't pass it along!
							$read_posts_list = [];
							$this->addToCard("Clear read list.");
							$this->message( $response, 'reset_intent' );
							break;
							
						} else {
							
							// Unknown response.
							// Search all tags for this keyword, get ALL posts with this keyword
							// Exclude posts already read in this session.
							$args = $this->args_for_post_by_tag ( $keyword, $response, $request, 1 );
							if ($args) {
								$args = array_merge( $args, array(
									'exclude' => $read_posts_list
								) );
								$posts = get_posts( $args );
							} else {
								$posts = [];
							}

// error_log("Search args: " . print_r($args, true) );
// error_log("Search for posts by tag: {$keyword}");
							
							if ( !empty($posts) ) {
								// Get the first post only
								$post_id = $posts[0]->ID;
error_log("Found post {$post_id}");
							} else {
								$post_id = null;
error_log("No posts found with tag {$keyword}");
								$this->message( $response, 'unknown_tag_error', $request, $keyword );
							}
						}



// ReadPostByKeyword : B

						if (!empty($post_id)) {			

error_log("------- ReadPostByKeyword : B -------");
error_log("Post ID found: #{$post_id}");

							// Get the post text and the list of related posts to read 
							$result = $this->endpoint_single_post( $post_id );
							$content = $result['content'];

							// Get the list of related posts
							$post_id_list = $this->get_related_post_id_list($post_id, $read_posts_list);
							$read_posts_list[$post_id] = $post_id;
							
							// Create the related posts text to read
							$related = $this->build_related_posts_text ( $post_id, $read_posts_list, $request, $response );
							
							$footer = '<break time="0.5s"/>' . $related;
							$speech = $content . $footer;
							$speech = "<speak>{$speech}</speak>";

							$this->addToCard("Read Post List: ".implode(", ", $read_posts_list));
							$this->addToCard("post_id to speak: $post_id");
							$this->addToCard("keyword: $keyword");
							$this->addToCard("Post ID found to match keyword: $post_id" );
							$this->addToCard("related post ID's:" . implode(", ", $post_id_list ) );

							$response
									->respond_ssml( $speech )
									->with_directives ( 'Dialog.ElicitSlot', 'Keyword')
									->with_card( "ReadPostByKeyword : B", $this->card)
									->add_session_attribute('read_posts_list', $read_posts_list)
									->add_session_attribute('post_id_list', $post_id_list);
									//->with_card( $result['title'], '', $result['image'] )
									// This would end the session!
									//->end_session();

						

						} else {
						
							$this->addToCard("Oops, no post id!" );

							$this->message( $response, '', $response );
						}


					} else {
						
						// =========
						// Keyword exists, but no list of posts to choose from,
						// so use the keyword to find the first tagged post.
						// Search all tags for this keyword, get first post found
						$args = $this->args_for_post_by_tag ( $keyword, $response, $request, 1 );
						if ($args) {
							$args = array_merge( $args, array(
								'exclude' => $read_posts_list
							) );
							$posts = get_posts( $args );
						} else {
							$posts = [];
						}

//error_log("Search args: " . print_r($args, true) );						

						// Check the keyword: is it a stop or cancel?
						if (in_array($keyword, ["stop", "quit", "never mind" ]) ) {
							$this->addToCard("STOP response.");
							$this->message( $response, 'stop_intent' );
							break;
						}



error_log("------- ReadPostByKeyword : C -------");
error_log("(No list of related posts passed in session variable.)");

						if ($posts) {

							$response->add_session_attribute("post_id_list", $post_id_list) ;
							$post = $posts[0];
							$post_id = $post->ID;

							$post_id_list = $this->get_related_post_id_list($post_id, $read_posts_list);
							$read_posts_list[$post_id] = $post_id;

error_log("post list exists, looking for '$keyword' : " . implode(", ", $post_id_list ) );

							
							
							// Output -------
							
							$result = $this->endpoint_single_post( $post_id );
							
							$content = $result['content'];
												
							( $related = $this->build_related_posts_text ( $post_id, $read_posts_list, $request, $response ) )
							? $footer = '<break time="0.5s"/>' . $related
							: $footer = '';
						
							$speech = $content . $footer;
							$speech = "<speak>{$speech}</speak>";

							$response
									->respond_ssml( $speech )
									->with_card( $this->card)
									->add_session_attribute('post_id_list', $post_id_list);
							$response
									->add_session_attribute('read_posts_list', $read_posts_list);
									//->with_card( $result['title'], '', $result['image'] )
									// This would end the session!
									//->end_session();
									
							if ($related)
								$response->with_directives ( 'Dialog.ElicitSlot', 'Keyword');

						} else {

							// Error: Cannot find posts with that tag

						if (in_array($keyword, [ "reset", "clear", "forget"] ) ) {
error_log("Cleared the read posts list.");
							$read_posts_list = [];
							$this->message( $response, 'reset_intent', $request, $keyword );
							break;
						}

							
							
error_log("Error: Cannot find posts with keyword {$keyword}");

							$response
									->with_directives ( 'Dialog.ElicitSlot', 'Keyword')
									->with_card( $this->card)
									->add_session_attribute('post_id_list', $post_id_list);
							$response
									->add_session_attribute('read_posts_list', $read_posts_list);
									
							$this->message( $response, 'unknown_tag_error', $request, $keyword );

						}
						

					}
					break;
					
				// ========================================
				// "Read the first/second/third/fourth/fifth post"
				// Used for choosing from a list of posts.
				case 'ReadPostByNumber':

error_log("------- ReadPostByNumber -------");
error_log("PostNumber: " . $request->getSlot( 'PostNumber' ));
error_log("PostNumberWord: " . $request->getSlot( 'PostNumberWord' ));


				
					// choose post ID from a list of pasts, based on provided keyword or number,
					// e.g. "...read the second"
					if ( $post_number = $request->getSlot( 'PostNumberWord' ) ) {
						if ( 'second' === $post_number ) {
							/**
							* Alexa Skills Kit passes 'second' instead of '2nd'
							* unlike the case for all other numbers.
							*/
							$post_number = 2;
						} else {
							$post_number = substr( $post_number, 0, -2 );
						}
					} else {
						$post_number = $request->getSlot( 'PostNumber' );
					}

					if ( ! empty( $request->session->attributes['post_id_list'] ) && ! empty( $post_number ) ) {
						$post_id = $this->get_post_id( $request->session->attributes['post_id_list'], $post_number );
						if ( ! $post_id ) {
							$this->message( $response, 'number_slot_error', $request );
						} else {
							$result = $this->endpoint_single_post( $post_id );
							
						$content = $result['content'];


						// Depends on the "Related Posts for WordPress" plugin!
						$post = get_post($post_id);
						$related_post_html = rp4wp_children( $post_id, false );

						$related = "";
						$pause = '<break time="0.2s"/>';
						
						$post_id_list = $this->get_related_post_id_list($post_id, $read_posts_list);
						$read_posts_list[$post_id] = $post_id;

error_log ("From inside of the main code:" . print_r($post_id_list, true));

						$response->add_session_attribute("post_id_list", $post_id_list) ;
						
						$related = " Ask me to say more about {$related}?";

						$footer = '<break time="0.5s"/>' . $related;
						
						$speech = $content . $footer;


						$speech = "<speak>{$speech}</speak>";
						$response
								->respond_ssml( $speech )
								->with_card( $result['title'], '', $result['image'] )
								->add_session_attribute('post_id_list', $post_id_list);
							$response									
								->add_session_attribute('read_posts_list', $read_posts_list);
								// This would end the session!
								//->end_session();
						}
					} else {
						$this->message( $response, '', $request, $keyword );
					}
					break;
					

				// ==============================
				// example: "Read post id {PostID}"
				case 'ReadPostByID':
					$post_id = strtolower( sanitize_text_field( $request->getSlot( 'PostID' ) ) );
					$keyword = strtolower( sanitize_text_field( $request->getSlot( 'Keyword' ) ) );

error_log("------- ReadPostByID -------");
error_log("post_id: " . $post_id);
error_log("keyword: " . $keyword);

					if ($keyword) {
						// The sessionAttributes have a list of posts
						$post_id_list = $request->session->attributes['post_id_list'];
						$keyword && $post_id_list[$keyword] 
						? $post_id = $post_id_list[$keyword] 
						: $post_id = null;

					// Old concept: get the post ID from the slug, if it exists.
					/*
					$slug = strtolower( sanitize_text_field( $request->getSlot( 'Slug' ) ) );
					if ($slug) {
						$page = get_page_by_path( $shortcode, OBJECT, "post" );
						if ($page) {
							$post_id = $page->ID;
						}
					}
					*/

					
						error_log("post_id: $post_id");
						error_log("keyword: $keyword");
						error_log(print_r($post_id_list, true));
						//error_log(print_r($request->session, true));
						error_log("-------");
 					
 						
 						if (!$post_id) {
 							$speech = "<speak>I don't know about that.</speak>";
	 						$response
								->respond_ssml( $speech )
								->with_directives ( 'Dialog.ElicitSlot', 'Keyword')
								->add_session_attribute('post_id_list', $post_id_list);
							$response
								->add_session_attribute('read_posts_list', $read_posts_list);
							break;
 						}
 					}
										
					if ( ! $post_id ) {
						$this->message( $response, 'post_id_error', $request );
					} else {
						$this->set_response_to_post_by_id ( $post_id, $request, $response );						
						$response->with_directives ( 'Dialog.ElicitSlot', 'Keyword');
					}
					break;
					
					
				// ==============================
				case 'AMAZON.StopIntent':
				case 'AMAZON.CancelIntent':
$response->with_card( "Intent: Stop or Cancel.");
					$this->message( $response, 'stop_intent' );
					break;
				case 'AMAZON.HelpIntent':
$response->with_card( "Intent: Help.");
					$this->message( $response, 'help_intent' );
					break;
					
				case 'AMAZON.FallbackIntent':
					$response->with_card( "Intent: FallbackIntent");
//						->with_directives ( 'Dialog.ElicitSlot', 'Keyword');
					$this->message( $response, 'fallback_intent', $request, $keyword );	// default error
					break;
					
				default:
$this->addToCard("Unknown intent: $intent");
					$this->skill_intent( $intent, $request, $response );
					break;
			}
		} elseif ( $request instanceof \Alexa\Request\LaunchRequest ) {
			$this->message( $response, 'launch_request' );
		}
	}


	/**
	 * Is a string an ordinal, e.g. first, tenth
	 * @param string $tag text that might be an ordinal
	 * @return integer|false	e.g. 1st = 1, second = 2
	 */
	private function ordinal_to_integer ( $t ) {
		$t = trim(strtolower($t));
		
		$digith = array(false, 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth', 'thirteenth', 'fourteenth', 'fiftheenth', 'sixteenth', 'seventeenth', 'eighteenth', 'nineteenth');	
		$digith2 = array(false, '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th');
		
		array_search($t, $digith)
		? $i =  array_search($t, $digith)
		: $i =  array_search($t, $digith2);

		return $i;
	}

	/**
	 * Return the Alexa version of an ordinal number, i.e. 1 => 1st, 2 = 2nd, 3 = 3rd
	 * @param string $tag text that might be an ordinal
	 * @return string The text version of a number, e.g. 1 => "first"
	 */
	private function get_ordinal ( $n ) {
		$n == "second" && $n = "2nd";
		$ordinal = array('', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th');
		
		//$ordinal = array('', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth', 'thirteenth', 'fourteenth', 'fiftheenth', 'sixteenth', 'seventeenth', 'eighteenth', 'nineteenth');
		
		return $ordinal[$n];
	}


	/**
	 * Add text to the card variable for return to Alexa
	 * @param string $t Text to add
	 */
	private function addToCard ( $t ) {
		$this->card .= "\n" . $t;
	}


	/**
	 * Create a query to look for the most recent posts with the latest term in the chosen taxonomies (tag/category)
	 * @param string $term_slot The text to search for
	 * @return object $tax_query The WordPress query
	 */
	private function get_latest_term_query ( $term_slot, $request, $response ) {
		$tax_query = null;
		
		if ( $term_slot ) {
			$news_taxonomies = voicewp_news_taxonomies();

			if ( $news_taxonomies ) {
				/*
				 * TODO:
				 *
				 * Support for 'name__like'?
				 * Support for an 'alias' meta field?
				 * Support for excluding terms?
				 */
				$terms = get_terms( array(
					'name' => $term_slot,
					'taxonomy' => $news_taxonomies,
				) );

				if ( $terms ) {
					// 'term_taxonomy_id' query allows omitting 'taxonomy'.
					$tax_query = array(
						'terms' => wp_list_pluck( $terms, 'term_taxonomy_id' ),
						'field' => 'term_taxonomy_id',
					);
				}
			}
		
			if ( ! isset( $tax_query ) ) {
				$this->message( $response );
			}
		}
		return $tax_query;
	}



	/**
	 * List of keywords to choose from.
	 * Speak to user after an article; ask user to choose one.
	 * @param string $tag name of the tag to search for
	 * Search for posts with matching tags
	 */
	private function build_related_posts_text ( $post_id, $read_posts_list, $request, $response ) {

//error_log (__FUNCTION__);

		// Depends on the "Related Posts for WordPress" plugin!
		$post = get_post($post_id);
		$related_post_html = rp4wp_children( $post_id, false );
		// This will extract the paths & shortcodes from the urls
		$p = "|'" . get_site_url() . "/(.*?)/'>|";
		preg_match_all($p, $related_post_html, $matches, PREG_PATTERN_ORDER);

		$related = "";
		$pause = '<break time="0.2s"/>';
		$post_id_list = array();
		
		if ($matches[1]) {
		
			// Build a list from the excerpts, which contain the keyword for the post
			$k = 1;
			foreach ($matches[1] as $shortcode) {
				$page = get_page_by_path( $shortcode, OBJECT, "post" );
				if ($page->post_excerpt) {
					$post_name = sanitize_text_field($page->post_excerpt);
				} else {
					$post_name = $this->get_ordinal($k);
				}
				
				if ( !in_array($page->ID, $read_posts_list) ) {
					$related
					? $related = $related . " or " . $post_name . $pause
					: $related = $post_name . $pause;
			
					// Add the ID's as session attributes
					$post_id_list[strtolower($post_name)] = $page->ID;
					$k++;
				}

			}
			

			// Clear session attributes, then add our $post_id_list
			$response->add_session_attribute("post_id_list", $post_id_list) ;		
			$related = " Ask me to say more about {$related}?";
			
		} else {
			// no related posts
			$related = " Continue reading the news?";
		}
		
		return $related;
	}


	/**
	 * Create arguments for get_posts to
	 * @param string $tag name of the tag to search for
	 * @param string $response TK
	 * @param string $request TK
	 * @param string $posts_per_page Number of posts to return, default -1 = all posts found
	 * Search for posts with matching tags
	 */
	private function args_for_post_by_tag ( $tag, $response, $request, $posts_per_page = -1 ) {

		$args = [];
		
		if ( $tag ) {
			// taxonomies to search, e.g. tags, categories, etc.
			// Use Tag if nothing chosen
			// Use the name of the tag, not the slug or id.
			$news_taxonomies = voicewp_news_taxonomies();
			$news_taxonomies || $news_taxonomies = [ 'post_tag'];
			
			$tax_query = array();
			foreach ($news_taxonomies as $taxonomy) {
			
	//error_log(__FUNCTION__ . ": news_taxonomies (" . implode(", ", $news_taxonomies) );

				$tax_query[] = array (
						'taxonomy'	=> $taxonomy,
						'field' => 'name',
						'terms'	=> $tag
					);

	//error_log(__FUNCTION__ . ": tax_query:" . print_r($tax_query, true) );
			
			} //foreach

			// Search for posts, then return a list of posts for the user to choose from.					
			$args = array(
				'post_type' => voicewp_news_post_types(),
				'posts_per_page' => $posts_per_page,
				'post_status' => 'publish',
				'orderby' => 'date',
				'order' => 'DESC',
				'tax_query'	=> $tax_query
			);

		}

		return $args;
	}


	/**
	 * Create arguments for get_posts to
	 * Search body of posts with a search term
	 * Only search posts with the selected category
	 * @param string $term text to search for in the post body
	 */
	private function find_post_by_term( $term, $category_slug = "voicewp" ) {

		$args = [];
		
		if ( $term ) {

			// Search for posts, then return a list of posts for the user to choose from.					
			$args = array(
				'category_name'	=> $category_slug,
				'post_type' => voicewp_news_post_types(),
				'posts_per_page' => 3,
				's'	=> $term
			);
		}
		// return the results
		return $args;

	}


	/**
	 * Handles intents that come from outside the main set of News skill intents
	 * @param string $intent name of the intent to handle
	 * @param AlexaRequest $request
	 * @param AlexaResponse $response
	 */
	private function skill_intent( $intent, $request, $response ) {
		$custom_skill_index = get_option( 'voicewp_skill_index_map', array() );
		if ( isset( $custom_skill_index[ $intent ] ) ) {
			$voicewp = Voicewp::get_instance();
			$voicewp->skill_dispatch( absint( $custom_skill_index[ $intent ] ), $request, $response );
		}
	}


	/**
	 * Gets formatted post data given the post ID
	 * Set the responce from the post information, e.g. text to speak, card info, etc.
	 * @param int $id ID of post to get data for
	 * @return array Data from the post being returned
	 */
	private function set_response_to_post_by_id ( $post_id , $request, &$response ) {

		$result = $this->endpoint_single_post( $post_id );		
		$content = $result['content'];

		// Depends on the "Related Posts for WordPress" plugin!
		$post = get_post($post_id);
		$related_post_html = rp4wp_children( $post_id, false );
		// This will extract the paths & shortcodes from the urls
		$p = "|'" . get_site_url() . "/(.*?)/'>|";
		preg_match_all($p, $related_post_html, $matches, PREG_PATTERN_ORDER);

		$related = $this->build_related_posts_text ( $post_id, $read_posts_list, $request, $response );
		$related ? $footer = '<break time="0.5s"/>' . " Ask me to say more about {$related}?" : $footer = "";

		$speech = $content . $footer;
		$this->speech = $speech;
		$speech = "<speak>{$speech}</speak>";

		$response
			->respond_ssml( $speech )
			->with_card( $result['title'], $content, $result['image'] )
			->add_session_attribute('post_id_list', $post_id_list) ;
	}




	/**
	 * Gets formatted post data given the post ID
	 * Set the responce from the post information, e.g. text to speak, card info, etc.
	 * Do NOT include post that were already read!
	 * @param int $id ID of post to get data for
	 * @return array Data from the post being returned
	 */
	private function get_related_post_id_list ( $post_id, $read_posts_list ) {

		// Depends on the "Related Posts for WordPress" plugin!
		$post = get_post($post_id);
		$related_post_html = rp4wp_children( $post_id, false );

		// This will extract the paths & shortcodes from the urls
		$p = "|'" . get_site_url() . "/(.*?)/'>|";
		preg_match_all($p, $related_post_html, $matches, PREG_PATTERN_ORDER);

		$k = 1;
		$post_id_list = array();
		foreach ($matches[1] as $shortcode) {
			$page = get_page_by_path( $shortcode, OBJECT, "post" );
			if ($page->post_excerpt) {
				$post_name = sanitize_text_field($page->post_excerpt);
			} else {
				// return 1,2,3, etc. as a string because some languages don't keep orders of arrays
				// and if someone were to translate this logic, it would break on numeric indices.
				$post_name = strval($k);
			}
			
			if ( !in_array($page->ID, $read_posts_list) ) {
				$post_id_list[strtolower($post_name)] = $page->ID;
				$k++;
			}
		}
		
		return $post_id_list;
	}


	/**
	 * Gets formatted post data given the post ID
	 * Set the responce from the post information, e.g. text to speak, card info, etc.
	 * @param int $id ID of post to get data for
	 * @return array Data from the post being returned
	 */
	private function add_post_to_list ( $post_id ) {

		// Depends on the "Related Posts for WordPress" plugin!
		$post = get_post($post_id);
		$related_post_html = rp4wp_children( $post_id, false );

		// This will extract the paths & shortcodes from the urls
		$p = "|'" . get_site_url() . "/(.*?)/'>|";
		preg_match_all($p, $related_post_html, $matches, PREG_PATTERN_ORDER);

		$k = 1;
		$post_id_list = array();
		foreach ($matches[1] as $shortcode) {
			$page = get_page_by_path( $shortcode, OBJECT, "post" );
			if ($page->post_excerpt) {
				$post_name = sanitize_text_field($page->post_excerpt);
			} else {
				// return 1,2,3, etc. as a string because some languages don't keep orders of arrays
				// and if someone were to translate this logic, it would break on numeric indices.
				$post_name = strval($k);
			}

			$post_id_list[strtolower($post_name)] = $page->ID;
			$k++;
		}
		
		return $post_id_list;
	}
	

	/**
	 * Gets formatted post data that will be served in the response
	 * @param int $id ID of post to get data for
	 * @return array Data from the post being returned
	 */
	private function endpoint_single_post( $id ) {
		$transient_key = 'voicewp_single_' . $id;
		if ( false === ( $result = get_transient( $transient_key ) ) ) {
			$single_post = get_post( $id );
			$result = $this->format_single_post( $id, $single_post );
			// Set long cache time instead of 0 to prevent autoload
			set_transient( $transient_key, $result, WEEK_IN_SECONDS );
		}
		return $result;
	}


	/**
	 * Packages up the post data that will be served in the response
	 * @param int $id ID of post to get data for
	 * @param Object $single_post Post object
	 * @return array Data from the post being returned
	 */
	public function format_single_post( $id, $single_post ) {
		$voicewp_instance = \Voicewp_Setup::get_instance();
		// Strip shortcodes and markup other than SSML
		$post_content = html_entity_decode( wp_kses( strip_shortcodes( preg_replace(
			array(
				'|^(\s*)(https?://[^\s<>"]+)(\s*)$|im',
				'/<script\b[^>]*>(.*?)<\/script>/is',
			),
			'',
			$single_post->post_content
		) ), $voicewp_instance::$ssml ) );
		// Apply user defined dictionary to content as ssml
		$dictionary = get_option( 'voicewp_user_dictionary', array() );
		if ( ! empty( $dictionary ) ) {
			$dictionary_keys = array_map( function( $key ) {
				return ' ' . $key;
			}, array_keys( $dictionary ) );
			$post_content = str_ireplace( $dictionary_keys, $dictionary, $post_content );
		}
		return array(
			'content' => $post_content,
			'title' => $single_post->post_title,
			'image' => get_post_thumbnail_id( $id ),
		);
	}

	/**
	 * Gets a post ID from an array based on user input.
	 * Handles the offset between user selection of post in a list,
	 * and zero based index of array
	 * @param array $post_id_list Array of IDs that were listed to the user
	 * @param in $number User selection from list
	 * @return int The post the user asked for
	 */
	private function get_post_id( $post_id_list, $number ) {
		$number = absint( $number ) - 1;
		if ( ! array_key_exists( $number, $post_id_list ) ) {
			return;
		}
		return absint( $post_id_list[ $number ] );
	}

	/**
	 * Deliver a message to user
	 * @param AlexaResponse $response
	 * @param string $case The type of message to return
	 */
	private function message( $response, $case = 'missing', $request = false, $val='' ) {
		
		$pause = '<break time="0.1s"/>';
		
		$voicewp_settings = get_option( 'voicewp-settings' );
		if ( isset( $voicewp_settings[ $case ] ) ) {
			$response->respond( $voicewp_settings[ $case ] );
			
		} elseif ( 'number_slot_error' == $case ) {
			$response
				->respond( __( 'You can select between one and five, please select an item within that range.', 'voicewp' ) )
				->add_session_attribute( 'post_id_list', $request->session->get_attribute( 'post_id_list' ) );

		} elseif ( 'post_id_error' == $case ) {
			$response
				->respond( __( 'I do not have post id ' . $request->getSlot( 'PostID' ) . '.', 'voicewp' ) );

		} elseif ( 'unknown_tag_error' == $case ) {
			$response
				->respond_ssml( __( '<speak>I cannot find any unread posts tagged with ' . $val . '. Try a different topic, or say reset ' . $pause . ' or clear ' . $pause . ' to clear the list.</speak>', 'voicewp' ) );

		} elseif ( 'unknown_keyword_error' == $case ) {
			$val || $val == "the list";
			$response
				->respond( __( 'Please choose from $val, or say stop.', 'voicewp' ) );

		} elseif ( 'reset_intent' == $case ) {
			$response
				->respond( __( 'OK, I reset the list of unread posts.', 'voicewp' ) );

		} elseif ( 'fallback_intent' == $case ) {
			$response
				->respond( __( "I don't understand. Ask me to search for a topic.", 'voicewp' ) );

		} else {
			//$response->respond( __( "Sorry! I couldn't find any news about that topic. Ask me to search for something else.", 'voicewp' ) );
			$response->respond( __( "No news about {$val}. Ask me to search for something else.", 'voicewp' ) );
		}

		if ( 'stop_intent' === $case ) {
			$response->end_session();
		}
	}

	/**
	 * Creates output when a user asks for a list of posts.
	 * Delivers an array containing a numbered list of post titles
	 * to choose from and a subarray of IDs that get set in an attribute
	 * @param array $response
	 * @return array array of post IDs and titles
	 */
	public function endpoint_content( $args ) {
		$transient_key = isset( $args['tax_query'][0]['terms'][0] ) ? 'voicewp_latest_' . $args['tax_query'][0]['terms'][0] : 'voicewp_latest';
		if ( false === ( $result = get_transient( $transient_key ) ) ) {
			$news_posts = get_posts( array_merge( $args, array(
				'post_status' => 'publish',
			) ) );

			$content = $card_content = '';
			$post_id_list = array();
			if ( ! empty( $news_posts ) && ! is_wp_error( $news_posts ) ) {

				foreach ( $news_posts as $key => $news_post ) {
					// Appending 'th' to any number results in proper ordinal pronunciation
					// TODO: Sounds a little strange when there's only one result.
					$content .= ( $key + 1 ) . 'th, ' . $news_post->post_title . '. ';
					$card_content .= ( $key + 1 ) . '. ' . $news_post->post_title . "\n";
					$post_id_list[] = $news_post->ID;
				}
			}

			$result = array(
				'content' => $content,
				'ids' => $post_id_list,
				'card_content' => $card_content,
			);
			/**
			 * If this is the main latest feed, the content will be cleared
			 * when a post is published. We're setting a very long defined cache time
			 * so that if it's on a site without external object cache, it won't be autoloaded.
			 * For taxonomy feeds, cache for 15 minutes
			 */
			$expiration = ( 'voicewp_latest' == $transient_key ) ? WEEK_IN_SECONDS : 15 * MINUTE_IN_SECONDS;

			set_transient( $transient_key, $result, $expiration );
		}
		return $result;
	}
}


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

	/**
	 * Figures out what kind of intent we're dealing with from the request
	 * Handles grabbing the needed data and delivering the response
	 * @param AlexaEvent $event
	 */
	public function skill_request( $request, $response ) {

		if ( $request instanceof \Alexa\Request\IntentRequest ) {
			$intent = $request->intent_name;
			
			//$post_id = null;
			
			/*
			$slot = strtolower( sanitize_text_field( $request->getSlot( 'SlotA' ) ) );
			if ($slot) {
				$intent = "ReadPostByID";
				$post_id = 1;
				//$post_id_list;
			}
			*/
			

			error_log("------- explore::skill_request( $intent ) -------");

			
			switch ( $intent ) {
			
				case 'LatestTerm':
					$term_slot = strtolower( sanitize_text_field( $request->getSlot( 'TermName' ) ) );
					$term_slot = apply_filters( 'voicewp_filter_term_slot_result', $term_slot );
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
							break;
						}
					}
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
					
					// Criteria for search exists?
					$keyword = strtolower( sanitize_text_field( $request->getSlot( 'Keyword' ) ) );
					
					isset($request->session->attributes['post_id_list'])
					? $post_id_list = $request->session->attributes['post_id_list']
					: $post_id_list = null;

					if ( empty($keyword) ) {
					
error_log("------- ReadPostByKeyword : A -------");
//error_log( print_r ($request, true) );
error_log("No keyword, start dialog.");


						// Dialog to get the keyword
						
if ($post_id_list)
	error_log(print_r($post_id_list, true));

						$speech = "<speak>Choose a topic.</speak>";
						$response
							->respond_ssml( $speech )
							->with_directives ( 'Dialog.ElicitSlot', 'Keyword')
							->add_session_attribute('post_id_list', $post_id_list);

	 				// ========================================					
 					// Keyword + List of Posts: use the keyword to choose from the list
 					// or if there is no match, do a search.
					} else if ( $keyword && $post_id_list ) {

						// =========
						// ORDINAL?
						// Is the keyword an ordinal ('first'), and the user is choosing from the list
						// with the ordinal?
						if ($post_number = $this->is_ordinal( $keyword ) ) {
							$post_id = $post_id_list[$post_number];

error_log("ORDINAL: $keywords ---> $post_id");
						
						} else {
													
							// =========
							// LIST?

							// Look for the keyword in the prepared list of posts (related posts)
							if ($keyword && !empty($post_id_list[$keyword]) ) {
								// Found keyword in session post list?
								$post_id = $post_id_list[$keyword];
								
							} else {
								// Search all tags for this keyword
								$args = $this->args_for_post_by_tag ( $keyword, $response, $request, 1 );
								$posts = get_posts( array_merge( $args, array(
									'no_found_rows' => true,
									'post_status' => 'publish',
								) ) );
								
								// Get the first post only
								$post_id = $posts[0]->ID;

								// For now: error!
								$this->message( $response, 'unknown_tag_error', $request, $keyword );
							}
							
							
						}


error_log("------- ReadPostByKeyword : B -------");
error_log("Keyword and Post List exist.");
error_log("post_id to speak: $post_id");
error_log("keyword: $keyword");
error_log("Post found to match keyword: $post_id" );
error_log("related post ID's:" . print_r($post_id_list, true ) );
						
						
						// Get the post text and the list of related posts to read 
						if (!empty($post_id)) {
							
							$result = $this->endpoint_single_post( $post_id );
							
							$content = $result['content'];

							$related = $this->build_related_posts_text ( $post_id, $request, $response );

							$footer = '<break time="0.5s"/>' . $related;
						
							$speech = $content . $footer;


							$speech = "<speak>{$speech}</speak>";
							$response
									->respond_ssml( $speech )
									->with_card( $result['title'], '', $result['image'] );
									// This would end the session!
									//->end_session();

						} else {
						
error_log("Oops, no post id!" );

							$this->message( $response, '', $response );
						}


					// =========
					// Keyword exists, but no list of posts to choose from,
					// so use the keyword to find the first tagged post.
					} else {
						
						// search all tags for this keyword, get first post found
						$args = $this->args_for_post_by_tag ( $keyword, $response, $request, 1 );
						$posts = get_posts( array_merge( $args, array(
							'no_found_rows' => true,
							'post_status' => 'publish',
						) ) );


error_log("------- ReadPostByKeyword : C -------");
error_log("No list of related posts in session.");
error_log("keyword: $keyword");
error_log("posts found to match '$keyword' : " .  print_r(wp_list_pluck($posts, 'ID'), true) );

						if ($posts) {
							// Just get the first post (for now)
							// TO DO: turn this into a list to choose from?
							$ids = wp_list_pluck( $posts, 'ID' );
							$response->add_session_attribute("post_id_list", $ids) ;

	error_log("related post ID's from session:" . print_r($ids, true ) );

							$post = $posts[0];
							$post_id = $post->ID;
							
							$result = $this->endpoint_single_post( $post_id );
							
							$content = $result['content'];
							
					
							( $related = $this->build_related_posts_text ( $post_id, $request, $response ) )
							? $footer = '<break time="0.5s"/>' . $related
							: $footer = '';
						
							$speech = $content . $footer;
							$speech = "<speak>{$speech}</speak>";
							$response
									->respond_ssml( $speech )
									->with_card( $result['title'], '', $result['image'] );
									// This would end the session!
									//->end_session();
									
							if ($post_id_list)
								$response->add_session_attribute('post_id_list', $post_id_list);
								
							if ($related)
								$response->with_directives ( 'Dialog.ElicitSlot', 'Keyword');

						} else {

							// For now: error!
							$this->message( $response, 'unknown_tag_error', $request, $keyword );
						}
						

					}
					break;
					
				// ========================================
				// "Read the first/second/third/fourth/fifth post"
				// Used for choosing from a list of posts.
				case 'ReadPostByNumber':
				
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
						// This will extract the paths & shortcodes from the urls
						preg_match_all("/'https:\/\/thenewreporter.com\/explore\/(.*?)\/'/", $related_post_html, $matches, PREG_PATTERN_ORDER);

						$url = "https://thenewreporter.com/explore/?attachment_id=";
						$related = "";
						$pause = '<break time="0.2s"/>';
						$ids = array();
						
						// Build a list from the excerpts, which contain the keyword for the post
						foreach ($matches[1] as $shortcode) {
							$page = get_page_by_path( $shortcode, OBJECT, "post" );

							//$content = $content . ", $shortcode : " . $post_id . " keyword=". $page->post_excerpt;
							$related
							? $related = $related . " or " . $page->post_excerpt . $pause
							: $related = $page->post_excerpt . $pause;
							
							// Add the ID's as session attributes
							$ids[$page->post_excerpt] = $page->ID;
						}
						
						$response->add_session_attribute("post_id_list", $ids) ;
						
						$related = "Ask me to say more about {$related}?";

						$footer = '<break time="0.5s"/>' . $related;
						
						$speech = $content . $footer;


						$speech = "<speak>{$speech}</speak>";
						$response
								->respond_ssml( $speech )
								->with_card( $result['title'], '', $result['image'] );
								// This would end the session!
								//->end_session();
						}
					} else {
						$this->message( $response, '', $request );
					}
					break;
					

				// ==============================
				// example: "Read post id {PostID}"
				case 'ReadPostByID':
					$post_id = strtolower( sanitize_text_field( $request->getSlot( 'PostID' ) ) );
					$keyword = strtolower( sanitize_text_field( $request->getSlot( 'Keyword' ) ) );

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
					$this->message( $response, 'stop_intent' );
					break;
				case 'AMAZON.HelpIntent':
					$this->message( $response, 'help_intent' );
					break;
				default:
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
	 */
	private function is_ordinal ( $t ) {

		$digith = array('', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth', 'thirteenth', 'fourteenth', 'fiftheenth', 'sixteenth', 'seventeenth', 'eighteenth', 'nineteenth');	
		return array_search( trim(strtolower($t)), $digith);
	}

	/**
	 * List of keywords to choose from.
	 * Speak to user after an article; ask user to choose one.
	 * @param string $tag name of the tag to search for
	 * Search for posts with matching tags
	 */
	private function build_related_posts_text ( $post_id, $request, $response ) {
		// Depends on the "Related Posts for WordPress" plugin!
		$post = get_post($post_id);
		$related_post_html = rp4wp_children( $post_id, false );
		// This will extract the paths & shortcodes from the urls
		preg_match_all("/'https:\/\/thenewreporter.com\/explore\/(.*?)\/'/", $related_post_html, $matches, PREG_PATTERN_ORDER);

		$url = "https://thenewreporter.com/explore/?attachment_id=";
		$related = "";
		$pause = '<break time="0.2s"/>';
		$ids = array();
		
		if ($matches[1]) {
		
			// Build a list from the excerpts, which contain the keyword for the post
			foreach ($matches[1] as $shortcode) {
				$page = get_page_by_path( $shortcode, OBJECT, "post" );

				//$content = $content . ", $shortcode : " . $post_id . " keyword=". $page->post_excerpt;
				$related
				? $related = $related . " or " . $page->post_excerpt . $pause
				: $related = $page->post_excerpt . $pause;
			
				// Add the ID's as session attributes
				$ids[strtolower(sanitize_text_field($page->post_excerpt))] = $page->ID;
			}
			
			// Clear session attributes, then add our $ids
			$response->session_attributes = [];
			$response->add_session_attribute("post_id_list", $ids) ;
		
			$related = "Ask me to say more about {$related}?";
			
		} else {
			// no related posts
			$related = null;
		}
		
		return $related;
	}


	/**
	 * Create arguments for get_posts to
	 * @param string $tag name of the tag to search for
	 * Search for posts with matching tags
	 */
	private function args_for_post_by_tag ( $tag, $response, $request, $posts_per_page = 3 ) {

		$args = [];
		
		if ( $tag ) {
			// taxonomies to search, e.g. tags, categories, etc.
			$news_taxonomies = voicewp_news_taxonomies();

			if ( $news_taxonomies ) {
				$terms = get_terms( array(
					'name' => $tag,
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
			
			// Error
			if ( ! isset( $tax_query ) ) {
				$this->message( $response );
			}

			// Search for posts, then return a list of posts for the user to choose from.					
			$args = array(
				'post_type' => voicewp_news_post_types(),
				'posts_per_page' => $posts_per_page,
			);

			if ( isset( $tax_query ) ) {
				$args['tax_query'] = array( $tax_query );
			}

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
		preg_match_all("/'https:\/\/thenewreporter.com\/explore\/(.*?)\/'/", $related_post_html, $matches, PREG_PATTERN_ORDER);

		$url = "https://thenewreporter.com/explore/?attachment_id=";
		$related = array();
		$pause = '<break time="0.2s"/>';
	
		foreach ($matches[1] as $shortcode) {
			$page = get_page_by_path( $shortcode, OBJECT, "post" );
			$page->post_excerpt && $related[] = $page->post_excerpt;
			// Add the ID's as session attributes
			$ids[strtolower(sanitize_text_field($page->post_excerpt))] = $page->ID;
		}
		$related = implode( "$pause or ", $related);
		$related ? $footer = '<break time="0.5s"/>' . " Ask me to say more about {$related}?" : $footer = "";

		$speech = $content . $footer;
		
		$this->speech = $speech;

		$speech = "<speak>{$speech}</speak>";
		
		$response
			->respond_ssml( $speech )
			->with_card( $result['title'], $content, $result['image'] )
			->add_session_attribute('post_id_list', $ids) ;
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
	 * @param array $ids Array of IDs that were listed to the user
	 * @param in $number User selection from list
	 * @return int The post the user asked for
	 */
	private function get_post_id( $ids, $number ) {
		$number = absint( $number ) - 1;
		if ( ! array_key_exists( $number, $ids ) ) {
			return;
		}
		return absint( $ids[ $number ] );
	}

	/**
	 * Deliver a message to user
	 * @param AlexaResponse $response
	 * @param string $case The type of message to return
	 */
	private function message( $response, $case = 'missing', $request = false, $val='' ) {
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
				->respond( __( 'I cannot find any posts tagged with ' . $val . '.', 'voicewp' ) );

		} else {
			$response->respond( __( "Sorry! I couldn't find any news about that topic. Try asking something else!", 'voicewp' ) );
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
				'no_found_rows' => true,
				'post_status' => 'publish',
			) ) );

			$content = $card_content = '';
			$ids = array();
			if ( ! empty( $news_posts ) && ! is_wp_error( $news_posts ) ) {

				foreach ( $news_posts as $key => $news_post ) {
					// Appending 'th' to any number results in proper ordinal pronunciation
					// TODO: Sounds a little strange when there's only one result.
					$content .= ( $key + 1 ) . 'th, ' . $news_post->post_title . '. ';
					$card_content .= ( $key + 1 ) . '. ' . $news_post->post_title . "\n";
					$ids[] = $news_post->ID;
				}
			}

			$result = array(
				'content' => $content,
				'ids' => $ids,
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

<?php
/*
 * Plugin Name: Term Filters
 * Plugin URI: http://wordpress.lowtone.nl/plugins/terms-filter/
 * Description: Filter posts on terms.
 * Version: 1.0
 * Author: Lowtone <info@lowtone.nl>
 * Author URI: http://lowtone.nl
 * License: http://wordpress.lowtone.nl/license
 */
/**
 * @author Paul van der Meijs <code@lowtone.nl>
 * @copyright Copyright (c) 2013, Paul van der Meijs
 * @license http://wordpress.lowtone.nl/license/
 * @version 1.0
 * @package wordpress\plugins\lowtone\terms\filter
 */

namespace lowtone\terms\filter {

	use lowtone\content\packages\Package,
		lowtone\ui\forms\Form,
		lowtone\ui\forms\FieldSet,
		lowtone\ui\forms\Html,
		lowtone\ui\forms\Input,
		lowtone\net\URL,
		lowtone\wp\sidebars\Sidebar,
		lowtone\wp\widgets\simple\Widget;

	// Includes
	
	if (!include_once WP_PLUGIN_DIR . "/lowtone-content/lowtone-content.php") 
		return trigger_error("Lowtone Content plugin is required", E_USER_ERROR) && false;

	// Init

	Package::init(array(
			Package::INIT_PACKAGES => array("lowtone", "lowtone\\wp", "lowtone\\style"),
			Package::INIT_MERGED_PATH => __NAMESPACE__,
			Package::INIT_SUCCESS => function() {

				wp_register_style("lowtone_terms_filter", plugins_url("/assets/styles/filter.css", __FILE__));

				$selectedTerms = NULL;

				add_action("parse_request", function() use (&$selectedTerms) {
					if (is_admin() 
						|| (false === is_active_widget(false, false, "lowtone_terms_filter", true)
							&& false === is_active_widget(false, false, "lowtone_terms_filters", true)))
								return;

					wp_enqueue_style("lowtone_terms_filter");

					$selectedTerms = NULL;

					foreach (taxonomies() as $taxonomy) {
						$name = $taxonomy->query_var;

						$filterArg = "filter_" . $name;

						if (!(isset($_GET[$filterArg]) && taxonomy_exists($name))) 
							continue;

						$queryTypeArg = "query_type_" . $name;

						$selectedTerms[$name] = array(
								"taxonomy" => $name,
								"terms" => array_map("abs", explode(",", $_GET[$filterArg])),
								"query_type" => isset($_GET[$queryTypeArg]) && in_array(($queryType = strtolower($_GET[$queryTypeArg])), array("and", "or")) ? $queryType : apply_filters("lowtone_terms_filter_default_query_type", "and")
							);
					}

					if (NULL === $selectedTerms)
						return;

					add_filter("pre_get_posts", function($query) use ($selectedTerms) {
						if (!$query->is_main_query())
							return;

						if (count($selectedTerms) < 1)
							return;

						$args = apply_filters("lowtone_terms_filter_get_posts_args", array(
								"post_status" => "publish",
							));

						$args = array_merge($args, array(
								"post_type" => $query->get("post_type"),
								"numberposts" => -1,
								"fields" => "ids",
								"no_found_rows" => true,
							));

						$postsPerTaxonomy = array_map(function($options) use ($args) {
								$args["tax_query"] = array(
										"relation" => strtoupper($options["query_type"]),
										array(
											"taxonomy" => $options["taxonomy"],
											"terms" => $options["terms"],
											"field" => "id",
										)
									);

								return get_posts($args);
							}, $selectedTerms);

						$posts = count($postsPerTaxonomy) > 1 ? call_user_func_array("array_intersect", $postsPerTaxonomy) : reset($postsPerTaxonomy);

						if (!is_array($posts))
							return;

						$posts[] = 0;

						$query->set("post__in", $posts);
					});
				}, 1);

				// Fetch taxonomy list

				add_action("wp_ajax_lowtone_terms_filter_taxonomies", function() {
					$response = function($response) {
						header("Content-type: application/json");

						echo json_encode($response);

						exit;
					};

					if (!isset($_REQUEST["post_type"]))
						$response(array("meta" => array("code" => 400, "message" => array("Post type is required"))));


					$response(array(
							"meta" => array(
								"code" => 200,
								"message" => array("Success!")
							),
							"data" => array(
								"taxonomies" => array_map(function($taxonomy) {
									return $taxonomy->labels->singular_name ?: $taxonomy->label;
								}, taxonomies($_REQUEST["post_type"]))
							)
						));
				});

				add_action("load-widgets.php", function() {

					wp_enqueue_script("lowtone_terms_filter_admin", plugins_url("/assets/scripts/jquery.widget-admin.js", __FILE__), array("jquery"));
					wp_localize_script("lowtone_terms_filter_admin", "lowtone_terms_filter_admin", array(
							"ajaxurl" => admin_url("admin-ajax.php"),
						));

				});

				// Register widget

				add_action("widgets_init", function() use (&$selectedTerms) {

					$__postsInQuery = array();

					$postsInQuery = function($type = "unfiltered") use (&$__postsInQuery) {
						if (isset($__postsInQuery[$type]))
							return $__postsInQuery[$type];

						$query = $GLOBALS["wp_the_query"]->query_vars;

						switch ($type) {
							case "unfiltered":
								unset($query["post__in"]);
								break;
						}

						$query = array_merge($query, array(
								"nopaging" => true,
								"fields" => 'ids',
								"no_found_rows" => true,
								"update_post_meta_cache" => false,
								"update_post_term_cache" => false
							));

						return ($__postsInQuery[$type] = get_posts($query));
					};

					/**
					 * Basic fields for the filter widget form.
					 * @var Closure
					 * @return Form Returns a form instance with basic fields 
					 * for the filter widget.
					 */
					$widgetForm = function() {
						wp_enqueue_style("lowtone_style_grid");

						$form = new Form();

						$form
							->appendChild(
								$form->createFieldSet(array(
										FieldSet::PROPERTY_LEGEND => __("Term selection", "lowtone_terms_filter"),
									))
									->appendChild(
										$form->createInput(Input::TYPE_SELECT, array(
												Input::PROPERTY_NAME => "display_type",
												Input::PROPERTY_LABEL => __("Display Type", "lowtone_terms_filter"),
												Input::PROPERTY_VALUE => array("list", "dropdown"),
												Input::PROPERTY_ALT_VALUE => array(__("List", "lowtone_terms_filter"), __("Dropdown", "lowtone_terms_filter"))
											))
									)
									->appendChild(
										$form->createInput(Input::TYPE_SELECT, array(
												Input::PROPERTY_NAME => "query_type",
												Input::PROPERTY_LABEL => __("Query Type", "lowtone_terms_filter"),
												Input::PROPERTY_VALUE => array("and", "or"),
												Input::PROPERTY_ALT_VALUE => array(__("AND", "lowtone_terms_filter"), __("OR", "lowtone_terms_filter"))
											))
									)
							)
							->appendChild(
								$form->createFieldSet(array(
										FieldSet::PROPERTY_LEGEND => __("Sorting", "lowtone_terms_filter"),
									))
									->appendChild(
										$form->createInput(Input::TYPE_SELECT, array(
												Input::PROPERTY_NAME => "sort_by",
												Input::PROPERTY_LABEL => __("Sort by", "lowtone_terms_filter"),
												Input::PROPERTY_VALUE => array("name", "num_products"),
												Input::PROPERTY_ALT_VALUE => array(__("Name", "lowtone_terms_filter"), __("Number of products", "lowtone_terms_filter")),
											))
									)
									->appendChild(
										$form->createInput(Input::TYPE_CHECKBOX, array(
												Input::PROPERTY_NAME => "selected_at_top",
												Input::PROPERTY_LABEL => __("Move selected terms to top", "lowtone_terms_filter"),
												Input::PROPERTY_VALUE => "1",
											))
									)
							);

						return $form;
					};

					/**
					 * Create the widget body based on the given instance.
					 * @var Closure
					 * @param array $instance The widget instance.
					 * @return string|bool Returns the body for the widget as a
					 * string or FALSE if there are no terms available for the 
					 * widget.
					 */
					$widgetBody = function($instance, &$taxonomy = NULL) use (&$selectedTerms, $postsInQuery) {
							if (false === ($taxonomy = get_taxonomy($instance["taxonomy"])))
								return false;

							$selectedTermsForTaxonomy = isset($selectedTerms[$taxonomy->name]) ? $selectedTerms[$taxonomy->name]["terms"] : array();

							$terms = get_terms($taxonomy->name, array("hide_empty" => true));

							$currentTerm = NULL;

							$currentTaxonomy = NULL;

							/*if ($taxonomiesArray && is_tax($taxonomiesArray)) {
								$queriedObject = get_queried_object();

								$currentTerm = $queriedObject->term_id;
								$currentTaxonomy = $queriedObject->taxonomy;
							}*/

							// Extend widget info

							$terms = array_map(function($term) use ($instance, $postsInQuery, $taxonomy, $selectedTermsForTaxonomy, $currentTerm) {

									// Skip the current term
									
									if ($currentTerm == $term->term_id)
										return false;

									// Get post IDs for term

									$transientName = "wc_ln_count_" . md5(sanitize_key($taxonomy->query_var) . sanitize_key($term->term_id));

									if (false === ($postsInTerm = get_transient($transientName))) 
										set_transient($transientName, ($postsInTerm = get_objects_in_term($term->term_id, $taxonomy->query_var)));

									// Check if the term is selected

									$selected = in_array($term->term_id, $selectedTermsForTaxonomy);

									$term->selected = $selected;

									// Term count
									
									$postCount = count($postsInTerm);

									switch ($instance["query_type"]) {
										case "and":
											$postCount = sizeof(array_intersect($postsInTerm, $postsInQuery("filtered")));

											break;

										default:
											$postCount = sizeof(array_intersect($postsInTerm, $postsInQuery()));

									}

									if ($postCount < 1 && !$selected)
										return false;

									$term->post_count = $postCount;

									return $term;
								}, $terms);

							$terms = array_filter($terms);

							if (count($terms) < 1) 
								return false;
							
							/**
							 * Move selected terms to the top of the list.
							 * @var Closure
							 */
							$selectedToTop = function() use (&$terms, $selectedTermsForTaxonomy) {
								$top = array();
								$bottom = array();

								foreach ($terms as $term) {
									if ($term->selected)
										$top[] = $term;
									else 
										$bottom[] = $term;
								}

								$terms = array_merge($top, $bottom);
							};

							$widgetBody = "";

							switch ($instance["display_type"]) {
								case "dropdown":
									break;

								default:
									$widgetBody .= '<ul>';

									if (isset($instance["selected_at_top"]) && $instance["selected_at_top"]) 
										$selectedToTop();

									foreach ($terms as $term) {

										// Create link

										if (defined("SHOP_IS_ON_FRONT")) 
											$link = home_url();
										/*elseif (is_post_type_archive("product") || is_page(woocommerce_get_page_id("shop"))) 
											$link = get_post_type_archive_link("product");*/
										else 
											$link = get_term_link(get_query_var("term"), get_query_var("taxonomy"));

										$link = URL::fromString($link);

										// Build query

										$query = filterArgs();

										if (get_search_query())
											$query["s"] = get_search_query();

										$filterArg = "filter_" . $taxonomy->query_var;
										$queryTypeArg = "query_type_" . $taxonomy->query_var;

										$currentFilter = isset($_GET[$filterArg]) && ($currentFilter = explode(",", $_GET[$filterArg])) ? $currentFilter : array();

										$currentFilter = array_map("esc_attr", $currentFilter);

										$class = "";

										if ($term->selected) {
											$currentFilter = array_diff($currentFilter, array($term->term_id));

											$class = 'class="chosen"';
										} else
											$currentFilter[] = $term->term_id;

										if ($currentFilter) {
											asort($currentFilter);

											$query[$filterArg] = implode(",", $currentFilter);

											if ("or" == $instance["query_type"])
												$query[$queryTypeArg] = "or";

										} else {
											unset($query[$filterArg]);
											unset($query[$queryTypeArg]);
										}

										$link->appendQuery($query);

										$widgetBody .= '<li ' . $class . '>' .
											(($term->post_count > 0 || $term->selected) 
												? '<a href="' . esc_url(apply_filters("lowtone_terms_filter_nav_link", $link)) . '">' . $term->name . '</a>'
												: '<span>' . $term->name . '</span>') .
											' <small class="count">' . $term->post_count . '</small></li>';

									}

									$widgetBody .= '</ul>';
							}

							return $widgetBody;
						};

					/**
					 * Create output for a single filter widget and add it .
					 * @var Closure
					 * @param array $args The settings for the widget.
					 * @param array $instance The properties for the widget 
					 * instance.
					 * @param Widget $widget The widget object.
					 */
					$widgetOut = function($args, $instance, $widget) use ($widgetBody) {
							if (false == ($body = $widgetBody($instance, $taxonomy)))
								return;

							// Widget output

							$title = isset($instance["title"]) && ($title = trim($instance["title"])) ? $title : $taxonomy->labels->singular_name;

							$title = apply_filters("widget_title", $title, $instance, $widget->id_base);

							echo $args[Sidebar::PROPERTY_BEFORE_WIDGET] . 
								$args[Sidebar::PROPERTY_BEFORE_TITLE] . $title . $args[Sidebar::PROPERTY_AFTER_TITLE] . 
								'<div class="widget_body">' . 
								$body . 
								'</div>' . 
								$args[Sidebar::PROPERTY_AFTER_WIDGET];
						};

					// Single taxonomy widget

					Widget::register(array(
							Widget::PROPERTY_ID => "lowtone_terms_filter",
							Widget::PROPERTY_NAME => __("Term Filter", "lowtone_terms_filter"),
							Widget::PROPERTY_DESCRIPTION => __("Filter posts using a term filter.", "lowtone_terms_filter"),
							Widget::PROPERTY_FORM => function($instance) use ($widgetForm) {
								$form = new Form();

								$postTypes = array_map(function($postType) {
										return $postType->labels->singular_name ?: $postType->label;
									}, postTypes());

								$instance = array_merge(array(
										"post_type" => reset(array_keys($postTypes)),
									), (array) $instance);

								$taxonomies = array_map(function($taxonomy) {
										return $taxonomy->labels->singular_name ?: $taxonomy->label;
									}, taxonomies($instance["post_type"]));

								$form
									->appendChild(
										$form->createInput(Input::TYPE_TEXT, array(
												Input::PROPERTY_NAME => "title",
												Input::PROPERTY_LABEL => __("Title", "lowtone_terms_filter")
											))
									)
									->appendChild(
										$form
											->createInput(Input::TYPE_SELECT, array(
												Input::PROPERTY_NAME => "post_type",
												Input::PROPERTY_LABEL => __("Post type", "lowtone_terms_filter"),
												Input::PROPERTY_VALUE => array_keys($postTypes),
												Input::PROPERTY_ALT_VALUE => array_values($postTypes),
											))
											->addClass("lowtone_terms_filter post_type")
									)
									->appendChild(
										$form
											->createInput(Input::TYPE_SELECT, array(
												Input::PROPERTY_NAME => "taxonomy",
												Input::PROPERTY_LABEL => __("Taxonomy", "lowtone_terms_filter"),
												Input::PROPERTY_VALUE => array_keys($taxonomies),
												Input::PROPERTY_ALT_VALUE => array_values($taxonomies),
											))
											->addClass("lowtone_terms_filter taxonomy")
									);

								foreach ($widgetForm()->getChildren() as $child)
									$form->appendChild($child);

								return $form;
							},
							Widget::PROPERTY_WIDGET => $widgetOut,
						));

					// Multiple taxonomies
					
					Widget::register(array(
							Widget::PROPERTY_ID => "lowtone_terms_filters",
							Widget::PROPERTY_NAME => __("Term Filters", "lowtone_terms_filter"),
							Widget::PROPERTY_DESCRIPTION => __("Automatically create filters for multiple taxonomies.", "lowtone_terms_filter"),
							Widget::PROPERTY_FORM => function($instance) use ($widgetForm) {
								$form = new Form();

								$postTypes = array_map(function($postType) {
										return $postType->labels->singular_name ?: $postType->label;
									}, postTypes());

								$postTypes = array("" => __("Automatically based on query", "lowtone_terms_filter")) + $postTypes;

								$instance = (array) $instance;

								$taxonomyValues = $taxonomyAltValues = array();

								foreach (taxonomies() as $taxonomy) 
									foreach ($taxonomy->object_type as $postType) {
										$label = get_post_type_object($postType)->label;

										$taxonomyValues[$label][$taxonomy->name] = $taxonomy->name;
										$taxonomyAltValues[$label][$taxonomy->name] = $taxonomy->label;
									}

								$form
									->appendChild(
										$form->createInput(Input::TYPE_TEXT, array(
												Input::PROPERTY_NAME => "title",
												Input::PROPERTY_LABEL => __("Title", "lowtone_terms_filter")
											))
									)
									->appendChild(
										$form
											->createInput(Input::TYPE_SELECT, array(
												Input::PROPERTY_NAME => "post_type",
												Input::PROPERTY_LABEL => __("Post type", "lowtone_terms_filter"),
												Input::PROPERTY_VALUE => array_keys($postTypes),
												Input::PROPERTY_ALT_VALUE => array_values($postTypes),
											))
									)
									->appendChild(
										$form->createFieldSet(array(
												FieldSet::PROPERTY_LEGEND => __("Taxonomy filter", "lowtone_terms_filter"),
											))
											->appendChild(
												$form->createFieldSet(array(
														FieldSet::PROPERTY_ELEMENT_NAME => "div",
														FieldSet::PROPERTY_CLASS => "one-half column"
													))
													->appendChild(
														$form->createInput(Input::TYPE_RADIO, array(
																Input::PROPERTY_NAME => "taxonomy_filter",
																Input::PROPERTY_LABEL => __("Hide", "lowtone_terms_filter"),
																Input::PROPERTY_VALUE => "hide",
															))
													)
											)
											->appendChild(
												$form->createFieldSet(array(
														FieldSet::PROPERTY_ELEMENT_NAME => "div",
														FieldSet::PROPERTY_CLASS => "one-half column"
													))
													->appendChild(
														$form->createInput(Input::TYPE_RADIO, array(
																Input::PROPERTY_NAME => "taxonomy_filter",
																Input::PROPERTY_LABEL => __("Show", "lowtone_terms_filter"),
																Input::PROPERTY_VALUE => "show",
															))
													)
											)
											->appendChild($form->createHtml(array(Html::PROPERTY_CLASS => "clear")))
											->appendChild(
												$form
													->createInput(Input::TYPE_SELECT, array(
														Input::PROPERTY_NAME => "taxonomy_filter_taxonomies",
														Input::PROPERTY_LABEL => __("Taxonomies", "lowtone_terms_filter"),
														Input::PROPERTY_VALUE => $taxonomyValues,
														Input::PROPERTY_ALT_VALUE => $taxonomyAltValues,
														Input::PROPERTY_MULTIPLE => true,
														Input::PROPERTY_ATTRIBUTES => array(
															"style" => "height: 160px"
														)
													))
											)
									);

								foreach ($widgetForm()->getChildren() as $child)
									$form->appendChild($child);

								$form
									->appendChild(
										$form->createFieldSet(array(
												FieldSet::PROPERTY_LEGEND => __("Widget layout", "lowtone_terms_filter"),
											))
											->appendChild(
												$form->createInput(Input::TYPE_RADIO, array(
														Input::PROPERTY_NAME => "widget_layout",
														Input::PROPERTY_LABEL => __("Single widget", "lowtone_terms_filter"),
														Input::PROPERTY_VALUE => "single_widget",
													))
											)
											->appendChild(
												$form->createInput(Input::TYPE_RADIO, array(
														Input::PROPERTY_NAME => "widget_layout",
														Input::PROPERTY_LABEL => __("Seperate widget for each taxonomy", "lowtone_terms_filter"),
														Input::PROPERTY_VALUE => "multiple_widgets",
													))
											)
									);

								return $form;
							},
							Widget::PROPERTY_WIDGET => function($args, $instance, $widget) use ($widgetOut, $widgetBody) {
								$postType = $instance["post_type"];

								if (!$postType) {
									global $wp_query;

									$postType = "post";

									if (is_tax()
										&& ($taxonomy = get_taxonomy(get_queried_object()->taxonomy))
										&& ($_pt = reset($taxonomy->object_type))) 
											$postType = $_pt;

								}

								$taxonomies = array_filter(taxonomies($postType), function($taxonomy) use ($instance) {
										$inArray = in_array($taxonomy->name, $instance["taxonomy_filter_taxonomies"]);

										return "hide" == $instance["taxonomy_filter"] ? !$inArray : $inArray;
									});

								switch ($instance["widget_layout"]) {
									case "multiple_widgets":
										$widgets = array();

										foreach ($taxonomies as $taxonomy) 
											$widgets[] = $widgetOut($args, array_merge($instance, array(
													"post_type" => $postType,
													"taxonomy" => $taxonomy->name
												)), $widget);

										if (!($widgets = array_filter($widgets)))
											return;
 
										echo implode($widgets);

										break;

									default:
										$body = array();

										foreach ($taxonomies as $taxonomy) {
											$_b = $widgetBody(array_merge($instance, array(
													"post_type" => $postType,
													"taxonomy" => $taxonomy->name
												)));

											if (!$_b)
												continue;

											$body[] = '<dt>' . $taxonomy->label . '</dt>' . 
												'<dd>' . $_b . '</dd>';

										}

										if (!$body)
											return;

										$title = isset($instance["title"]) && $instance["title"]
											? $instance["title"]
											: sprintf(__("Filter %s", "lowtone_terms_filter"), get_post_type_object($postType)->label);

										$title = apply_filters("widget_title", $title, $instance, $widget->id_base);

										echo $args[Sidebar::PROPERTY_BEFORE_WIDGET] . 
											$args[Sidebar::PROPERTY_BEFORE_TITLE] . $title . $args[Sidebar::PROPERTY_AFTER_TITLE] . 
											'<div class="widget_body">' . 
											'<dl>' . implode($body) . '</dl>' .
											'</div>' . 
											$args[Sidebar::PROPERTY_AFTER_WIDGET];

								}
							},
							Widget::PROPERTY_DEFAULTS => function() {
								return array(
										"post_type" => NULL,
										"taxonomy_filter" => "hide",
										"taxonomy_filter_taxonomies" => array(),
										"widget_layout" => "single_widget"
									);
							}
						));

				});

				add_filter("woocommerce_layered_nav_link", function($link) {
					foreach (taxonomies() as $taxonomy) {
						$arg = "filter_" . $taxonomy->query_var;

						if (!isset($_GET[$arg]))
							continue;

						$link = add_query_arg($arg, $_GET[$arg], $link);
					}

					return $link;
				});
				
			}
		));
				
	// Register textdomain

	add_action("plugins_loaded", function() {
		load_plugin_textdomain("lowtone_terms_filter", false, basename(__DIR__) . "/assets/languages");
	});

	// Functions
	
	/**
	 * Get a list of post types that have one or more taxonomies.
	 * @return array Returns a list of post objects.
	 */
	function postTypes() {
		return apply_filters("lowtone_terms_filter_post_types", array_filter(
			get_post_types(array(
				"public" => true,
			), "objects"),
			function($postType) {
				return count(taxonomies($postType->name)) > 0;
			}
		));
	}
	
	/**
	 * Get a list of taxonomies.
	 * @param string|NULL $postType If a post type is supplied only taxonomies 
	 * for that post type are returned.
	 * @return array Returns a list of taxonomies.
	 */
	function taxonomies($postType = NULL) {
		$options = array();

		if (NULL !== $postType)
			$options["object_type"] = array($postType);

		return apply_filters("lowtone_terms_filter_taxonomies", array_filter(
				get_taxonomies($options, "objects"),
				function($taxonomy) {
					return (bool) $taxonomy->query_var;
				}
			), $postType);
	}

	/**
	 * Get the filter arguments from the request.
	 * @return array Returns a list of filter arguments and their values.
	 */
	function filterArgs() {
		$args = array();

		$checkName = function($name) {
			if ("post_type" == $name)
				return true;

			if ("filter_" == substr($name, 0, 7))
				return true;

			if ("query_type_" == substr($name, 0, 11))
				return true;

			return false;
		};

		foreach ($_GET as $name => $val) {
			if (!$checkName($name))
				continue;	

			$args[$name] = $val;
		}

		return $args;
	}

}
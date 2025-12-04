<?php
/**
 * Plugin Name: MasterStudy Search API
 * Plugin URI: https://github.com/aldeentorres/masterstudy-search-api
 * Description: Enhanced search API for MasterStudy LMS with powerful search capabilities. Makes courses and lessons searchable via REST API. Includes case-insensitive partial matching for courses and lessons, category filtering, and category-only filtering. Perfect for external applications, mobile apps, and third-party integrations.
 * Version: 1.1.1
 * Author: artor
 * Author URI: https://github.com/aldeentorres
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: masterstudy-search-api
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Network: false
 *
 * API Endpoints:
 * - GET /wp-json/masterstudy-lms/v2/courses
 *   Parameters: s (search), category (IDs/names/slugs, comma-separated), per_page, page, sort, author
 *   Returns: courses array, lessons array (if search term provided), total, pages
 *
 * - GET /wp-json/masterstudy-lms/v2/search
 *   Parameters: s (search, optional if category provided), category (IDs/names/slugs, comma-separated), per_page, page, sort
 *   Returns: courses array, lessons array, total, pages
 *
 * Category parameter supports:
 * - IDs: category=168
 * - Names: category=Vietnam
 * - Slugs: category=vietnam
 * - Mixed: category=168,Vietnam,thailand
 *
 * Examples:
 * - /wp-json/masterstudy-lms/v2/courses?category=Vietnam
 * - /wp-json/masterstudy-lms/v2/courses?category=168&s=marketing
 * - /wp-json/masterstudy-lms/v2/search?category=Vietnam
 * - /wp-json/masterstudy-lms/v2/search?category=Vietnam&s=marketing&sort=rating&per_page=20
 *
 * See README.md for complete API documentation.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load GitHub updater
require_once plugin_dir_path( __FILE__ ) . 'includes/class-github-updater.php';

class MasterStudy_Search_API {

	/**
	 * Plugin version
	 */
	const VERSION = '1.1.1';

	/**
	 * Instance of this class
	 */
	private static $instance = null;

	/**
	 * Get instance of this class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Hook into REST API to intercept courses requests before middleware processes them
		add_filter( 'rest_pre_dispatch', array( $this, 'intercept_courses_request' ), 5, 3 );
		
		// Enable REST API for stm-lessons post type
		add_filter( 'register_post_type_args', array( $this, 'enable_lessons_rest_api' ), 10, 2 );
		add_action( 'registered_post_type', array( $this, 'enable_lessons_rest_api_after' ), 10, 2 );
		
		// Register combined courses and lessons search endpoint
		add_action( 'rest_api_init', array( $this, 'register_combined_search_endpoint' ) );
		add_action( 'rest_api_init', array( $this, 'register_agent_progress_endpoint' ) );
	}

	/**
	 * Enable REST API for stm-lessons post type during registration
	 *
	 * @param array  $args      Post type registration arguments.
	 * @param string $post_type Post type name.
	 * @return array
	 */
	public function enable_lessons_rest_api( $args, $post_type ) {
		if ( 'stm-lessons' === $post_type ) {
			$args['show_in_rest'] = true;
			$args['rest_base'] = 'stm-lessons';
			$args['rest_controller_class'] = 'WP_REST_Posts_Controller';
		}
		return $args;
	}

	/**
	 * Enable REST API for stm-lessons post type after registration (fallback)
	 *
	 * @param string       $post_type        Post type name.
	 * @param WP_Post_Type $post_type_object Post type object.
	 */
	public function enable_lessons_rest_api_after( $post_type, $post_type_object ) {
		if ( 'stm-lessons' === $post_type ) {
			$post_type_object->show_in_rest = true;
			$post_type_object->rest_base = 'stm-lessons';
			$post_type_object->rest_controller_class = 'WP_REST_Posts_Controller';
		}
	}

	/**
	 * Intercept courses request and handle it directly, bypassing middleware
	 * This runs before the router processes the request
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public function intercept_courses_request( $result, $server, $request ) {
		// Only process if result is empty (not already handled)
		if ( ! empty( $result ) ) {
			return $result;
		}

		// Get the route
		$route = $request->get_route();

		// Check if this is the courses endpoint
		if ( strpos( $route, '/masterstudy-lms/v2/courses' ) === false ) {
			return $result;
		}

		// Check if request already has nonce - if it does, let it proceed normally
		if ( ! empty( $request->get_header( 'X-WP-Nonce' ) ) ) {
			return $result;
		}

		// For courses endpoint without nonce, handle it directly
		// This bypasses the Guest middleware entirely
		return $this->handle_courses_directly( $request );
	}

	/**
	 * Handle courses endpoint directly by calling the controller
	 * Also includes lessons in the results if search term is provided
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	private function handle_courses_directly( $request ) {
		// Check if the controller class exists
		if ( ! class_exists( '\MasterStudy\Lms\Http\Controllers\Course\GetCoursesController' ) ) {
			return new WP_REST_Response(
				array(
					'error_code' => 'controller_not_found',
					'message'    => esc_html__( 'Courses controller not found. Please ensure MasterStudy LMS is active.', 'masterstudy-search-api' ),
				),
				500
			);
		}

		try {
			// Get category filter if provided
			$category = $request->get_param( 'category' );
			
			// If category is specified, validate that courses belong to that category
			if ( ! empty( $category ) ) {
				$category_ids = $this->parse_category_parameter( $category );
				
				if ( ! empty( $category_ids ) ) {
					// Filter courses to ensure they belong to the specified category
					$response_data = $this->get_courses_by_category( $request, $category_ids );
				} else {
					// Invalid category, return empty results
					return new WP_REST_Response(
						array(
							'courses' => array(),
							'total'   => 0,
							'pages'   => 0,
						),
						200
					);
				}
			} else {
				// No category filter, use controller normally
			$controller = new \MasterStudy\Lms\Http\Controllers\Course\GetCoursesController();
			$response = $controller( $request );
			$response_data = $response->get_data();
			}
			
			// Convert courses from objects to arrays if needed
			if ( ! empty( $response_data['courses'] ) && is_array( $response_data['courses'] ) ) {
				$converted_courses = array();
				foreach ( $response_data['courses'] as $course ) {
					// Convert object to array if needed
					if ( is_object( $course ) ) {
						$course = (array) $course;
					}
					$converted_courses[] = $course;
				}
				$response_data['courses'] = $converted_courses;
			}
			
			// If there's a search term, find matching lessons and group them by course
			$search_term = $request->get_param( 's' );
			if ( ! empty( $search_term ) && is_array( $response_data ) ) {
				// Get ALL matching lessons (no pagination - we'll paginate courses later)
				$lessons = $this->search_lessons( $search_term, 1000, $category, 0 );
				
				// Group lessons by course ID
				$lessons_by_course = $this->group_lessons_by_course( $lessons );
				
				// Get all unique course IDs that have matching lessons
				$lesson_course_ids = array_keys( $lessons_by_course );
				
				// Get courses that contain matching lessons
				$courses_from_lessons = array();
				if ( ! empty( $lesson_course_ids ) ) {
					$courses_from_lessons = $this->get_courses_by_ids( $lesson_course_ids, $category );
				}
				
				// Build a map of existing courses by ID
				$existing_courses_map = array();
				if ( ! empty( $response_data['courses'] ) ) {
					foreach ( $response_data['courses'] as $course ) {
						// Ensure course is an array
						if ( is_object( $course ) ) {
							$course = (array) $course;
						}
						$course_id = $course['id'] ?? ( isset( $course->id ) ? $course->id : 0 );
						$existing_courses_map[ $course_id ] = $course;
					}
				}
				
				// Merge courses from lessons with existing courses and add lessons to each
				foreach ( $courses_from_lessons as $course ) {
					// Ensure course is an array
					if ( is_object( $course ) ) {
						$course = (array) $course;
					}
					$course_id = $course['id'] ?? 0;
					if ( ! isset( $existing_courses_map[ $course_id ] ) ) {
						$existing_courses_map[ $course_id ] = $course;
					}
					// Add matching lessons to this course
					if ( isset( $lessons_by_course[ $course_id ] ) ) {
						// Ensure the course in the map is an array
						if ( is_object( $existing_courses_map[ $course_id ] ) ) {
							$existing_courses_map[ $course_id ] = (array) $existing_courses_map[ $course_id ];
						}
						$existing_courses_map[ $course_id ]['lessons'] = $lessons_by_course[ $course_id ];
					}
				}
				
				// Also add lessons to courses that matched directly
				foreach ( $existing_courses_map as $course_id => $course ) {
					// Ensure course is an array
					if ( is_object( $course ) ) {
						$course = (array) $course;
						$existing_courses_map[ $course_id ] = $course;
					}
					if ( isset( $lessons_by_course[ $course_id ] ) ) {
						$existing_courses_map[ $course_id ]['lessons'] = $lessons_by_course[ $course_id ];
					} elseif ( ! isset( $existing_courses_map[ $course_id ]['lessons'] ) ) {
						$existing_courses_map[ $course_id ]['lessons'] = array();
					}
				}
				
				// Get pagination and sort parameters
				$per_page = intval( $request->get_param( 'per_page' ) ) ?: 10;
				$page = intval( $request->get_param( 'page' ) ) ?: 1;
				$sort = $request->get_param( 'sort' );
				
				// Apply sorting if requested
				if ( ! empty( $sort ) ) {
					// Accept both MasterStudy defaults and user-friendly names
					// Note: 'relevance' is not directly supported but will be ignored gracefully
					$supported_sorts = array( 'date_high', 'date_low', 'newest', 'oldest', 'price_high', 'price_low', 'rating', 'popular' );
					if ( in_array( strtolower( $sort ), $supported_sorts, true ) ) {
						$sorted_courses = $this->sort_courses_array( array_values( $existing_courses_map ), $sort );
						$existing_courses_map = array();
						foreach ( $sorted_courses as $course ) {
							// Ensure course is an array
							if ( is_object( $course ) ) {
								$course = (array) $course;
							}
							$course_id = $course['id'] ?? 0;
							$existing_courses_map[ $course_id ] = $course;
						}
					}
				}
				
				// Convert to array and apply pagination
				$all_courses = array_values( $existing_courses_map );
				$total_courses = count( $all_courses );
				$offset = $per_page * ( $page - 1 );
				$paginated_courses = array_slice( $all_courses, $offset, $per_page );
				
				// Update response with paginated courses
				$response_data['courses'] = $paginated_courses;
				// Remove separate lessons array if it exists
				unset( $response_data['lessons'] );
				// Update total and pages
				$response_data['total'] = $total_courses;
				$response_data['pages'] = ceil( $total_courses / $per_page );
			} elseif ( is_array( $response_data ) ) {
				// Ensure all courses have an empty lessons array when no search term
				if ( ! empty( $response_data['courses'] ) ) {
					foreach ( $response_data['courses'] as &$course ) {
						// Convert object to array if needed
						if ( is_object( $course ) ) {
							$course = (array) $course;
						}
						$course['lessons'] = array();
					}
				}
			}
			
			// Ensure we return a proper REST response
			return rest_ensure_response( $response_data );
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				array(
					'error_code' => 'internal_error',
					'message'    => esc_html__( 'An error occurred while processing the request', 'masterstudy-search-api' ),
					'error'      => $e->getMessage(),
				),
				500
			);
		} catch ( Error $e ) {
			return new WP_REST_Response(
				array(
					'error_code' => 'internal_error',
					'message'    => esc_html__( 'An error occurred while processing the request', 'masterstudy-search-api' ),
					'error'      => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Parse category parameter - supports IDs, names, and slugs
	 * Accepts comma-separated values that can be a mix of IDs, names, or slugs
	 *
	 * @param string $category Category parameter (can be IDs, names, or slugs, comma-separated).
	 * @return array Array of category term IDs.
	 */
	private function parse_category_parameter( $category ) {
		if ( empty( $category ) ) {
			return array();
		}

		$category_values = array_map( 'trim', explode( ',', $category ) );
		$category_values = array_filter( $category_values );
		
		if ( empty( $category_values ) ) {
			return array();
		}

		$category_ids = array();
		$taxonomy = 'stm_lms_course_taxonomy';

		foreach ( $category_values as $value ) {
			// Try as numeric ID first
			if ( is_numeric( $value ) ) {
				$term = get_term( intval( $value ), $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$category_ids[] = intval( $term->term_id );
				}
			} else {
				// Try as slug first (more common in URLs, case-sensitive)
				$term = get_term_by( 'slug', $value, $taxonomy );
				if ( ! $term ) {
					// If not found as slug, try as name (case-insensitive)
					// Use direct SQL query for efficient case-insensitive matching
					global $wpdb;
					$term_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT t.term_id 
						FROM {$wpdb->terms} t
						INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
						WHERE tt.taxonomy = %s
						AND LOWER(t.name) = LOWER(%s)
						LIMIT 1",
						$taxonomy,
						$value
					) );
					
					if ( $term_id ) {
						$term = get_term( intval( $term_id ), $taxonomy );
					}
				}
				
				if ( $term && ! is_wp_error( $term ) ) {
					$category_ids[] = intval( $term->term_id );
				}
			}
		}

		return array_unique( array_filter( $category_ids ) );
	}

	/**
	 * Get courses filtered by category with strict validation
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param array           $category_ids Array of category term IDs.
	 * @return array
	 */
	private function get_courses_by_category( $request, $category_ids ) {
		global $wpdb;

		$per_page = intval( $request->get_param( 'per_page' ) ) ?: 10;
		$page = intval( $request->get_param( 'page' ) ) ?: 1;
		$offset = $per_page * ( $page - 1 );
		$search_term = $request->get_param( 's' );
		$sort = $request->get_param( 'sort' );

		// Build query with strict category filtering
		$category_placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
		
		$courses_from = "{$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
		
		$courses_where = array(
			"p.post_type = 'stm-courses'",
			"p.post_status = 'publish'",
			"tt.taxonomy = 'stm_lms_course_taxonomy'",
			"tt.term_id IN ($category_placeholders)",
		);

		$courses_params = $category_ids;

		// Add search filter if provided
		if ( ! empty( $search_term ) ) {
			$search_like = '%' . $wpdb->esc_like( $search_term ) . '%';
			$courses_where[] = "(
				LOWER(p.post_title) LIKE %s
				OR LOWER(p.post_content) LIKE %s
				OR LOWER(p.post_excerpt) LIKE %s
			)";
			$courses_params = array_merge( $courses_params, array( $search_like, $search_like, $search_like ) );
		}

		$courses_where_sql = implode( ' AND ', $courses_where );

		// Get courses
		$courses_query = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_date, p.post_author
			FROM $courses_from
			WHERE $courses_where_sql
			ORDER BY p.post_date DESC
			LIMIT %d OFFSET %d",
			...array_merge( $courses_params, array( $per_page, $offset ) )
		);

		$courses = $wpdb->get_results( $courses_query );

		// Get total count
		$courses_count_query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) 
			FROM $courses_from
			WHERE $courses_where_sql",
			...$courses_params
		);
		$courses_total = intval( $wpdb->get_var( $courses_count_query ) );

		// Format courses
		$formatted_courses = array();
		foreach ( $courses as $course ) {
			// Double-check that course actually belongs to the category
			$course_terms = wp_get_post_terms( $course->ID, 'stm_lms_course_taxonomy', array( 'fields' => 'ids' ) );
			$has_category = ! empty( array_intersect( $category_ids, $course_terms ) );
			
			if ( $has_category ) {
				$course_data = array(
					'id'       => intval( $course->ID ),
					'title'    => $course->post_title,
					'excerpt'  => wp_trim_words( $course->post_excerpt ?: $course->post_content, 20 ),
					'link'     => get_permalink( $course->ID ),
					'type'     => 'course',
					'date'     => $course->post_date,
					'author'   => intval( $course->post_author ),
					'lessons'  => array(), // Initialize with empty lessons array
				);

				// Add course-specific data if available
				if ( class_exists( '\MasterStudy\Lms\Repositories\CourseRepository' ) ) {
					try {
						$repo = new \MasterStudy\Lms\Repositories\CourseRepository();
						$full_course = $repo->find( $course->ID, 'grid' );
						if ( $full_course ) {
							$course_data['price'] = $full_course->price ?? 0;
							$course_data['rating'] = $full_course->rating ?? 0;
							$course_data['students'] = $full_course->current_students ?? 0;
						}
					} catch ( Exception $e ) {
						// Continue without additional data
					}
				}

				$formatted_courses[] = $course_data;
			}
		}

		// Apply sorting if requested
		if ( ! empty( $sort ) ) {
			// Accept both MasterStudy defaults and user-friendly names
			$supported_sorts = array( 'date_high', 'date_low', 'newest', 'oldest', 'price_high', 'price_low', 'rating', 'popular' );
			if ( in_array( strtolower( $sort ), $supported_sorts, true ) ) {
				$sorted_courses = $this->get_sorted_courses( $search_term, $sort, $per_page, $offset, $request->get_param( 'category' ) );
				if ( ! empty( $sorted_courses ) ) {
					$formatted_courses = $sorted_courses;
				}
			}
		}

		return array(
			'courses' => $formatted_courses,
			'total'   => $courses_total,
			'pages'   => ceil( $courses_total / $per_page ),
		);
	}

	/**
	 * Get lessons by category only (no search term)
	 *
	 * @param string $category Category IDs (comma-separated) to filter by.
	 * @param int    $limit Limit results.
	 * @param int    $offset Offset for pagination.
	 * @return array
	 */
	private function get_lessons_by_category( $category, $limit = 10, $offset = 0 ) {
		global $wpdb;

		if ( empty( $category ) ) {
			return array();
		}

		$category_ids = $this->parse_category_parameter( $category );
		
		if ( empty( $category_ids ) ) {
			return array();
		}

		$curriculum_table = $wpdb->prefix . 'stm_lms_curriculum_materials';
		$sections_table = $wpdb->prefix . 'stm_lms_curriculum_sections';
		
		// Check if tables exist
		$materials_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $curriculum_table ) );
		$sections_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sections_table ) );
		
		if ( ! $materials_table_exists || ! $sections_table_exists ) {
			return array();
		}

		$category_placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
		
		// Join with curriculum tables and course categories
		$from_clause = "{$wpdb->posts} l
			INNER JOIN {$curriculum_table} m ON l.ID = m.post_id
			INNER JOIN {$sections_table} s ON m.section_id = s.id
			INNER JOIN {$wpdb->posts} c ON s.course_id = c.ID
			INNER JOIN {$wpdb->term_relationships} tr ON c.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
		
		$where_clauses = array(
			"l.post_type = 'stm-lessons'",
			"l.post_status = 'publish'",
			"m.post_type = 'stm-lessons'",
			"c.post_type = 'stm-courses'",
			"c.post_status = 'publish'",
			"tt.taxonomy = 'stm_lms_course_taxonomy'",
			"tt.term_id IN ($category_placeholders)",
		);

		$query_params = $category_ids;

		// Build the final query
		$where_sql = implode( ' AND ', $where_clauses );
		
		$lessons_query = $wpdb->prepare(
			"SELECT DISTINCT l.ID, l.post_title, l.post_content, l.post_excerpt, l.post_date, l.post_author
			FROM $from_clause
			WHERE $where_sql
			ORDER BY l.post_date DESC
			LIMIT %d OFFSET %d",
			...array_merge( $query_params, array( $limit, $offset ) )
		);

		$lessons = $wpdb->get_results( $lessons_query );

		// Format lessons
		$formatted = array();
		foreach ( $lessons as $lesson ) {
			// Find which course(s) this lesson belongs to
			$course_ids = $this->get_lesson_courses( $lesson->ID );
			$primary_course_id = ! empty( $course_ids ) ? intval( $course_ids[0] ) : null;

			// Build lesson URL in format: courses/{course-slug}/{lesson-id}/
			$lesson_link = $this->get_lesson_url( $lesson->ID, $primary_course_id );

			$formatted[] = array(
				'id'        => intval( $lesson->ID ),
				'title'     => $lesson->post_title,
				'excerpt'   => wp_trim_words( $lesson->post_excerpt ?: $lesson->post_content, 20 ),
				'link'      => $lesson_link,
				'type'      => 'lesson',
				'date'      => $lesson->post_date,
				'author'    => intval( $lesson->post_author ),
				'course_id' => $primary_course_id,
				'courses'   => array_map( 'intval', $course_ids ),
			);
		}

		return $formatted;
	}

	/**
	 * Search lessons with case-insensitive partial matching
	 *
	 * @param string $search_term Search term.
	 * @param int    $limit Limit results.
	 * @param string $category Category IDs (comma-separated) to filter by.
	 * @param int    $offset Offset for pagination.
	 * @return array
	 */
	private function search_lessons( $search_term, $limit = 10, $category = null, $offset = 0 ) {
		global $wpdb;

		// Prepare search term for case-insensitive partial matching
		$search_like = '%' . $wpdb->esc_like( $search_term ) . '%';

		// Build base query
		$where_clauses = array(
			"l.post_type = 'stm-lessons'",
			"l.post_status = 'publish'",
			"(
				LOWER(l.post_title) LIKE %s
				OR LOWER(l.post_content) LIKE %s
				OR LOWER(l.post_excerpt) LIKE %s
			)",
		);

		$query_params = array( $search_like, $search_like, $search_like );
		$from_clause = "{$wpdb->posts} l";

		// If category filter is provided, filter lessons by their parent course categories
		if ( ! empty( $category ) ) {
			$category_ids = $this->parse_category_parameter( $category );
			
			if ( ! empty( $category_ids ) ) {
				$curriculum_table = $wpdb->prefix . 'stm_lms_curriculum_materials';
				$sections_table = $wpdb->prefix . 'stm_lms_curriculum_sections';
				
				// Check if tables exist
				$materials_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $curriculum_table ) );
				$sections_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sections_table ) );
				
				if ( $materials_table_exists && $sections_table_exists ) {
					$category_placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
					
					// Join with curriculum tables and course categories
					$from_clause .= "
						INNER JOIN {$curriculum_table} m ON l.ID = m.post_id
						INNER JOIN {$sections_table} s ON m.section_id = s.id
						INNER JOIN {$wpdb->posts} c ON s.course_id = c.ID
						INNER JOIN {$wpdb->term_relationships} tr ON c.ID = tr.object_id
						INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					";
					
					$where_clauses[] = "m.post_type = 'stm-lessons'";
					$where_clauses[] = "c.post_type = 'stm-courses'";
					$where_clauses[] = "c.post_status = 'publish'";
					$where_clauses[] = "tt.taxonomy = 'stm_lms_course_taxonomy'";
					$where_clauses[] = "tt.term_id IN ($category_placeholders)";
					
					$query_params = array_merge( $query_params, $category_ids );
				} else {
					// If curriculum tables don't exist, we can't filter lessons by category
					return array();
				}
			}
		}

		// Build the final query
		$where_sql = implode( ' AND ', $where_clauses );
		
		$lessons_query = $wpdb->prepare(
			"SELECT DISTINCT l.ID, l.post_title, l.post_content, l.post_excerpt, l.post_date, l.post_author
			FROM $from_clause
			WHERE $where_sql
			ORDER BY l.post_date DESC
			LIMIT %d OFFSET %d",
			...array_merge( $query_params, array( $limit, $offset ) )
		);

		$lessons = $wpdb->get_results( $lessons_query );

		// Format lessons
		$formatted = array();
		foreach ( $lessons as $lesson ) {
			// Find which course(s) this lesson belongs to
			$course_ids = $this->get_lesson_courses( $lesson->ID );
			$primary_course_id = ! empty( $course_ids ) ? intval( $course_ids[0] ) : null;

			// Build lesson URL in format: courses/{course-slug}/{lesson-id}/
			$lesson_link = $this->get_lesson_url( $lesson->ID, $primary_course_id );

			$formatted[] = array(
				'id'        => intval( $lesson->ID ),
				'title'     => $lesson->post_title,
				'excerpt'   => wp_trim_words( $lesson->post_excerpt ?: $lesson->post_content, 20 ),
				'link'      => $lesson_link,
				'type'      => 'lesson',
				'date'      => $lesson->post_date,
				'author'    => intval( $lesson->post_author ),
				'course_id' => $primary_course_id,
				'courses'   => array_map( 'intval', $course_ids ),
			);
		}

		return $formatted;
	}

	/**
	 * Get lesson URL in format: courses/{course-slug}/{lesson-id}/
	 *
	 * @param int $lesson_id Lesson ID.
	 * @param int $course_id Course ID (optional, will find if not provided).
	 * @return string
	 */
	private function get_lesson_url( $lesson_id, $course_id = null ) {
		// If no course ID provided, try to find it
		if ( empty( $course_id ) ) {
			$course_ids = $this->get_lesson_courses( $lesson_id );
			$course_id = ! empty( $course_ids ) ? $course_ids[0] : null;
		}

		// If still no course ID, return default permalink
		if ( empty( $course_id ) ) {
			return get_permalink( $lesson_id );
		}

		// Get course slug
		$course_slug = get_post_field( 'post_name', $course_id );
		
		// If course slug is empty, try to get it from permalink
		if ( empty( $course_slug ) ) {
			$course_permalink = get_permalink( $course_id );
			if ( $course_permalink ) {
				$parsed = parse_url( $course_permalink );
				$path = trim( $parsed['path'], '/' );
				$path_parts = explode( '/', $path );
				$course_slug = end( $path_parts );
			}
		}

		// Get courses page slug from MasterStudy settings
		$courses_page_slug = 'courses';
		if ( class_exists( 'STM_LMS_Options' ) ) {
			$courses_page_slug = \STM_LMS_Options::courses_page_slug();
		}

		// Build URL: courses/{course-slug}/{lesson-id}/
		$lesson_url = home_url( '/' . $courses_page_slug . '/' . $course_slug . '/' . $lesson_id . '/' );

		return esc_url( $lesson_url );
	}

	/**
	 * Register combined courses and lessons search endpoint
	 */
	public function register_combined_search_endpoint() {
		register_rest_route(
			'masterstudy-lms/v2',
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'combined_search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					's'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Search term (searches in titles and content)',
					),
					'category' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Category IDs (comma-separated) to filter courses and lessons by course category',
					),
					'per_page' => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 10,
						'description' => 'Number of results per page',
					),
					'page'     => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 1,
						'description' => 'Page number',
					),
					'sort'     => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Sort by: date_low, price_high, price_low, rating, popular (for courses only)',
					),
				),
			)
		);
	}

	/**
	 * Combined search for courses and lessons
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	public function combined_search( $request ) {
		$search_term = $request->get_param( 's' );
		$category    = $request->get_param( 'category' );
		$per_page    = intval( $request->get_param( 'per_page' ) ) ?: 10;
		$page        = intval( $request->get_param( 'page' ) ) ?: 1;
		$offset      = $per_page * ( $page - 1 );

		$results = array(
			'courses' => array(),
			'lessons' => array(),
			'total'   => 0,
			'pages'   => 0,
		);

		// Allow category-only filtering without search term
		if ( empty( $search_term ) && empty( $category ) ) {
			return new WP_REST_Response( $results, 200 );
		}

		global $wpdb;

		// Build courses query with optional category filter
		$courses_from = "{$wpdb->posts} p";
		$courses_where = array(
			"p.post_type = 'stm-courses'",
			"p.post_status = 'publish'",
		);

		$courses_params = array();

		// Add search filter if provided
		if ( ! empty( $search_term ) ) {
		$search_like = '%' . $wpdb->esc_like( $search_term ) . '%';
			$courses_where[] = "(
				LOWER(p.post_title) LIKE %s
				OR LOWER(p.post_content) LIKE %s
				OR LOWER(p.post_excerpt) LIKE %s
			)";
			$courses_params = array( $search_like, $search_like, $search_like );
		}

		// Add category filter if provided
		if ( ! empty( $category ) ) {
			$category_ids = $this->parse_category_parameter( $category );
			
			if ( ! empty( $category_ids ) ) {
				$category_placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
				
				// Use JOIN for category filtering
				$courses_from .= " 
					INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				";
				
				$courses_where[] = "tt.taxonomy = 'stm_lms_course_taxonomy'";
				$courses_where[] = "tt.term_id IN ($category_placeholders)";
				
				$courses_params = array_merge( $courses_params, $category_ids );
			}
		}

		$courses_where_sql = implode( ' AND ', $courses_where );

		// Search courses (case-insensitive, partial match in title and content)
		// Get ALL courses first (no pagination - we'll paginate after merging with courses from lessons)
		$courses_query = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_date, p.post_author
			FROM $courses_from
			WHERE $courses_where_sql
			ORDER BY p.post_date DESC",
			...$courses_params
		);

		$courses = $wpdb->get_results( $courses_query );

		// Format courses (without lessons array - they're separate in search endpoint)
		foreach ( $courses as $course ) {
			$course_data = array(
				'id'       => intval( $course->ID ),
				'title'    => $course->post_title,
				'excerpt'  => wp_trim_words( $course->post_excerpt ?: $course->post_content, 20 ),
				'link'     => get_permalink( $course->ID ),
				'type'     => 'course',
				'date'     => $course->post_date,
				'author'   => intval( $course->post_author ),
			);

			// Add course-specific data if available
			if ( class_exists( '\MasterStudy\Lms\Repositories\CourseRepository' ) ) {
				try {
					$repo = new \MasterStudy\Lms\Repositories\CourseRepository();
					$full_course = $repo->find( $course->ID, 'grid' );
					if ( $full_course ) {
						$course_data['price'] = $full_course->price ?? 0;
						$course_data['rating'] = $full_course->rating ?? 0;
						$course_data['students'] = $full_course->current_students ?? 0;
					}
				} catch ( Exception $e ) {
					// Continue without additional data
				}
			}

			$results['courses'][] = $course_data;
		}

		// Search lessons (case-insensitive, partial match in title and content)
		// Get ALL matching lessons (no pagination limit for now)
		if ( ! empty( $search_term ) ) {
			$lessons = $this->search_lessons( $search_term, 1000, $category, 0 );
		} else {
			// If no search term but category is provided, get all lessons from that category
			$lessons = $this->get_lessons_by_category( $category, 1000, 0 );
		}

		// Enhance lessons with course information
		$enhanced_lessons = array();
		foreach ( $lessons as $lesson ) {
			$primary_course_id = $lesson['course_id'] ?? null;
			
			// Add course information to lesson
			$enhanced_lesson = $lesson;
			if ( ! empty( $primary_course_id ) ) {
				$enhanced_lesson['course_link'] = get_permalink( $primary_course_id );
				$enhanced_lesson['course_title'] = get_the_title( $primary_course_id ) ?: '';
			} else {
				$enhanced_lesson['course_link'] = '';
				$enhanced_lesson['course_title'] = '';
			}
			
			$enhanced_lessons[] = $enhanced_lesson;
		}

		// Get sort parameter
		$sort = $request->get_param( 'sort' );

		// Apply sorting to courses if requested
		if ( ! empty( $sort ) ) {
			$supported_sorts = array( 'date_high', 'date_low', 'newest', 'oldest', 'price_high', 'price_low', 'rating', 'popular' );
			if ( in_array( strtolower( $sort ), $supported_sorts, true ) ) {
				$results['courses'] = $this->sort_courses_array( $results['courses'], $sort );
			}
		}

		// Apply pagination - courses and lessons are paginated together
		// First courses, then lessons, up to per_page total items
		$total_courses = count( $results['courses'] );
		$total_lessons = count( $enhanced_lessons );
		$total_items = $total_courses + $total_lessons;
		
		$offset = $per_page * ( $page - 1 );
		
		// Paginate courses first
		$courses_to_show = min( $per_page, $total_courses - $offset );
		if ( $courses_to_show > 0 && $offset < $total_courses ) {
			$results['courses'] = array_slice( $results['courses'], $offset, $courses_to_show );
		} else {
			$results['courses'] = array();
		}
		
		// Remaining slots for lessons
		$remaining_slots = $per_page - count( $results['courses'] );
		$lessons_offset = max( 0, $offset - $total_courses );
		
		if ( $remaining_slots > 0 && $lessons_offset < $total_lessons ) {
			$results['lessons'] = array_slice( $enhanced_lessons, $lessons_offset, $remaining_slots );
		} else {
			$results['lessons'] = array();
		}

		// Calculate totals (courses + lessons)
		$results['total'] = $total_items;
		$results['pages'] = ceil( $total_items / $per_page );

		return new WP_REST_Response( $results, 200 );
	}


	/**
	 * Register agent progress endpoint.
	 */
	public function register_agent_progress_endpoint() {
		register_rest_route(
			'masterstudy-lms/v2',
			'/agent-progress',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_agent_progress' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'agent_id'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Agent identifier: accepts user ID, email address, or username.',
					),
					'status'         => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'all',
						'enum'        => array( 'all', 'completed', 'ongoing' ),
						'description' => 'Filter response to a specific status bucket.',
					),
					'include_lessons' => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Whether to include lesson progress data.',
					),
				),
			)
		);
	}

	/**
	 * Return agent progress grouped by status.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_agent_progress( $request ) {
		$agent_identifier = $request->get_param( 'agent_id' );

		if ( empty( $agent_identifier ) ) {
			return new WP_Error(
				'invalid_agent_id',
				esc_html__( 'A valid agent_id parameter is required.', 'masterstudy-search-api' ),
				array( 'status' => 400 )
			);
		}

		$agent = $this->resolve_agent_user( $agent_identifier );

		if ( ! $agent ) {
			return new WP_Error(
				'agent_not_found',
				esc_html__( 'Agent not found.', 'masterstudy-search-api' ),
				array( 'status' => 404 )
			);
		}

		$agent_id        = intval( $agent->ID );
		$status          = $request->get_param( 'status' ) ?: 'all';
		$include_lessons = $request->get_param( 'include_lessons' );
		$include_lessons = is_null( $include_lessons ) ? true : rest_sanitize_boolean( $include_lessons );
		$course_threshold = $this->get_course_completion_threshold();

		try {
			$courses = $this->get_agent_courses_progress( $agent_id, $course_threshold );
			$lessons = $include_lessons ? $this->get_agent_lessons_progress( $agent_id ) : array(
				'completed' => array(),
				'ongoing'   => array(),
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'agent_progress_error',
				esc_html__( 'Unable to load agent progress.', 'masterstudy-search-api' ),
				array(
					'status'  => 500,
					'details' => $e->getMessage(),
				)
			);
		}

		$summary = array(
			'courses' => array(
				'completed' => count( $courses['completed'] ),
				'ongoing'   => count( $courses['ongoing'] ),
			),
			'lessons' => array(
				'completed' => $include_lessons ? count( $lessons['completed'] ) : 0,
				'ongoing'   => $include_lessons ? count( $lessons['ongoing'] ) : 0,
			),
		);

		$response = array(
			'agent_id'         => $agent_id,
			'status_filter'    => $status,
			'course_threshold' => $course_threshold,
			'courses'          => $this->filter_progress_sets( $courses, $status ),
			'lessons'          => $include_lessons ? $this->filter_progress_sets( $lessons, $status ) : array(),
			'summary'          => $summary,
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Filter progress buckets by requested status.
	 *
	 * @param array  $data   Progress buckets.
	 * @param string $status Requested status.
	 * @return array
	 */
	private function filter_progress_sets( $data, $status ) {
		if ( 'completed' === $status ) {
			return array(
				'completed' => $data['completed'],
				'ongoing'   => array(),
			);
		}

		if ( 'ongoing' === $status ) {
			return array(
				'completed' => array(),
				'ongoing'   => $data['ongoing'],
			);
		}

		return $data;
	}

	/**
	 * Resolve agent identifier to WP_User.
	 *
	 * @param string $identifier Agent identifier (ID, email, or username).
	 * @return WP_User|false
	 */
	private function resolve_agent_user( $identifier ) {
		$identifier = trim( (string) $identifier );

		if ( '' === $identifier ) {
			return false;
		}

		// Numeric user ID.
		if ( is_numeric( $identifier ) ) {
			$user = get_user_by( 'ID', intval( $identifier ) );
			if ( $user ) {
				return $user;
			}
		}

		// Email address lookup.
		if ( function_exists( 'is_email' ) && is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
			if ( $user ) {
				return $user;
			}
		}

		// Fallback to username/login check.
		return get_user_by( 'login', $identifier );
	}

	/**
	 * Get course completion threshold from MasterStudy settings.
	 *
	 * @return int
	 */
	private function get_course_completion_threshold() {
		if ( class_exists( 'STM_LMS_Options' ) && method_exists( 'STM_LMS_Options', 'get_option' ) ) {
			return intval( \STM_LMS_Options::get_option( 'certificate_threshold', 70 ) );
		}

		return 70;
	}

	/**
	 * Fetch agent courses grouped by completion status.
	 *
	 * @param int $agent_id Agent user ID.
	 * @param int $completion_threshold Completion threshold percent.
	 * @return array
	 */
	private function get_agent_courses_progress( $agent_id, $completion_threshold ) {
		global $wpdb;

		$table = $this->get_user_courses_table();

		$query = $wpdb->prepare(
			"SELECT uc.*, p.post_title, p.post_excerpt, p.post_content, p.post_author, p.post_date
			FROM {$table} uc
			INNER JOIN {$wpdb->posts} p ON p.ID = uc.course_id
			WHERE uc.user_id = %d
				AND p.post_type = 'stm-courses'
				AND p.post_status IN ('publish','private')
			ORDER BY uc.user_course_id DESC",
			$agent_id
		);

		$rows = $wpdb->get_results( $query );

		$data = array(
			'completed' => array(),
			'ongoing'   => array(),
		);

		if ( empty( $rows ) ) {
			return $data;
		}

		foreach ( $rows as $row ) {
			$item      = $this->format_course_progress_item( $row, $completion_threshold );
			$progress  = intval( $row->progress_percent );
			$bucket    = ( $progress >= $completion_threshold ) ? 'completed' : 'ongoing';
			$data[ $bucket ][] = $item;
		}

		return $data;
	}

	/**
	 * Format a single course progress item.
	 *
	 * @param stdClass $row Course row.
	 * @param int      $completion_threshold Threshold percent.
	 * @return array
	 */
	private function format_course_progress_item( $row, $completion_threshold ) {
		$course_id     = intval( $row->course_id );
		$progress      = intval( $row->progress_percent );
		$status        = ( $progress >= $completion_threshold ) ? 'completed' : 'ongoing';
		$current_lesson_id = intval( $row->current_lesson_id );

		$current_lesson = null;
		if ( $current_lesson_id > 0 ) {
			$current_lesson = array(
				'id'    => $current_lesson_id,
				'title' => get_the_title( $current_lesson_id ) ?: '',
				'link'  => $this->get_lesson_url( $current_lesson_id, $course_id ),
			);
		}

		$item = array(
			'id'               => $course_id,
			'title'            => $row->post_title,
			'excerpt'          => wp_trim_words( $row->post_excerpt ?: $row->post_content, 20 ),
			'link'             => get_permalink( $course_id ),
			'type'             => 'course',
			'date'             => $row->post_date,
			'author'           => intval( $row->post_author ),
			'author_name'      => get_the_author_meta( 'display_name', $row->post_author ),
			'progress_percent' => $progress,
			'status'           => $status,
			'start_time'       => intval( $row->start_time ),
			'end_time'         => intval( $row->end_time ),
			'current_lesson'   => $current_lesson,
		);

		if ( class_exists( '\MasterStudy\Lms\Repositories\CourseRepository' ) ) {
			try {
				$repo        = new \MasterStudy\Lms\Repositories\CourseRepository();
				$full_course = $repo->find( $course_id, 'grid' );

				if ( $full_course ) {
					$item['price']    = $full_course->price ?? 0;
					$item['rating']   = $full_course->rating ?? 0;
					$item['students'] = $full_course->current_students ?? 0;
					$item['thumbnail'] = $full_course->image ?? '';
				}
			} catch ( Exception $e ) {
				// Continue without repository data.
			}
		}

		return $item;
	}

	/**
	 * Fetch agent lessons grouped by completion status.
	 *
	 * @param int $agent_id Agent user ID.
	 * @return array
	 */
	private function get_agent_lessons_progress( $agent_id ) {
		global $wpdb;

		$table = $this->get_user_lessons_table();

		$query = $wpdb->prepare(
			"SELECT ul.*, l.post_title, l.post_excerpt, l.post_content, l.post_author, l.post_date
			FROM {$table} ul
			INNER JOIN {$wpdb->posts} l ON l.ID = ul.lesson_id
			WHERE ul.user_id = %d
				AND l.post_type = 'stm-lessons'
				AND l.post_status = 'publish'
			ORDER BY ul.user_lesson_id DESC",
			$agent_id
		);

		$rows = $wpdb->get_results( $query );

		$data = array(
			'completed' => array(),
			'ongoing'   => array(),
		);

		if ( empty( $rows ) ) {
			return $data;
		}

		foreach ( $rows as $row ) {
			$item     = $this->format_lesson_progress_item( $row );
			$progress = is_null( $row->progress ) ? 0 : intval( $row->progress );
			$bucket   = ( $progress >= 100 || intval( $row->end_time ) > 0 ) ? 'completed' : 'ongoing';
			$data[ $bucket ][] = $item;
		}

		return $data;
	}

	/**
	 * Format a single lesson progress record.
	 *
	 * @param stdClass $row Lesson row.
	 * @return array
	 */
	private function format_lesson_progress_item( $row ) {
		$lesson_id = intval( $row->lesson_id );
		$course_id = intval( $row->course_id );
		$progress  = is_null( $row->progress ) ? 0 : intval( $row->progress );

		return array(
			'id'              => $lesson_id,
			'title'           => $row->post_title,
			'excerpt'         => wp_trim_words( $row->post_excerpt ?: $row->post_content, 20 ),
			'link'            => $this->get_lesson_url( $lesson_id, $course_id ),
			'type'            => 'lesson',
			'date'            => $row->post_date,
			'author'          => intval( $row->post_author ),
			'author_name'     => get_the_author_meta( 'display_name', $row->post_author ),
			'course_id'       => $course_id,
			'course_title'    => get_the_title( $course_id ) ?: '',
			'course_link'     => get_permalink( $course_id ),
			'progress'        => $progress,
			'status'          => ( $progress >= 100 || intval( $row->end_time ) > 0 ) ? 'completed' : 'ongoing',
			'start_time'      => intval( $row->start_time ),
			'end_time'        => intval( $row->end_time ),
		);
	}

	/**
	 * Get user courses table name with fallback.
	 *
	 * @return string
	 */
	private function get_user_courses_table() {
		global $wpdb;

		if ( function_exists( 'stm_lms_user_courses_name' ) ) {
			return stm_lms_user_courses_name( $wpdb );
		}

		return $wpdb->prefix . 'stm_lms_user_courses';
	}

	/**
	 * Get user lessons table name with fallback.
	 *
	 * @return string
	 */
	private function get_user_lessons_table() {
		global $wpdb;

		if ( function_exists( 'stm_lms_user_lessons_name' ) ) {
			return stm_lms_user_lessons_name( $wpdb );
		}

		return $wpdb->prefix . 'stm_lms_user_lessons';
	}

	/**
	 * Group lessons by their course IDs
	 *
	 * @param array $lessons Array of lesson data.
	 * @return array Array keyed by course ID, each containing an array of lessons.
	 */
	private function group_lessons_by_course( $lessons ) {
		$grouped = array();
		
		foreach ( $lessons as $lesson ) {
			$course_ids = array();
			
			// Get all course IDs this lesson belongs to
			if ( ! empty( $lesson['courses'] ) && is_array( $lesson['courses'] ) ) {
				$course_ids = $lesson['courses'];
			} elseif ( ! empty( $lesson['course_id'] ) ) {
				$course_ids = array( $lesson['course_id'] );
			}
			
			// Remove lesson-specific fields that shouldn't be in the nested lesson object
			$lesson_data = $lesson;
			unset( $lesson_data['course_id'] );
			unset( $lesson_data['courses'] );
			
			// Add lesson to each course it belongs to
			foreach ( $course_ids as $course_id ) {
				if ( ! isset( $grouped[ $course_id ] ) ) {
					$grouped[ $course_id ] = array();
				}
				$grouped[ $course_id ][] = $lesson_data;
			}
		}
		
		return $grouped;
	}

	/**
	 * Get courses by their IDs
	 *
	 * @param array  $course_ids Array of course IDs.
	 * @param string $category Optional category filter (IDs/names/slugs, comma-separated).
	 * @return array Array of formatted course data.
	 */
	private function get_courses_by_ids( $course_ids, $category = null ) {
		if ( empty( $course_ids ) ) {
			return array();
		}

		global $wpdb;

		// Build query
		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		$from_clause = "{$wpdb->posts} p";
		$where_clauses = array(
			"p.post_type = 'stm-courses'",
			"p.post_status = 'publish'",
			"p.ID IN ($placeholders)",
		);
		$query_params = $course_ids;

		// Add category filter if provided
		if ( ! empty( $category ) ) {
			$category_ids = $this->parse_category_parameter( $category );
			
			if ( ! empty( $category_ids ) ) {
				$category_placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
				
				$from_clause .= " 
					INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				";
				
				$where_clauses[] = "tt.taxonomy = 'stm_lms_course_taxonomy'";
				$where_clauses[] = "tt.term_id IN ($category_placeholders)";
				
				$query_params = array_merge( $query_params, $category_ids );
			}
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$courses_query = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_date, p.post_author
			FROM $from_clause
			WHERE $where_sql
			ORDER BY p.post_date DESC",
			...$query_params
		);

		$courses = $wpdb->get_results( $courses_query );

		// Format courses
		$formatted = array();
		foreach ( $courses as $course ) {
			$course_data = array(
				'id'       => intval( $course->ID ),
				'title'    => $course->post_title,
				'excerpt'  => wp_trim_words( $course->post_excerpt ?: $course->post_content, 20 ),
				'link'     => get_permalink( $course->ID ),
				'type'     => 'course',
				'date'     => $course->post_date,
				'author'   => intval( $course->post_author ),
				'lessons'  => array(), // Initialize with empty lessons array
			);

			// Add course-specific data if available
			if ( class_exists( '\MasterStudy\Lms\Repositories\CourseRepository' ) ) {
				try {
					$repo = new \MasterStudy\Lms\Repositories\CourseRepository();
					$full_course = $repo->find( $course->ID, 'grid' );
					if ( $full_course ) {
						$course_data['price'] = $full_course->price ?? 0;
						$course_data['rating'] = $full_course->rating ?? 0;
						$course_data['students'] = $full_course->current_students ?? 0;
					}
				} catch ( Exception $e ) {
					// Continue without additional data
				}
			}

			$formatted[] = $course_data;
		}

		return $formatted;
	}

	/**
	 * Get courses that contain a specific lesson
	 *
	 * @param int $lesson_id Lesson ID.
	 * @return array Array of course IDs.
	 */
	private function get_lesson_courses( $lesson_id ) {
		$course_ids = array();

		// Method 1: Use MasterStudy's repository if available (most reliable)
		if ( class_exists( '\MasterStudy\Lms\Repositories\CurriculumRepository' ) ) {
			try {
				$repo = new \MasterStudy\Lms\Repositories\CurriculumRepository();
				$course_ids = $repo->get_lesson_course_ids( $lesson_id );
			} catch ( Exception $e ) {
				// Fall through to other methods
			}
		}

		// Method 2: Direct database query to curriculum_materials table
		if ( empty( $course_ids ) ) {
			global $wpdb;

			$curriculum_table = $wpdb->prefix . 'stm_lms_curriculum_materials';
			$sections_table = $wpdb->prefix . 'stm_lms_curriculum_sections';

			// Check if tables exist
			$materials_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $curriculum_table ) );
			$sections_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sections_table ) );

			if ( $materials_table_exists && $sections_table_exists ) {
				$course_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT s.course_id 
						FROM {$curriculum_table} m
						INNER JOIN {$sections_table} s ON m.section_id = s.id
						WHERE m.post_id = %d
						AND m.post_type = 'stm-lessons'",
						$lesson_id
					)
				);
			}
		}

		// Method 3: Fallback - search course meta for curriculum containing this lesson
		if ( empty( $course_ids ) ) {
			global $wpdb;
			
			// Search in postmeta for courses that have this lesson in their curriculum
			$course_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID 
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'stm-courses'
					AND p.post_status = 'publish'
					AND pm.meta_key = 'curriculum'
					AND pm.meta_value LIKE %s",
					'%' . $wpdb->esc_like( (string) $lesson_id ) . '%'
				)
			);
		}

		// Method 4: Match by exact or similar title (for lessons that match course titles)
		if ( empty( $course_ids ) ) {
			$lesson = get_post( $lesson_id );
			if ( $lesson && ! empty( $lesson->post_title ) ) {
				global $wpdb;
				
				// Search for courses with exact or very similar title
				$title_like = '%' . $wpdb->esc_like( $lesson->post_title ) . '%';
				
				$matching_courses = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID 
						FROM {$wpdb->posts}
						WHERE post_type = 'stm-courses'
						AND post_status = 'publish'
						AND (
							post_title = %s
							OR post_title LIKE %s
						)
						ORDER BY 
							CASE WHEN post_title = %s THEN 1 ELSE 2 END,
							post_date DESC
						LIMIT 5",
						$lesson->post_title,
						$title_like,
						$lesson->post_title
					)
				);

				if ( ! empty( $matching_courses ) ) {
					$course_ids = $matching_courses;
				}
			}
		}

		return array_map( 'intval', array_filter( $course_ids ) );
	}

	/**
	 * Sort an array of courses by the specified sort type
	 *
	 * @param array  $courses Array of course data.
	 * @param string $sort Sort type.
	 * @return array Sorted courses array.
	 */
	private function sort_courses_array( $courses, $sort ) {
		if ( empty( $courses ) || empty( $sort ) ) {
			return $courses;
		}

		// Normalize user-friendly sort names to MasterStudy format
		$sort_aliases = array(
			'newest' => 'date_high',
			'oldest' => 'date_low',
		);
		
		if ( isset( $sort_aliases[ strtolower( $sort ) ] ) ) {
			$sort = $sort_aliases[ strtolower( $sort ) ];
		}

		// Supported sort values (MasterStudy defaults + our additions)
		$supported_sorts = array( 'date_high', 'date_low', 'price_high', 'price_low', 'rating', 'popular' );
		
		// Check if sort is supported (either in SORT_MAPPING or in our custom list)
		$is_supported = false;
		if ( class_exists( '\MasterStudy\Lms\Repositories\CourseRepository' ) ) {
			$sort_mapping = \MasterStudy\Lms\Repositories\CourseRepository::SORT_MAPPING;
			$is_supported = isset( $sort_mapping[ $sort ] );
		}
		
		// Also check our custom supported list (for date_high which might not be in SORT_MAPPING)
		if ( ! $is_supported && in_array( $sort, $supported_sorts, true ) ) {
			$is_supported = true;
		}
		
		if ( ! $is_supported ) {
			return $courses;
		}

		// Convert all courses to arrays if they are objects
		$courses = array_map( function( $course ) {
			if ( is_object( $course ) ) {
				return (array) $course;
			}
			return $course;
		}, $courses );

		// Sort based on type
		switch ( $sort ) {
			case 'date_high':
				// Newest first (date descending)
				usort( $courses, function( $a, $b ) {
					$date_a = strtotime( $a['date'] ?? '' );
					$date_b = strtotime( $b['date'] ?? '' );
					return $date_b <=> $date_a;
				} );
				break;

			case 'date_low':
				// Oldest first (date ascending)
				usort( $courses, function( $a, $b ) {
					$date_a = strtotime( $a['date'] ?? '' );
					$date_b = strtotime( $b['date'] ?? '' );
					return $date_a <=> $date_b;
				} );
				break;

			case 'price_high':
				usort( $courses, function( $a, $b ) {
					$price_a = floatval( $a['price'] ?? 0 );
					$price_b = floatval( $b['price'] ?? 0 );
					return $price_b <=> $price_a;
				} );
				break;

			case 'price_low':
				usort( $courses, function( $a, $b ) {
					$price_a = floatval( $a['price'] ?? 0 );
					$price_b = floatval( $b['price'] ?? 0 );
					return $price_a <=> $price_b;
				} );
				break;

			case 'rating':
				usort( $courses, function( $a, $b ) {
					$rating_a = floatval( $a['rating'] ?? 0 );
					$rating_b = floatval( $b['rating'] ?? 0 );
					return $rating_b <=> $rating_a;
				} );
				break;

			case 'popular':
				usort( $courses, function( $a, $b ) {
					$students_a = intval( $a['students'] ?? 0 );
					$students_b = intval( $b['students'] ?? 0 );
					return $students_b <=> $students_a;
				} );
				break;

			default:
				// Default: newest first (date descending)
				usort( $courses, function( $a, $b ) {
					$date_a = strtotime( $a['date'] ?? '' );
					$date_b = strtotime( $b['date'] ?? '' );
					return $date_b <=> $date_a;
				} );
				break;
		}

		return $courses;
	}

	/**
	 * Get sorted courses with search
	 *
	 * @param string $search_term Search term.
	 * @param string $sort Sort type.
	 * @param int    $per_page Results per page.
	 * @param int    $offset Offset.
	 * @param string $category Category IDs (comma-separated) to filter by.
	 * @return array
	 */
	private function get_sorted_courses( $search_term, $sort, $per_page, $offset, $category = null ) {
		if ( ! class_exists( '\MasterStudy\Lms\Repositories\CourseRepository' ) ) {
			return array();
		}

		// Normalize user-friendly sort names to MasterStudy format
		$sort_aliases = array(
			'newest' => 'date_high',
			'oldest' => 'date_low',
		);
		
		if ( isset( $sort_aliases[ strtolower( $sort ) ] ) ) {
			$sort = $sort_aliases[ strtolower( $sort ) ];
		}

		try {
			$repo = new \MasterStudy\Lms\Repositories\CourseRepository();
			$request_data = array(
				's'        => $search_term,
				'sort'     => $sort,
				'per_page' => $per_page,
				'page'     => floor( $offset / $per_page ) + 1,
			);

			// Add category filter if provided
			if ( ! empty( $category ) ) {
				$request_data['category'] = $category;
			}

			$result = $repo->get_all( $request_data );
			
			// Format courses to match our structure
			$formatted = array();
			foreach ( $result['courses'] as $course ) {
				$formatted[] = array(
					'id'       => $course->id ?? 0,
					'title'    => $course->title ?? '',
					'excerpt'  => $course->excerpt ?? '',
					'link'     => $course->link ?? '',
					'type'     => 'course',
					'price'    => $course->price ?? 0,
					'rating'   => $course->rating ?? 0,
					'students' => $course->current_students ?? 0,
					'lessons'  => array(), // Initialize with empty lessons array
				);
			}
			
			return $formatted;
		} catch ( Exception $e ) {
			return array();
		}
	}
}

// Initialize the plugin
function masterstudy_search_api_init() {
	$instance = MasterStudy_Search_API::get_instance();
	
	// Initialize GitHub updater
	if ( is_admin() ) {
		new MasterStudy_Search_API_GitHub_Updater( __FILE__ );
	}
	
	return $instance;
}

// Hook into plugins_loaded to ensure MasterStudy is loaded first
add_action( 'plugins_loaded', 'masterstudy_search_api_init', 20 );


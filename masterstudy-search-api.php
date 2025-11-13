<?php
/**
 * Plugin Name: MasterStudy Search API
 * Plugin URI: https://github.com/aldeentorres/masterstudy-search-api
 * Description: Enhanced search API for MasterStudy LMS with powerful search capabilities. Makes courses and lessons searchable via REST API. Includes case-insensitive partial matching for courses and lessons, category filtering, and category-only filtering. Perfect for external applications, mobile apps, and third-party integrations.
 * Version: 1.0.0
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
	const VERSION = '1.0.0';

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
			
			// If there's a search term, also include lessons
			$search_term = $request->get_param( 's' );
			if ( ! empty( $search_term ) ) {
				$per_page = intval( $request->get_param( 'per_page' ) ) ?: 10;
				$page = intval( $request->get_param( 'page' ) ) ?: 1;
				$offset = $per_page * ( $page - 1 );
				$lessons = $this->search_lessons( $search_term, $per_page, $category, $offset );
				
				// Add lessons to the response
				if ( is_array( $response_data ) ) {
					$response_data['lessons'] = $lessons;
					$response_data['total'] = ( $response_data['total'] ?? 0 ) + count( $lessons );
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
		if ( ! empty( $sort ) && class_exists( '\MasterStudy\Lms\Repositories\CourseRepository' ) ) {
			$sort_mapping = \MasterStudy\Lms\Repositories\CourseRepository::SORT_MAPPING;
			
			if ( isset( $sort_mapping[ $sort ] ) ) {
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
		$courses_query = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_date, p.post_author
			FROM $courses_from
			WHERE $courses_where_sql
			ORDER BY p.post_date DESC
			LIMIT %d OFFSET %d",
			...array_merge( $courses_params, array( $per_page, $offset ) )
		);

		$courses = $wpdb->get_results( $courses_query );

		// Get total courses count
		$courses_count_query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) 
			FROM $courses_from
			WHERE $courses_where_sql",
			...$courses_params
		);
		$courses_total = intval( $wpdb->get_var( $courses_count_query ) );

		// Format courses
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
		// Use the same search_lessons method which handles category filtering
		// Only search lessons if there's a search term, otherwise just filter by category
		if ( ! empty( $search_term ) ) {
			$lessons = $this->search_lessons( $search_term, $per_page, $category, $offset );
		} else {
			// If no search term but category is provided, get all lessons from that category
			$lessons = $this->get_lessons_by_category( $category, $per_page, $offset );
		}
		
		// Get total lessons count with category filter
		// Reuse the same logic as search_lessons but for count
		$lessons_where = array(
			"l.post_type = 'stm-lessons'",
			"l.post_status = 'publish'",
		);

		$lessons_params = array();
		$lessons_from = "{$wpdb->posts} l";

		// Add search filter if provided
		if ( ! empty( $search_term ) ) {
			$search_like = '%' . $wpdb->esc_like( $search_term ) . '%';
			$lessons_where[] = "(
				LOWER(l.post_title) LIKE %s
				OR LOWER(l.post_content) LIKE %s
				OR LOWER(l.post_excerpt) LIKE %s
			)";
			$lessons_params = array( $search_like, $search_like, $search_like );
		}

		// Add category filter for lessons if provided
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
					$lessons_from .= "
						INNER JOIN {$curriculum_table} m ON l.ID = m.post_id
						INNER JOIN {$sections_table} s ON m.section_id = s.id
						INNER JOIN {$wpdb->posts} c ON s.course_id = c.ID
						INNER JOIN {$wpdb->term_relationships} tr ON c.ID = tr.object_id
						INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					";
					
					$lessons_where[] = "m.post_type = 'stm-lessons'";
					$lessons_where[] = "c.post_type = 'stm-courses'";
					$lessons_where[] = "c.post_status = 'publish'";
					$lessons_where[] = "tt.taxonomy = 'stm_lms_course_taxonomy'";
					$lessons_where[] = "tt.term_id IN ($category_placeholders)";
					
					$lessons_params = array_merge( $lessons_params, $category_ids );
				} else {
					// If curriculum tables don't exist, no lessons can be filtered by category
					$lessons_total = 0;
				}
			}
		}

		// Get total lessons count
		if ( ! isset( $lessons_total ) ) {
			$lessons_where_sql = implode( ' AND ', $lessons_where );
			
		$lessons_count_query = $wpdb->prepare(
				"SELECT COUNT(DISTINCT l.ID) 
				FROM $lessons_from
				WHERE $lessons_where_sql",
				...$lessons_params
		);
		$lessons_total = intval( $wpdb->get_var( $lessons_count_query ) );
		}

		// Lessons are already formatted by search_lessons method
		$results['lessons'] = $lessons;

		// Calculate totals
		$results['total'] = $courses_total + $lessons_total;
		$results['pages'] = ceil( max( $courses_total, $lessons_total ) / $per_page );

		// Apply sorting if requested (only affects courses)
		if ( ! empty( $request->get_param( 'sort' ) ) && class_exists( '\MasterStudy\Lms\Repositories\CourseRepository' ) ) {
			$sort = $request->get_param( 'sort' );
			$sort_mapping = \MasterStudy\Lms\Repositories\CourseRepository::SORT_MAPPING;
			
			if ( isset( $sort_mapping[ $sort ] ) ) {
				// Re-fetch courses with sorting
				$sorted_courses = $this->get_sorted_courses( $search_term, $sort, $per_page, $offset, $category );
				if ( ! empty( $sorted_courses ) ) {
					$results['courses'] = $sorted_courses;
				}
			}
		}

		return new WP_REST_Response( $results, 200 );
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


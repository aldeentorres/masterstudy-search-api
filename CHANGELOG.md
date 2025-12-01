# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2025-01-XX

### Changed
- **Courses endpoint** (`/wp-json/masterstudy-lms/v2/courses`): Lessons are now nested inside courses in a `lessons` array
- **Search endpoint** (`/wp-json/masterstudy-lms/v2/search`): Returns courses and lessons as separate arrays (lessons include course information)
- Improved pagination logic - now paginates courses correctly (not lessons)
- Sorting now works properly on courses even when only lessons match the search

### Added
- User-friendly sort aliases: `newest` (alias for `date_high`) and `oldest` (alias for `date_low`)
- Lessons in search endpoint now include `course_id`, `course_title`, and `course_link` fields
- Better documentation explaining the difference between courses and search endpoints

### Fixed
- Fixed pagination issue where `per_page` and `page` parameters weren't working correctly when search only matched lessons
- Fixed sorting to work on courses instead of lessons
- Fixed total count to reflect courses (not lessons) in courses endpoint

## [1.0.0] - 2025-01-XX

### Added
- Public access to MasterStudy LMS courses API endpoint without nonce requirement
- Public search API for courses and lessons
- Combined search endpoint for courses and lessons (`/wp-json/masterstudy-lms/v2/search`)
- Case-insensitive partial word matching in search
- Lesson search support with REST API enabled for `stm-lessons` post type
- Automatic course-lesson relationship detection
- Lesson URLs formatted as `courses/{course-slug}/{lesson-id}/`
- Multiple fallback methods for finding lesson-course relationships
- Support for all course search parameters (sort, filter, pagination)

### Features
- ✅ Case-insensitive search (works with any capitalization)
- ✅ Partial word matching (finds "river" in "Riverside")
- ✅ Searches in both titles and content/descriptions
- ✅ Returns both courses and lessons in search results
- ✅ Maintains security for other endpoints
- ✅ Survives MasterStudy plugin updates


# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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


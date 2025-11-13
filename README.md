# MasterStudy Search API

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net/)

A WordPress plugin that provides enhanced search API capabilities for MasterStudy LMS. Makes courses and lessons searchable via REST API with powerful search features. Includes case-insensitive partial matching for both courses and lessons, and category filtering support.

## Description

This plugin extends the MasterStudy LMS API with enhanced search capabilities. It provides:
- Accessible courses API endpoint (`/wp-json/masterstudy-lms/v2/courses`)
- Combined search endpoint for courses and lessons (`/wp-json/masterstudy-lms/v2/search`)
- Case-insensitive partial word matching
- Lesson search with REST API support
- Automatic course-lesson relationship detection
- **Category filtering** - Filter by MasterStudy course categories (e.g., countries)
- **Category-only filtering** - Get all courses/lessons from a specific category without search term

Perfect for external applications, mobile apps, and third-party integrations.

## Features

- ✅ Enhanced courses API endpoint access
- ✅ Simplified authentication for courses endpoint
- ✅ Maintains security for other endpoints
- ✅ Survives MasterStudy plugin updates
- ✅ Lightweight and efficient
- ✅ Category filtering support
- ✅ Combined courses and lessons search
- ✅ Case-insensitive partial matching

## Installation

1. Upload the `masterstudy-search-api` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The enhanced search API is now available!

## API Endpoints

### 1. Courses Endpoint
**Base URL:** `/wp-json/masterstudy-lms/v2/courses`

Returns courses with optional search, filtering, and sorting capabilities.

### 2. Search Endpoint
**Base URL:** `/wp-json/masterstudy-lms/v2/search`

Returns both courses and lessons with search, filtering, and sorting capabilities.

## API Parameters

### Common Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `s` | string | No | - | Search term (searches in titles and content) |
| `category` | string | No | - | Category identifier (comma-separated). Supports IDs, names, or slugs (e.g., `168`, `Vietnam`, `vietnam`, or `168,Vietnam`) |
| `per_page` | integer | No | 10 | Number of results per page |
| `page` | integer | No | 1 | Page number for pagination |
| `sort` | string | No | - | Sort order (see Sort Options below) |
| `author` | integer | No | - | Filter by instructor/author ID |

### Sort Options

| Value | Description |
|-------|-------------|
| `date_low` | Sort by date (oldest first) |
| `price_high` | Sort by price (highest first) |
| `price_low` | Sort by price (lowest first) |
| `rating` | Sort by rating (highest first) |
| `popular` | Sort by popularity/views (highest first) |

**Note:** Sorting only applies to courses, not lessons.

## Usage Examples

### Basic Search

**Search courses:**
```
GET /wp-json/masterstudy-lms/v2/courses?s=javascript
```

**Search courses and lessons:**
```
GET /wp-json/masterstudy-lms/v2/search?s=marketing
```

### Category Filtering

**Get all courses from a specific category (by ID):**
```
GET /wp-json/masterstudy-lms/v2/courses?category=168
```

**Get all courses from a specific category (by name):**
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam
```

**Get all courses from a specific category (by slug):**
```
GET /wp-json/masterstudy-lms/v2/courses?category=vietnam
```

**Get all courses and lessons from a specific category:**
```
GET /wp-json/masterstudy-lms/v2/search?category=Vietnam
```

**Multiple categories (comma-separated, mix of IDs and names):**
```
GET /wp-json/masterstudy-lms/v2/courses?category=168,169
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam,Thailand
GET /wp-json/masterstudy-lms/v2/courses?category=168,Thailand
```

### Category + Search

**Search within a specific category (using name):**
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam&s=marketing
```

**Search within a specific category (using ID):**
```
GET /wp-json/masterstudy-lms/v2/courses?category=168&s=marketing
```

**Search courses and lessons within a category:**
```
GET /wp-json/masterstudy-lms/v2/search?category=Vietnam&s=marketing
```

### Sorting

**Sort courses by rating:**
```
GET /wp-json/masterstudy-lms/v2/courses?sort=rating
```

**Sort by price (low to high):**
```
GET /wp-json/masterstudy-lms/v2/courses?sort=price_low
```

**Sort with search:**
```
GET /wp-json/masterstudy-lms/v2/courses?s=javascript&sort=rating
```

**Sort with category:**
```
GET /wp-json/masterstudy-lms/v2/courses?category=168&sort=popular
```

### Pagination

**Get second page with 20 results per page:**
```
GET /wp-json/masterstudy-lms/v2/courses?per_page=20&page=2
```

**Combined example:**
```
GET /wp-json/masterstudy-lms/v2/search?category=168&s=marketing&per_page=20&page=1&sort=rating
```

### Filter by Author

**Get courses by specific instructor:**
```
GET /wp-json/masterstudy-lms/v2/courses?author=5
```

**Combine with search:**
```
GET /wp-json/masterstudy-lms/v2/courses?author=5&s=javascript
```

## Response Format

### Courses Endpoint Response

```json
{
  "courses": [
    {
      "id": 123,
      "title": "Course Title",
      "excerpt": "Course description...",
      "link": "https://yoursite.com/course/...",
      "type": "course",
      "date": "2024-01-15 10:00:00",
      "author": 5,
      "price": 99,
      "rating": 4.5,
      "students": 150
    }
  ],
  "lessons": [
    {
      "id": 456,
      "title": "Lesson Title",
      "excerpt": "Lesson description...",
      "link": "https://yoursite.com/courses/course-slug/456/",
      "type": "lesson",
      "date": "2024-01-15 10:00:00",
      "author": 5,
      "course_id": 123,
      "courses": [123]
    }
  ],
  "total": 50,
  "pages": 5
}
```

**Note:** The `lessons` array is only included when a search term (`s`) is provided.

### Search Endpoint Response

```json
{
  "courses": [
    {
      "id": 123,
      "title": "Course Title",
      "excerpt": "Course description...",
      "link": "https://yoursite.com/course/...",
      "type": "course",
      "date": "2024-01-15 10:00:00",
      "author": 5,
      "price": 99,
      "rating": 4.5,
      "students": 150
    }
  ],
  "lessons": [
    {
      "id": 456,
      "title": "Lesson Title",
      "excerpt": "Lesson description...",
      "link": "https://yoursite.com/courses/course-slug/456/",
      "type": "lesson",
      "date": "2024-01-15 10:00:00",
      "author": 5,
      "course_id": 123,
      "courses": [123]
    }
  ],
  "total": 15,
  "pages": 2
}
```

**Note:** The search endpoint always returns both courses and lessons (when applicable).

## Response Fields

### Course Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Course post ID |
| `title` | string | Course title |
| `excerpt` | string | Course excerpt/description (trimmed to 20 words) |
| `link` | string | Full URL to the course |
| `type` | string | Always `"course"` |
| `date` | string | Publication date (YYYY-MM-DD HH:MM:SS) |
| `author` | integer | Author/instructor user ID |
| `price` | float | Course price (if available) |
| `rating` | float | Course rating (if available) |
| `students` | integer | Number of enrolled students (if available) |

### Lesson Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Lesson post ID |
| `title` | string | Lesson title |
| `excerpt` | string | Lesson excerpt/description (trimmed to 20 words) |
| `link` | string | Full URL to the lesson |
| `type` | string | Always `"lesson"` |
| `date` | string | Publication date (YYYY-MM-DD HH:MM:SS) |
| `author` | integer | Author/instructor user ID |
| `course_id` | integer | Primary course ID (first course this lesson belongs to) |
| `courses` | array | Array of all course IDs this lesson belongs to |

## Search Features

✅ **Case-Insensitive**: Works with "Riverside", "riverside", or "RIVERSIDE"  
✅ **Partial Matching**: Finds "riverside" in "Riverside Park Course"  
✅ **Title & Content**: Searches in both titles and descriptions  
✅ **Word Matching**: Finds partial words (e.g., "river" matches "Riverside")  
✅ **Category Filtering**: Strict filtering by MasterStudy course categories  
✅ **Category-Only Mode**: Get all items from a category without search term

## Category Filtering Details

The `category` parameter filters by MasterStudy course categories (taxonomy: `stm_lms_course_taxonomy`). This is useful when you've organized courses by categories such as countries, topics, or any other taxonomy.

### How Category Filtering Works

1. **Courses**: Only returns courses that belong to the specified category(ies)
2. **Lessons**: Only returns lessons that belong to courses in the specified category(ies)
3. **Strict Filtering**: The filter is strict - only items that actually belong to the category are returned
4. **Multiple Categories**: Use comma-separated values to filter by multiple categories (e.g., `category=168,169` or `category=Vietnam,Thailand`)

### Category Parameter Formats

The `category` parameter supports three formats:

1. **Category ID** (numeric): `category=168`
2. **Category Name** (case-insensitive): `category=Vietnam`, `category=vietnam`, or `category=VIETNAM` - all work!
3. **Category Slug** (URL-friendly, case-sensitive): `category=vietnam`

You can mix formats in comma-separated lists:
- `category=168,Vietnam,thailand`
- `category=Vietnam,169`
- `category=vietnam,Thailand` (name is case-insensitive, slug is case-sensitive)

**Note:** The API will try to match in this order:
1. If the value is numeric, it's treated as an ID
2. If not numeric, it first tries to match as a slug (case-sensitive)
3. If no slug match, it tries to match as a name (case-insensitive)

**Case-Insensitive Names:** Category names are matched case-insensitively, so `Vietnam`, `vietnam`, and `VIETNAM` all work the same way.

### Finding Category Information

To find category IDs, names, or slugs:
1. Go to WordPress Admin → Courses → Categories
2. Hover over a category name - the URL shows the ID (e.g., `tag_ID=168`)
3. The category name is displayed in the list
4. The slug is the URL-friendly version (usually lowercase, with hyphens)
5. Or use the WordPress REST API: `/wp-json/wp/v2/stm_lms_course_taxonomy`

## Use Cases

### 1. Get All Courses from Vietnam (using ID)
```
GET /wp-json/masterstudy-lms/v2/courses?category=168
```

### 1b. Get All Courses from Vietnam (using name)
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam
```

### 2. Search for "Marketing" Courses in Vietnam (using name)
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam&s=marketing
```

### 3. Get All Courses and Lessons from Vietnam
```
GET /wp-json/masterstudy-lms/v2/search?category=Vietnam
```

### 4. Search Courses and Lessons in Vietnam
```
GET /wp-json/masterstudy-lms/v2/search?category=Vietnam&s=marketing
```

### 5. Get Top-Rated Courses in Vietnam
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam&sort=rating&per_page=10
```

### 6. Paginated Search Results
```
GET /wp-json/masterstudy-lms/v2/search?category=Vietnam&s=marketing&per_page=20&page=2
```

### 7. Multiple Categories (mix of IDs and names)
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam,Thailand,169
```

## Security

- Only the `/courses` endpoint is made public (when no nonce is provided)
- All other MasterStudy API endpoints still require authentication
- The plugin uses WordPress REST API standards
- No security vulnerabilities introduced
- Category filtering is strict and validated

## Requirements

- WordPress 5.0 or higher
- MasterStudy LMS plugin (free or pro version)
- PHP 7.4 or higher

## Additional Features

### Lesson Search Support

The plugin also enables WordPress REST API access for lessons (`stm-lessons` post type):

```
GET /wp-json/wp/v2/stm-lessons?search=riverside
```

**Note:** After activating the plugin, you may need to flush rewrite rules:
1. Go to Settings → Permalinks
2. Click "Save Changes" (no need to change anything)

## Changelog

### 1.0.0
- Initial release
- Makes courses endpoint publicly accessible
- Bypasses nonce requirement for courses endpoint only
- Enables REST API for stm-lessons post type
- Added category filtering support
- Added category-only filtering (no search term required)
- Enhanced search endpoint with category support

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## Support

- **Issues**: [GitHub Issues](https://github.com/aldeentorres/masterstudy-search-api/issues)
- **Documentation**: See this README
- **MasterStudy LMS**: [Official Documentation](https://docs.stylemixthemes.com/masterstudy-lms/)

## Credits

- Built for MasterStudy LMS
- Uses WordPress REST API standards
- Compatible with MasterStudy LMS Free and Pro versions

## License

This plugin is licensed under the GPL v2 or later.

```
Copyright (C) 2025 artor

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

## Disclaimer

This plugin is not affiliated with, endorsed by, or sponsored by Stylemix Themes or MasterStudy LMS. It is an independent extension created by the community.

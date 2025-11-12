# MasterStudy Search API

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net/)

A WordPress plugin that provides enhanced search API capabilities for MasterStudy LMS. Makes courses and lessons searchable via REST API with powerful search features. Includes case-insensitive partial matching for both courses and lessons.

## Description

This plugin extends the MasterStudy LMS API with enhanced search capabilities. It provides:
- Accessible courses API endpoint (`/wp-json/masterstudy-lms/v2/courses`)
- Combined search endpoint for courses and lessons (`/wp-json/masterstudy-lms/v2/search`)
- Case-insensitive partial word matching
- Lesson search with REST API support
- Automatic course-lesson relationship detection

Perfect for external applications, mobile apps, and third-party integrations.

## Features

- ✅ Enhanced courses API endpoint access
- ✅ Simplified authentication for courses endpoint
- ✅ Maintains security for other endpoints
- ✅ Survives MasterStudy plugin updates
- ✅ Lightweight and efficient

## Installation

1. Upload the `masterstudy-search-api` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The enhanced search API is now available!

## Usage

### Basic Search
```
GET /wp-json/masterstudy-lms/v2/courses?s=javascript
```

### Search with Sort
```
GET /wp-json/masterstudy-lms/v2/courses?s=javascript&sort=rating
```

### Available Sort Options
- `date_low` - Sort by date (oldest first)
- `price_high` - Sort by price (highest first)
- `price_low` - Sort by price (lowest first)
- `rating` - Sort by rating (highest first)
- `popular` - Sort by popularity/views (highest first)

### Additional Parameters
- `per_page` - Number of results per page (default: 10)
- `page` - Page number (default: 1)
- `category` - Category IDs (comma-separated)
- `author` - Instructor/author ID
- `level` - Course level filter
- `status` - Course status filter

### Example URLs

**Search courses:**
```
/wp-json/masterstudy-lms/v2/courses?s=javascript
```

**Search with sort:**
```
/wp-json/masterstudy-lms/v2/courses?s=javascript&sort=rating&per_page=20
```

**Sort by price (low to high):**
```
/wp-json/masterstudy-lms/v2/courses?sort=price_low
```

## Response Format

```json
{
  "courses": [
    {
      "id": 123,
      "title": "Course Title",
      "excerpt": "Course description...",
      "price": 99,
      "rating": 4.5
    }
  ],
  "total": 50,
  "pages": 5,
  "courses_page": "/courses/"
}
```

## Security

- Only the `/courses` endpoint is made public
- All other MasterStudy API endpoints still require authentication
- The plugin uses WordPress REST API standards
- No security vulnerabilities introduced

## Requirements

- WordPress 5.0 or higher
- MasterStudy LMS plugin (free or pro version)
- PHP 7.4 or higher

## Additional Features

### Combined Courses and Lessons Search

The plugin now includes lessons in search results! When you search using the courses endpoint, it will also return matching lessons.

**Courses Endpoint (now includes lessons):**
```
GET /wp-json/masterstudy-lms/v2/courses?s=riverside
```

**Response includes both courses and lessons:**
```json
{
  "courses": [...],
  "lessons": [
    {
      "id": 123,
      "title": "Lesson Title",
      "excerpt": "Lesson description...",
      "link": "https://yoursite.com/lesson/...",
      "type": "lesson",
      "course_id": 456,
      "courses": [456]
    }
  ],
  "total": 15,
  "pages": 2
}
```

**Dedicated Combined Search Endpoint:**
```
GET /wp-json/masterstudy-lms/v2/search?s=riverside&per_page=20
```

### Search Features

✅ **Case-Insensitive**: Works with "Riverside", "riverside", or "RIVERSIDE"  
✅ **Partial Matching**: Finds "riverside" in "Riverside Park Course"  
✅ **Title & Content**: Searches in both titles and descriptions  
✅ **Word Matching**: Finds partial words (e.g., "river" matches "Riverside")

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


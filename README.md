# MasterStudy Search API

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net/)

Enhanced search API for MasterStudy LMS. Search courses and lessons with powerful filtering options.

## What It Does

This plugin adds a simple search API to your MasterStudy LMS that:
- ✅ Searches courses and lessons by keyword
- ✅ Shows matching lessons inside their parent courses
- ✅ Filters by categories (countries, topics, etc.)
- ✅ Sorts results by price, rating, popularity
- ✅ Works without authentication for public access

Perfect for mobile apps, external integrations, or custom frontends.

## Quick Start

**Search for courses and lessons:**
```
GET /wp-json/masterstudy-lms/v2/courses?s=marketing
```

**Filter by category:**
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam
```

**Combine search and category:**
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam&s=marketing
```

## Installation

1. Upload the `masterstudy-search-api` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress
3. Start using the API!

## API Endpoints

### Courses Endpoint
**URL:** `/wp-json/masterstudy-lms/v2/courses`

Search courses and their lessons. When you search, matching lessons are shown **nested inside each course** in a `lessons` array.

### Search Endpoint
**URL:** `/wp-json/masterstudy-lms/v2/search`

Searches both courses and lessons, but returns them as **separate arrays**. Lessons include course information (course_id, course_title, course_link).

### Agent Progress Endpoint
**URL:** `/wp-json/masterstudy-lms/v2/agent-progress`

Get progress for a specific user (courses and lessons they've completed or are working on).

## Parameters

### Search Parameters

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `s` | string | Search keyword (searches titles and content) | `s=marketing` |
| `category` | string | Filter by category (ID, name, or slug) | `category=Vietnam` or `category=168` |
| `per_page` | integer | Results per page (default: 10) | `per_page=20` |
| `page` | integer | Page number (default: 1) | `page=2` |
| `sort` | string | Sort order (see below) | `sort=rating` |
| `author` | integer | Filter by instructor ID | `author=5` |

### Sort Options

| Value | Description | Alias |
|-------|-------------|-------|
| `newest` | Newest first (default) | `date_high` |
| `oldest` | Oldest first | `date_low` |
| `price_high` | Most expensive first | - |
| `price_low` | Cheapest first | - |
| `rating` | Highest rated first | - |
| `popular` | Most popular first | - |

**Note:** You can use either the user-friendly names (`newest`, `oldest`) or the MasterStudy defaults (`date_high`, `date_low`). Both work the same way.

### Agent Progress Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `agent_id` | string | Yes | User ID, email, or username |
| `status` | string | No | `all`, `completed`, or `ongoing` |
| `include_lessons` | boolean | No | Include lesson progress (default: `true`) |

## Examples

### Basic Search

**Search for "marketing":**
```
GET /wp-json/masterstudy-lms/v2/courses?s=marketing
```

**Search for "AML":**
```
GET /wp-json/masterstudy-lms/v2/courses?s=AML
```

### Category Filtering

**Get all courses from Vietnam:**
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam
```

**Get courses from multiple categories:**
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam,Thailand
```

**Use category ID instead of name:**
```
GET /wp-json/masterstudy-lms/v2/courses?category=168
```

### Combined Search

**Search "marketing" in Vietnam:**
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam&s=marketing
```

**Sort by rating:**
```
GET /wp-json/masterstudy-lms/v2/courses?category=Vietnam&s=marketing&sort=rating
```

**Sort by newest first:**
```
GET /wp-json/masterstudy-lms/v2/courses?s=marketing&sort=newest
```
or
```
GET /wp-json/masterstudy-lms/v2/courses?s=marketing&sort=date_high
```

**Sort by oldest first:**
```
GET /wp-json/masterstudy-lms/v2/courses?s=marketing&sort=oldest
```
or
```
GET /wp-json/masterstudy-lms/v2/courses?s=marketing&sort=date_low
```

**Pagination:**
```
GET /wp-json/masterstudy-lms/v2/courses?s=marketing&per_page=20&page=2
```

### Agent Progress

**Get all progress for a user:**
```
GET /wp-json/masterstudy-lms/v2/agent-progress?agent_id=user@example.com
```

**Get only completed courses:**
```
GET /wp-json/masterstudy-lms/v2/agent-progress?agent_id=123&status=completed
```

## Response Format

### Courses Endpoint Response

When you search the `/courses` endpoint, matching lessons are **nested inside each course**:

```json
{
  "courses": [
    {
      "id": 123,
      "title": "Marketing Fundamentals",
      "excerpt": "Learn the basics of marketing...",
      "link": "https://yoursite.com/course/marketing-fundamentals/",
      "type": "course",
      "date": "2024-01-15 10:00:00",
      "author": 5,
      "price": 99,
      "rating": 4.5,
      "students": 150,
      "lessons": [
        {
          "id": 456,
          "title": "Introduction to Marketing",
          "excerpt": "This lesson covers...",
          "link": "https://yoursite.com/courses/marketing-fundamentals/456/",
          "type": "lesson",
          "date": "2024-01-15 10:00:00",
          "author": 5
        }
      ]
    }
  ],
  "total": 1,
  "pages": 1
}
```

**Key Points:**
- Each course has a `lessons` array
- Matching lessons appear nested inside their parent course
- The `total` shows the number of courses

### Search Endpoint Response

When you use the `/search` endpoint, courses and lessons are returned as **separate arrays**:

```json
{
  "courses": [
    {
      "id": 123,
      "title": "Marketing Fundamentals",
      "excerpt": "Learn the basics of marketing...",
      "link": "https://yoursite.com/course/marketing-fundamentals/",
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
      "title": "Introduction to Marketing",
      "excerpt": "This lesson covers...",
      "link": "https://yoursite.com/courses/marketing-fundamentals/456/",
      "type": "lesson",
      "date": "2024-01-15 10:00:00",
      "author": 5,
      "course_id": 123,
      "course_title": "Marketing Fundamentals",
      "course_link": "https://yoursite.com/course/marketing-fundamentals/"
    }
  ],
  "total": 2,
  "pages": 1
}
```

**Key Points:**
- Courses and lessons are in separate arrays
- Each lesson includes `course_id`, `course_title`, and `course_link`
- The `total` shows the combined count of courses + lessons

### Agent Progress Response

```json
{
  "agent_id": 123,
  "status_filter": "all",
  "course_threshold": 70,
  "summary": {
    "courses": {
      "completed": 3,
      "ongoing": 2
    },
    "lessons": {
      "completed": 15,
      "ongoing": 4
    }
  },
  "courses": {
    "completed": [
      {
        "id": 321,
        "title": "Advanced Sales",
        "progress_percent": 95,
        "status": "completed"
      }
    ],
    "ongoing": [
      {
        "id": 654,
        "title": "Negotiation Fundamentals",
        "progress_percent": 45,
        "status": "ongoing"
      }
    ]
  },
  "lessons": {
    "completed": [
      {
        "id": 555,
        "title": "Lesson Title",
        "course_id": 321,
        "progress": 100,
        "status": "completed"
      }
    ],
    "ongoing": []
  }
}
```

## Response Fields

### Course Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Course ID |
| `title` | string | Course title |
| `excerpt` | string | Short description (20 words) |
| `link` | string | Course URL |
| `type` | string | Always `"course"` |
| `date` | string | Publication date |
| `author` | integer | Instructor user ID |
| `price` | float | Course price |
| `rating` | float | Course rating |
| `students` | integer | Number of students |
| `lessons` | array | Array of matching lessons (courses endpoint only) |

### Lesson Object

**In Courses Endpoint** (nested inside courses):

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Lesson ID |
| `title` | string | Lesson title |
| `excerpt` | string | Short description (20 words) |
| `link` | string | Lesson URL |
| `type` | string | Always `"lesson"` |
| `date` | string | Publication date |
| `author` | integer | Instructor user ID |

**In Search Endpoint** (separate array):

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Lesson ID |
| `title` | string | Lesson title |
| `excerpt` | string | Short description (20 words) |
| `link` | string | Lesson URL |
| `type` | string | Always `"lesson"` |
| `date` | string | Publication date |
| `author` | integer | Instructor user ID |
| `course_id` | integer | Primary course ID this lesson belongs to |
| `course_title` | string | Course title |
| `course_link` | string | Course URL |
| `courses` | array | Array of all course IDs this lesson belongs to |

## How Search Works

1. **Course Matching**: Returns courses whose title or content matches the search term
2. **Lesson Matching**: Finds lessons that match the search term
3. **Grouping**: Matching lessons are grouped under their parent courses
4. **Result**: You get courses with matching lessons nested inside

**Example:**
- Search: `s=AML`
- Finds 2 lessons with "AML" in the title
- Both lessons belong to Course A
- Response shows Course A with those 2 lessons in its `lessons` array

## Category Filtering

Filter results by MasterStudy course categories (like countries, topics, etc.).

### Category Formats

You can use:
- **Category ID**: `category=168`
- **Category Name**: `category=Vietnam` (case-insensitive)
- **Category Slug**: `category=vietnam` (case-sensitive)

**Multiple categories:**
```
category=Vietnam,Thailand,168
```

### How It Works

- **Courses**: Only shows courses in the specified category
- **Lessons**: Only shows lessons from courses in that category
- **Strict**: Only items actually in the category are returned

## Requirements

- WordPress 5.0+
- MasterStudy LMS plugin
- PHP 7.4+

## Security

- Only the `/courses` endpoint is public (no authentication needed)
- Other endpoints still require authentication
- Uses WordPress REST API standards
- Category filtering is validated

## License

GPL v2 or later

---

**Note:** This plugin is not affiliated with Stylemix Themes or MasterStudy LMS. It's an independent community extension.

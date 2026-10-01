# Corrai Server

PHP backend for the corrai application.

## Setup

### PHP Requirements

- PHP >= 7.4 (Docker uses PHP 8.3)
- Composer for dependency management
- SeaweedFS (S3-compatible object store) for schools, users, assessments, and files. Logs go to `/var/log/corrai`.

### Installing dependencies

```bash
cd server/php
composer install
```

### Class loading

The PHP backend uses PSR-4 autoloading:

- Classes in `inc/Corrai/` namespace are autoloaded from `inc/Corrai/`
- Composer dependencies are autoloaded from `vendor/`
- See `server/php/inc/autoload.php` for autoloader implementation

### API Endpoints (quick reference)

REST API endpoints are located in `php/api/`:

| Endpoint | Description |
|----------|-------------|
| `post_user.php` | Create an Independent (IND) teacher profile |
| `get_assessment.php` | Retrieve assessment information |
| `get_assessments.php` | List assessments for the authenticated user |
| `get_download.php` | Download file |
| `get_health.php` | Health check |
| `post_assessment.php` | Create a new assessment |
| `post_file.php` | Upload file |
| `put_assessment.php` | Update an existing assessment |
| `delete_assessment.php` | Delete an assessment |
| `delete_file.php` | Delete file |
| `options.php` | CORS preflight handler |

## Windows full PHP+DB dev environment setup

### Software prerequisites

Install MAMP / WAMP / XAMP with:

- MySQL 5 or 8
- MariaDB
- PHP >= 7.4
- XDebug

Install an SQL client with SSL tunneling for MySQL and MariaDB:

- HeidiSQL
- DBeaver
- MySQL Workbench

### Database setup

Connect to the database:

```ps
mysql.exe -u root --password=root --port=<port>
```

Create the database:

```sql
CREATE DATABASE `corrai` CHARACTER SET utf8 COLLATE utf8_general_ci;
USE corrai;
source c:\path\to\the\script\.sql
```

### PHP IDE setup

Make sure Xdebug is installed:

```bash
php -m
```

Configure Xdebug in php.ini:

```ini
[xdebug]
zend_extension="c:/wamp64/bin/php/php7.4.0/zend_ext/php_xdebug-2.8.0-7.4-vc15-x86_64.dll"
xdebug.remote_enable = on
xdebug.remote_autostart=1
xdebug.show_local_vars=0
xdebug.remote_port=9009
```

For Visual Studio Code:

1. Install the PHP Debug extension
2. Configure the PHP executable path in settings.json:

```json
"php.validate.executablePath": "C:\\wamp64\\bin\\php\\php7.4.0\\php.exe"
```

3. Install PHP Intelephense for better PHP support

## Development procedures

### Database export

```bash
set -a
source .env
mysqldump -u $DB_USER --password=$DB_PASSWORD $DB_BASE | gzip -c > thedump.sql
```

### Database transfer via SSH

```bash
time mysql -u [dest_user] --password=[dest_passwd] [dest_db] < <(ssh -C [src_host] "mysqldump -u [src_user] --password=[src_passwd] [src_db]")
```

### Database copy on same server

```bash
sudo time mysqldump -u root db_name | mysql -u root new_db_name
```

### Environment variables

To create a new environment, copy the template environment file and rename it to `.env`. Modify the variable values to adapt to your situation.

In PHP code, check if you are in production mode:

```php
isset($_ENV["APP_ENV"]) && $_ENV["APP_ENV"] == "prod"
```

Debugging and testing back-doors should be disabled in production mode. Production support features like Google Analytics or reCAPTCHA should be disabled in non-production environments.

---

# Corrai API Documentation

## Overview

The corrai API provides endpoints for managing users, assessments, and files. Assessment and file endpoints require authentication via the `Authorization` header with a Bearer token (7-character user id).

## Authentication

Most API endpoints require authentication using the `Authorization` header:

```
Authorization: Bearer <user_id>
```

The token is the 7-character alphanumeric user id returned by `POST /api/user` (and stored under `schools/IND/teachers/{userId}/` in S3). It identifies the request author and must be provided for assessment and file operations.

`POST /api/user` is unauthenticated and creates a new Independent-school teacher.

## Endpoints

### 0. Create User (Independent profile)

**POST** `/api/user`

Creates a new teacher under the fixed Independent (`IND`) school. Writes `schools/IND/teachers/{userId}/attributes.json` and registers `_id/{userId}`.

**Request Body (optional):**
```json
{
  "name": "Teacher display name"
}
```

**Response:**
- **201 Created**: User created successfully
  - Returns: `{"user": {"id": "...", "school_id": "IND", "email": "...", "name": "...", "role": "teacher", "created_at": "..."}}`
- **400 Bad Request**: Invalid JSON

**Example:**
```json
POST /api/user
Content-Type: application/json

{
  "name": "Alice"
}
```

---

### 1. Create Assessment

**POST** `/api/assessment`

Creates a new assessment under the authenticated user's S3 prefix.

**Request Body:**
- JSON object containing assessment data (`name`, `subject`, `date`)
- The owning user is taken from the Authorization header

**Response:**
- **201 Created**: Assessment created successfully
  - Returns: `{"hash": "<assessment_id>"}`
- **400 Bad Request**: Invalid request
  - Missing request body
  - Invalid JSON format
- **401 Unauthorized**: Missing/invalid Bearer token or unknown user

**Example:**
```json
POST /api/assessment
Authorization: Bearer <user_id>
Content-Type: application/json

{
  "name": "Assessment Title",
  "subject": "Mathematics",
  "date": "2026-06-15"
}
```

---

### 2. Get Assessment

**GET** `/api/get_assessment.php?hash=<assessment_id>`

Retrieves an assessment by its hash/ID.

**Query Parameters:**
- `hash` (required): The assessment ID/hash

**Response:**
- **200 OK**: Assessment retrieved successfully
  - Returns: `{"assessment": {...}, "files": [...]}`
- **400 Bad Request**: Invalid request
  - Missing hash parameter
  - Assessment does not exist

**Example:**
```
GET /api/get_assessment.php?hash=abc123...
Authorization: Bearer <user_id>
```

---

### 3. Update Assessment

**PUT** `/api/put_assessment.php?hash=<assessment_id>`

Updates an existing assessment. Only the assessment author can update their assessment.

**Query Parameters:**
- `hash` (required): The assessment ID/hash

**Request Body:**
- JSON object containing updated assessment data

**Response:**
- **200 OK**: Assessment updated successfully
  - Returns: `{"hash": "<assessment_id>", "message": "Assessment updated successfully"}`
- **400 Bad Request**: Invalid request
  - Missing hash parameter
  - Missing request body
  - Invalid JSON format
  - Assessment does not exist
- **403 Forbidden**: Not authorized
  - Request author does not match the assessment author

**Example:**
```json
PUT /api/put_assessment.php?hash=abc123...
Authorization: Bearer <user_id>
Content-Type: application/json

{
  "title": "Updated Title",
  ...
}
```

---

### 4. Delete Assessment

**DELETE** `/api/delete_assessment.php?hash=<assessment_id>`

Deletes an assessment. Only the assessment author can delete their assessment, and the assessment must not be locked or claimed.

**Query Parameters:**
- `hash` (required): The assessment ID/hash

**Response:**
- **200 OK**: Assessment deleted successfully
  - Returns: `{"hash": "<assessment_id>", "message": "File deleted successfully"}`
- **400 Bad Request**: Invalid request
  - Missing hash parameter
  - Assessment does not exist
  - Error deleting file
- **436 Conflict**: Cannot delete assessment
  - Assessment is locked or claimed
- **437 Forbidden**: Cannot delete assessment
  - Request author is not the assessment author

**Example:**
```
DELETE /api/delete_assessment.php?hash=abc123...
Authorization: Bearer <user_id>
```

---

### 5. Claim Assessment

**POST** `/api/post_claim.php?hash=<assessment_id>`

Claims an assessment by providing the correct secret. The assessment must be locked by the request author and have tries remaining.

**Query Parameters:**
- `hash` (required): The assessment ID/hash

**Request Body:**
- JSON object with `claim_secret` field

**Response:**
- **200 OK**: Assessment claimed successfully
  - Returns: `{"hash": "<assessment_id>", "message": "Assessment claimed successfully"}`
- **400 Bad Request**: Invalid request
  - Missing hash parameter
  - Missing claim_secret in request body
- **432 Conflict**: Assessment has already been claimed
- **433 Forbidden**: Request author does not match the locker
- **434 Forbidden**: No tries remaining for this assessment
- **435 Unprocessable Entity**: Invalid claim secret
  - Returns: `{"remaining_tries": <number>, "message": "Invalid claim secret"}`
  - The assessment's `tries_remaining` is decremented on invalid secret

**Example:**
```json
POST /api/post_claim.php?hash=abc123...
Authorization: Bearer <user_id>
Content-Type: application/json

{
  "claim_secret": "123456"
}
```

---

### 6. Upload File

**POST** `/api/post_file.php?id=<assessment_id>`

Uploads a file to an assessment. Uses multipart/form-data encoding.

**Query Parameters:**
- `id` (required): The assessment ID/hash

**Request Body:**
- `multipart/form-data` with a `file` field

**Response:**
- **201 Created**: File uploaded successfully
  - Returns: `{"filename": "<filename>", "path": "<assessment_id>_files/<filename>", "files": [...]}`
- **400 Bad Request**: Invalid request
  - Missing id parameter
  - Assessment does not exist
  - No file uploaded
  - File too large
  - File upload was incomplete
  - No file was uploaded
  - Missing temporary folder
  - Failed to write file to disk
  - File upload stopped by extension
  - Invalid file name
  - Failed to save uploaded file

**Example:**
```
POST /api/post_file.php?id=abc123...
Authorization: Bearer <user_id>
Content-Type: multipart/form-data

file: <file_data>
```

---

### 7. Delete File

**DELETE** `/api/file?id=<assessment_id>&file=<file_id>`

Deletes a file from an assessment by file hash.

**Query Parameters:**
- `id` (required): The assessment ID/hash
- `file` (required): The file hash to delete

**Response:**
- **200 OK**: File deleted successfully
  - Returns: `{"file": "<file_id>", "id": "<assessment_id>", "message": "File deleted successfully", "files": [...], "students": [...]}`
- **400 Bad Request**: Invalid request
  - Missing id parameter
  - Missing file parameter
  - Assessment does not exist
  - File does not exist
  - Error deleting file

**Example:**
```
DELETE /api/file?id=abc1234&file=def5678
Authorization: Bearer <user_id>
```

---

### 8. Lock Assessment

**PUT** `/api/put_lock.php?hash=<assessment_id>`

Locks an assessment. The request author becomes the locker.

**Query Parameters:**
- `hash` (required): The assessment ID/hash

**Response:**
- **200 OK**: Assessment locked successfully
  - Returns: Standard output format
- **400 Bad Request**: Invalid request
  - Missing hash parameter
  - Assessment does not exist

**Example:**
```
PUT /api/put_lock.php?hash=abc123...
Authorization: Bearer <user_id>
```

---

### 9. Unlock Assessment

**DELETE** `/api/delete_lock.php?hash=<assessment_id>`

Unlocks an assessment. Only the locker can unlock the assessment.

**Query Parameters:**
- `hash` (required): The assessment ID/hash

**Response:**
- **200 OK**: Assessment unlocked successfully
  - Returns: `{"hash": "<assessment_id>", "message": "Assessment unlocked successfully"}`
- **400 Bad Request**: Invalid request
  - Missing hash parameter
  - Assessment does not exist
  - Assessment is not locked
- **433 Forbidden**: Request author does not match the locker

**Example:**
```
DELETE /api/delete_lock.php?hash=abc123...
Authorization: Bearer <user_id>
```

---

### 10. CORS Options

**OPTIONS** `/api/options.php`

Handles CORS preflight requests and sets appropriate CORS headers.

**Response:**
- **200 OK**: CORS headers set

---

## Error Codes

### Standard HTTP Status Codes

| Code | Status | Description |
|------|--------|-------------|
| 200 | OK | Request successful |
| 201 | Created | Resource created successfully |
| 400 | Bad Request | General client error (missing parameters, invalid JSON, etc.) |
| 401 | Unauthorized | Authentication required or invalid (missing/invalid authorization header) |
| 403 | Forbidden | Authorization failed (valid authentication but insufficient permissions) |

### Custom Error Codes

| Code | Status | Description |
|------|--------|-------------|
| 432 | Conflict | Assessment has already been claimed |
| 433 | Forbidden | Request author does not match the locker |
| 434 | Forbidden | No tries remaining for this assessment |
| 435 | Unprocessable Entity | Invalid claim secret (tries_remaining is decremented) |
| 436 | Conflict | Cannot delete assessment: assessment is locked or claimed |
| 437 | Forbidden | Cannot delete assessment: request author is not the assessment author |

### Error Response Format

All errors are returned in a consistent JSON format:

```json
{
  "messages": [
    {
      "level": "error",
      "message": "Error description"
    }
  ],
  "output": {
    // Additional output data (if any)
  }
}
```

### Common Error Messages

- **"No hash parameter"**: Missing required `hash` query parameter
- **"No id parameter provided"**: Missing required `id` query parameter
- **"No filename parameter provided"**: Missing required `filename` query parameter
- **"No body in POST request"**: Missing request body for POST request
- **"No body in PUT request"**: Missing request body for PUT request
- **"Invalid JSON in POST request body"**: Malformed JSON in request body
- **"Invalid JSON in PUT request body"**: Malformed JSON in request body
- **"No authorization header"**: Missing Authorization header (401)
- **"Invalid authorization header with no Bearer prefix"**: Authorization header missing "Bearer " prefix (401)
- **"Invalid authorization header with no token"**: Authorization header missing token after "Bearer " (401)
- **"Not authorized"**: Request author does not have permission for the operation (403)
- **"Assessment with hash <hash> does not exist"**: Specified assessment ID not found
- **"Assessment with id <id> does not exist"**: Specified assessment ID not found
- **"Assessment is not locked"**: Attempted to unlock an assessment that is not locked
- **"No claim_secret in POST request body"**: Missing claim_secret field in claim request
- **"Invalid file name"**: File name contains invalid characters (slashes/backslashes)
- **"File too large"**: Uploaded file exceeds size limits
- **"File upload was incomplete"**: File upload was interrupted
- **"No file was uploaded"**: No file provided in upload request
- **"Missing temporary folder"**: Server configuration issue
- **"Failed to write file to disk"**: Disk write error
- **"File upload stopped by extension"**: Server extension blocked upload
- **"Failed to save uploaded file"**: Error moving uploaded file to destination
- **"File '<filename>' does not exist for assessment <id>"**: Specified file not found for assessment
- **"Error deleting file with hash <hash>"**: File system error during deletion
- **"Error deleting file '<filename>'"**: File system error during file deletion

---

## Response Format

### Success Response

Successful responses typically include an `output` object:

```json
{
  "output": {
    "hash": "<assessment_id>",
    "message": "Operation successful",
    // Additional fields as needed
  }
}
```

### Error Response

Error responses include a `messages` array:

```json
{
  "messages": [
    {
      "level": "error",
      "message": "Error description"
    }
  ],
  "output": {
    // May contain additional context
  }
}
```

---

## Notes

- Assessment and file endpoints require the `Authorization: Bearer <user_id>` header
- The user id is the 7-character hash returned by `POST /api/user`
- Assessment authors can only modify or delete their own assessments
- Only the locker can unlock an assessment or claim it
- Invalid claim attempts decrement the `tries_remaining` counter
- Assessments cannot be deleted if they are locked or claimed
- File operations are scoped to individual assessments
- CORS is handled automatically for same-domain and localhost requests

# Corrai

Corrai is an application designed to automate a significant portion of student exam paper grading.

# The Model

School
    Teacher
        Exam
            Subject
            Answer Key
            Grading Guidelines
            Unassigned
                File1
                File2
                File3
            Student Paper
                File1
                File2
                File3
                Grades

# Storage System

The system is based on MinIO (S3-compatible object storage).

Every school, user (teacher), and exam is identified by a short 7-character alphanumeric hash. These hashes form a tree in the bucket:

```
{schoolId}/school.csv
{schoolId}/{userId}/user.csv
{schoolId}/{userId}/{examId}/exam.csv
{schoolId}/{userId}/{examId}/unassigned/{filename}
{schoolId}/{userId}/{examId}/subject/   (answer key, grading guidelines)
_id/{hash}                              (pointer to the node prefix)
```

Structured entity data is stored as a single-record CSV file at the root of each node. Binary exam files live under `unassigned/` (and later under student-paper folders). If volumes grow too high, the CSV layer will be replaced by SQLite tables while keeping the same S3 tree for binaries.

# Use Cases

## School and Teacher Management

Listing, creating, and adding schools. This is restricted to administrators only.

Each school has an admin user/teacher who can add and modify teachers for that school.

## Session Management

The user is a teacher identified by an email address. Authentication uses a standard email and password login.

## Exam Creation

When logged in as a teacher, users can add, edit, or delete exams.

## Exam Management

### Adding Files

The UI allows users to add files, which are then stored in the exam's "Unassigned" section.

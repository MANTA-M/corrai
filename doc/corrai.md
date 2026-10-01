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

The system is based on SeaweedFS (S3-compatible object storage).

Every school, teacher, exam, file, and student is identified by a short alphanumeric hash (schools may use a fixed id such as `IND`). These hashes form a tree in the bucket:

```
schools/<schoolId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/exams/<examId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/content
schools/<schoolId>/teachers/<teacherId>/exams/<examId>/files/<fileId>/events/<eventId>.json
schools/<schoolId>/teachers/<teacherId>/exams/<examId>/students/<studentId>/attributes.json
_id/{hash}                              (pointer to the node prefix)
```

Each node owns its own `attributes.json`. Binary file bytes live under `content`. Concurrent updates are isolated to individual file and student documents (with If-Match) and append-only event objects.

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

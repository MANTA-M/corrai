# Corrai

Corrai is an application designed to automate a significant portion of student assessment paper grading.

# The Model

School
    Teacher
        Assessment
            Subject
            Answer Key
            Grading Guidelines
            Unclassified
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

Every school, teacher, assessment, file, and student is identified by a short alphanumeric hash (schools may use a fixed id such as `IND`). These hashes form a tree in the bucket:

```
schools/<schoolId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/subject/<fileId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/subject/<fileId>/content
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/students/<studentId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/students/<studentId>/<fileId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/students/<studentId>/<fileId>/content
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/students/<studentId>/<fileId>/events/<eventId>.json
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/unclassified/<fileId>/attributes.json
schools/<schoolId>/teachers/<teacherId>/assessments/<assessmentId>/unclassified/<fileId>/content
_id/{hash}                              (pointer to the node prefix)
```

Each node owns its own `attributes.json`. Binary file bytes live under `content`. Files linked to the subject (`subject`, `solution`, `instructions`) live under `subject/`. Student copies live under `students/<studentId>/<fileId>/`, with append-only event objects. Files not yet classified live under `unclassified/`. Assigning a type or a student moves the file to the matching prefix. Concurrent updates are isolated to individual file and student documents (with If-Match).

# Use Cases

## School and Teacher Management

Listing, creating, and adding schools. This is restricted to administrators only.

Each school has an admin user/teacher who can add and modify teachers for that school.

## Session Management

The user is a teacher identified by an email address. Authentication uses a standard email and password login.

## Assessment Creation

When logged in as a teacher, users can add, edit, or delete assessments.

## Assessment Management

### Adding Files

The UI allows users to add files, which are then stored in the assessment's "Unassigned" section.

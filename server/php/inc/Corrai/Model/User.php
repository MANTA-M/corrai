<?php

namespace Corrai\Model;

use Exception;
use Corrai\Utils\HashId;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;

class User
{
    public const ROLE_SCHOOL_ADMIN = 'school_admin';
    public const ROLE_TEACHER = 'teacher';

    public ?string $id = null;
    public string $school_id = '';
    public string $email = '';
    public string $name = '';
    public string $role = self::ROLE_TEACHER;
    public string $password_hash = '';
    public string $created_at = '';

    public static function from_array(array $data): User
    {
        $user = new User();
        $user->id = $data['id'] ?? null;
        $user->school_id = $data['school_id'] ?? '';
        $user->email = $data['email'] ?? '';
        $user->name = $data['name'] ?? '';
        $user->role = $data['role'] ?? self::ROLE_TEACHER;
        $user->password_hash = $data['password_hash'] ?? '';
        $user->created_at = $data['created_at'] ?? '';
        return $user;
    }

    public function validate(): void
    {
        if (trim($this->school_id) === '') {
            throw new WSException('User school_id is required', 400);
        }
        if (trim($this->email) === '') {
            throw new WSException('User email is required', 400);
        }
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new WSException('User email is invalid', 400);
        }
        if (trim($this->name) === '') {
            throw new WSException('User name is required', 400);
        }
        if (!in_array($this->role, [self::ROLE_SCHOOL_ADMIN, self::ROLE_TEACHER], true)) {
            throw new WSException('User role must be school_admin or teacher', 400);
        }
        if ($this->password_hash === '') {
            throw new WSException('User password is required', 400);
        }
    }

    public function setPassword(string $password): void
    {
        if ($password === '') {
            throw new WSException('Password cannot be empty', 400);
        }
        $this->password_hash = password_hash($password, PASSWORD_DEFAULT);
    }

    public function verifyPassword(string $password): bool
    {
        if ($this->password_hash === '') {
            return false;
        }
        return password_verify($password, $this->password_hash);
    }

    /**
     * Load a User from its hash via the _id pointer.
     */
    public static function from_hash(string $hash): User
    {
        if (!HashId::isValid($hash)) {
            throw new WSException("Invalid user id", 401);
        }

        $store = ObjectStore::getInstance();
        try {
            $prefix = $store->resolveIdPointer($hash);
        } catch (\Exception $e) {
            throw new WSException("User with hash $hash does not exist", 401);
        }

        try {
            $parsed = ObjectStore::parseNodePrefix($prefix);
        } catch (\Exception $e) {
            throw new WSException("Invalid user path for hash $hash", 401);
        }
        if ($parsed['kind'] !== 'teacher') {
            throw new WSException("Invalid user path for hash $hash", 401);
        }

        $schoolId = $parsed['school_id'];
        $userId = $parsed['teacher_id'];
        $attrKey = ObjectStore::teacherAttrKey($schoolId, $userId);

        if (!$store->exists($attrKey)) {
            throw new WSException("User with hash $hash does not exist", 401);
        }

        $loaded = $store->getJson($attrKey);
        $user = self::from_array($loaded['data']);
        $user->id = $userId;
        $user->school_id = $schoolId;
        return $user;
    }

    /**
     * Find a user by email by scanning all schools (acceptable until SQLite).
     */
    public static function from_email(string $email): User
    {
        $email = strtolower(trim($email));
        foreach (School::all() as $school) {
            foreach ($school->users() as $user) {
                if (strtolower($user->email) === $email) {
                    return $user;
                }
            }
        }
        throw new Exception("User with email $email does not exist");
    }

    /**
     * Persist teacher attributes.json and register the _id pointer.
     */
    public function save(): void
    {
        if ($this->id === null || $this->id === '') {
            $this->id = HashId::create();
        }
        if ($this->created_at === '') {
            $this->created_at = gmdate('c');
        }

        $this->validate();

        $store = ObjectStore::getInstance();
        $store->putJson(
            ObjectStore::teacherAttrKey($this->school_id, $this->id),
            [
                'email' => $this->email,
                'name' => $this->name,
                'role' => $this->role,
                'password_hash' => $this->password_hash,
                'created_at' => $this->created_at,
            ]
        );
        $store->setIdPointer(
            $this->id,
            ObjectStore::teacherPrefix($this->school_id, $this->id)
        );
    }

    /**
     * Delete the user prefix and its _id pointer (cascades exams in S3).
     */
    public function delete(): void
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '') {
            throw new Exception('Cannot delete user without id and school_id');
        }

        foreach ($this->exams() as $exam) {
            $exam->delete();
        }

        $store = ObjectStore::getInstance();
        $store->deletePrefix(ObjectStore::teacherPrefix($this->school_id, $this->id));
        $store->deleteIdPointer($this->id);
    }

    /**
     * List exams belonging to this user.
     *
     * @return Exam[]
     */
    public function exams(): array
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '') {
            return [];
        }

        $store = ObjectStore::getInstance();
        $exams = [];
        $prefix = ObjectStore::examsPrefix($this->school_id, $this->id);

        foreach ($store->listChildPrefixes($prefix) as $examId) {
            $attrKey = ObjectStore::examAttrKey($this->school_id, $this->id, $examId);
            if (!$store->exists($attrKey)) {
                continue;
            }
            try {
                $exams[] = Exam::from_hash($examId);
            } catch (\Exception $e) {
                continue;
            }
        }

        return $exams;
    }

    public function to_output(): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'email' => $this->email,
            'name' => $this->name,
            'role' => $this->role,
            'created_at' => $this->created_at,
        ];
    }
}
